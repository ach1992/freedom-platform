<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramSourceMessageSender;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class TelegramBroadcastDeliveryRunner
{
    private const CLAIM_LEASE_MINUTES = 5;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $administrators,
        private TelegramBroadcastTextDeliveryGateway $textDelivery,
        private TelegramSourceMessageSender $sourceMessages,
        private TelegramBroadcastCampaignService $campaigns,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    /** @requirement COM-002 DAT-002 DAT-003 DAT-004 ACL-002 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function processBatch(int $limit = 25): int
    {
        $this->assertLimit($limit);
        $this->recoverExpiredClaims(min(100, $limit * 2));
        $this->reconcileLinkedDeliveries(min(100, $limit * 2));

        $processed = 0;
        while ($processed < $limit) {
            $claim = $this->claimNext();
            if ($claim === null) {
                break;
            }

            try {
                $this->processClaim($claim);
            } catch (Throwable $exception) {
                $this->recoverClaimFailure($claim, $exception);
            }
            $processed++;
        }

        $this->reconcileLinkedDeliveries(min(100, $limit * 2));

        return $processed;
    }

    /** @requirement COM-002 DAT-003 DAT-004 OPS-003 QUA-004 */
    public function reconcile(int $limit = 100): int
    {
        $this->assertLimit($limit);
        $recovered = $this->recoverExpiredClaims($limit);
        $reconciled = $this->reconcileLinkedDeliveries($limit);

        return $recovered + $reconciled;
    }

    private function claimNext(): ?TelegramBroadcastRecipientClaim
    {
        return $this->database->connection()->transaction(function (Connection $connection): ?TelegramBroadcastRecipientClaim {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('bot_id', $this->runtime->botId())
                ->where('state', TelegramBroadcastCampaignState::Active->value)
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('broadcast_recipients as candidate')
                        ->whereColumn('candidate.broadcast_campaign_id', 'broadcast_campaigns.id')
                        ->where('candidate.delivery_state', 'queued');
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first([
                    'id',
                    'public_id',
                    'actor_administrator_id',
                    'current_message_version',
                ]);
            if ($campaign === null) {
                return null;
            }

            /** @var object{id:int|string,delivery_state:string,claim_token_hash:?string}|null $recipient */
            $recipient = $connection->table('broadcast_recipients')
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->where('delivery_state', 'queued')
                ->orderBy('id')
                ->lockForUpdate()
                ->first([
                    'id',
                    'public_id',
                    'attempt_count',
                ]);
            if ($recipient === null) {
                return null;
            }

            $message = $connection->table('broadcast_recipient_messages')
                ->where('broadcast_recipient_id', (int) $recipient->id)
                ->where('state', 'prepared')
                ->whereIn('action', ['send', 'retry'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'public_id']);
            if ($message === null) {
                $messageVersionId = $connection->table('broadcast_message_versions')
                    ->where('broadcast_campaign_id', (int) $campaign->id)
                    ->where('version', (int) $campaign->current_message_version)
                    ->value('id');
                if (! is_int($messageVersionId) && ! is_string($messageVersionId)) {
                    throw new RuntimeException('Broadcast current message version is unavailable while claiming a recipient.');
                }

                $messagePublicId = (string) Str::ulid();
                $connection->table('broadcast_recipient_messages')->insert([
                    'public_id' => $messagePublicId,
                    'broadcast_recipient_id' => (int) $recipient->id,
                    'broadcast_message_version_id' => (int) $messageVersionId,
                    'action' => 'send',
                    'request_key_hash' => hash('sha256', 'telegram-broadcast-recipient-send-v1|'.$recipient->public_id),
                    'state' => 'prepared',
                    'delivery_operation_public_id' => null,
                    'telegram_message_id' => null,
                    'result_code' => null,
                    'provider_boundary_started_at' => null,
                    'provider_boundary_finished_at' => null,
                    'requested_by_administrator_id' => (int) $campaign->actor_administrator_id,
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
            } else {
                $messagePublicId = (string) $message->public_id;
            }

            $claimToken = (string) Str::ulid();
            $claimTokenHash = hash('sha256', $claimToken);
            $updated = $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->where('delivery_state', 'queued')
                ->update([
                    'delivery_state' => 'sending',
                    'attempt_count' => (int) $recipient->attempt_count + 1,
                    'claim_token_hash' => $claimTokenHash,
                    'claim_expires_at' => $connection->raw(
                        'DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL '.self::CLAIM_LEASE_MINUTES.' MINUTE)',
                    ),
                    'failure_code' => null,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast recipient claim was lost.');
            }

            return new TelegramBroadcastRecipientClaim(
                (string) $campaign->public_id,
                (string) $recipient->public_id,
                $messagePublicId,
                $claimToken,
            );
        }, 3);
    }

    private function processClaim(TelegramBroadcastRecipientClaim $claim): void
    {
        $context = $this->claimContext($claim);
        if ($context['campaign_state'] !== TelegramBroadcastCampaignState::Active->value) {
            $this->releaseClaimWithoutEffect($claim, 'broadcast_campaign_inactive');

            return;
        }

        try {
            $this->administrators->authorize(
                $context['creator_administrator_id'],
                TelegramBroadcastCampaignService::PERMISSION,
            );
        } catch (AuthorizationException) {
            $this->pauseForSafety($claim, 'broadcast_creator_authorization_lost');

            return;
        }

        $mode = TelegramBroadcastMessageMode::tryFrom($context['message_mode'])
            ?? throw new RuntimeException('Broadcast claimed message mode is invalid.');

        if ($mode !== TelegramBroadcastMessageMode::NewText) {
            if (! $this->sourceStillBoundToCreator($context)) {
                $this->pauseForSafety($claim, 'broadcast_source_binding_lost');

                return;
            }

            $this->dispatchSourceMessage($claim, $context, $mode);

            return;
        }

        $this->dispatchText($claim, $context);
    }

    /** @param  array<string,mixed>  $context */
    private function dispatchText(TelegramBroadcastRecipientClaim $claim, array $context): void
    {
        $this->assertClaimStillActive($claim);

        $text = $context['message_text'];
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Broadcast text is unavailable for delivery.');
        }
        $keyboard = $this->keyboard($context['inline_keyboard_snapshot']);
        $receipt = $this->textDelivery->queueSend(
            $context['telegram_user_id'],
            $text,
            'tg-broadcast-recipient-message:'.$claim->recipientMessagePublicId,
            $this->deliveryCorrelationId($claim),
            $keyboard,
        );

        $this->database->connection()->transaction(function (Connection $connection) use (
            $claim,
            $receipt,
        ): void {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id']);
            if ($campaign === null) {
                throw new RuntimeException('Broadcast campaign disappeared while linking delivery.');
            }

            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'delivery_state',
                    'claim_token_hash',
                    'delivery_operation_public_id',
                ]);
            /** @var object{id:int|string,broadcast_recipient_id:int|string,state:string,provider_boundary_started_at:?string}|null $message */
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'broadcast_recipient_id',
                    'state',
                    'delivery_operation_public_id',
                ]);
            $this->assertClaimRows($claim, $recipient, $message);

            if ($message->delivery_operation_public_id !== null) {
                if (! hash_equals((string) $message->delivery_operation_public_id, $receipt->publicId)) {
                    throw new RuntimeException('Broadcast recipient message is linked to a different delivery operation.');
                }

                return;
            }
            if ((string) $message->state !== 'prepared') {
                throw new RuntimeException('Broadcast text recipient message is not prepared for delivery linking.');
            }

            $now = $this->timestamp();
            $messageUpdated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->where('state', 'prepared')
                ->whereNull('delivery_operation_public_id')
                ->update([
                    'state' => 'queued',
                    'delivery_operation_public_id' => $receipt->publicId,
                    'result_code' => $receipt->resultCode,
                    'updated_at' => $now,
                ]);
            $recipientUpdated = $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->where('delivery_state', 'sending')
                ->where('claim_token_hash', $claim->claimTokenHash())
                ->update([
                    'delivery_operation_public_id' => $receipt->publicId,
                    'claim_token_hash' => null,
                    'claim_expires_at' => null,
                    'updated_at' => $now,
                ]);
            if ($messageUpdated !== 1 || $recipientUpdated !== 1) {
                throw new RuntimeException('Broadcast text delivery link transition failed.');
            }
        }, 3);

        $this->reconcileRecipientDelivery($claim->recipientPublicId);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function dispatchSourceMessage(
        TelegramBroadcastRecipientClaim $claim,
        array $context,
        TelegramBroadcastMessageMode $mode,
    ): void {
        $this->assertClaimStillActive($claim);
        $this->administrators->authorize(
            $context['creator_administrator_id'],
            TelegramBroadcastCampaignService::PERMISSION,
        );
        if (! $this->sourceStillBoundToCreator($context)) {
            $this->pauseForSafety($claim, 'broadcast_source_binding_lost');

            return;
        }

        $entered = $this->enterSourceProviderBoundary($claim, $context);
        if (! $entered) {
            return;
        }

        $sourceChatId = $context['source_chat_id'];
        $sourceMessageId = $context['source_message_id'];
        if (! is_int($sourceChatId) || $sourceChatId < 1 || ! is_int($sourceMessageId) || $sourceMessageId < 1) {
            $this->finalizeSourceInterruption($claim, 'broadcast_source_identity_invalid');

            return;
        }

        $sourceMode = $mode === TelegramBroadcastMessageMode::Copy
            ? TelegramSourceMessageMode::Copy
            : TelegramSourceMessageMode::Forward;
        $keyboard = $this->keyboard($context['inline_keyboard_snapshot']);
        $resolvedKeyboard = $keyboard === null
            ? null
            : TelegramResolvedInlineKeyboardMarkup::resolve($keyboard, []);

        try {
            $result = $this->sourceMessages->send(
                $context['telegram_user_id'],
                new TelegramResolvedSourceMessagePresentation(
                    $sourceMode,
                    $sourceChatId,
                    $sourceMessageId,
                    $context['caption_override'],
                ),
                $resolvedKeyboard,
            );
        } catch (Throwable) {
            $result = new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'broadcast_source_transport_exception',
            );
        }

        $this->finalizeSourceResult($claim, $result);
    }

    /** @param array<string,mixed> $context */
    private function enterSourceProviderBoundary(
        TelegramBroadcastRecipientClaim $claim,
        array $context,
    ): bool {
        return $this->database->connection()->transaction(function (Connection $connection) use ($claim, $context): bool {
            /** @var object{id:int|string,state:string,state_version:int|string}|null $campaign */
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id', 'state', 'state_version']);
            if ($campaign === null) {
                throw new RuntimeException('Broadcast campaign disappeared before source provider boundary.');
            }

            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first(['id', 'delivery_state', 'claim_token_hash']);
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'broadcast_recipient_id',
                    'state',
                    'provider_boundary_started_at',
                ]);
            $this->assertClaimRows($claim, $recipient, $message);
            /** @var object{id:int|string,delivery_state:string,claim_token_hash:?string} $recipient */
            /** @var object{id:int|string,broadcast_recipient_id:int|string,state:string,provider_boundary_started_at:?string} $message */

            if ((string) $campaign->state !== TelegramBroadcastCampaignState::Active->value) {
                $this->releaseLockedClaim(
                    $connection,
                    $recipient,
                    $message,
                    (string) $campaign->state === TelegramBroadcastCampaignState::Cancelled->value,
                    'broadcast_campaign_inactive',
                );

                return false;
            }
            if ((string) $message->state === 'sending') {
                $this->markLockedUncertain(
                    $connection,
                    $recipient,
                    $message,
                    'broadcast_source_interrupted_after_boundary',
                );

                return false;
            }
            if ((string) $message->state !== 'prepared') {
                throw new RuntimeException('Broadcast source recipient message is not prepared.');
            }

            try {
                $this->administrators->authorize(
                    $context['creator_administrator_id'],
                    TelegramBroadcastCampaignService::PERMISSION,
                );
            } catch (AuthorizationException) {
                $this->pauseLockedSourceClaim(
                    $connection,
                    $campaign,
                    $recipient,
                    $message,
                    $claim,
                    'broadcast_creator_authorization_lost',
                );

                return false;
            }

            if (! $this->sourceStillBoundToCreator($context)) {
                $this->pauseLockedSourceClaim(
                    $connection,
                    $campaign,
                    $recipient,
                    $message,
                    $claim,
                    'broadcast_source_binding_lost',
                );

                return false;
            }

            $now = $this->timestamp();
            $updated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->where('state', 'prepared')
                ->update([
                    'state' => 'sending',
                    'provider_boundary_started_at' => $now,
                    'provider_boundary_finished_at' => null,
                    'result_code' => null,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast source provider-boundary transition failed.');
            }

            return true;
        }, 3);
    }

    /**
     * @param  object{id:int|string,state:string,state_version:int|string}  $campaign
     * @param  object{id:int|string,delivery_state:string,claim_token_hash:?string}  $recipient
     * @param  object{id:int|string,broadcast_recipient_id:int|string,state:string,provider_boundary_started_at:?string}  $message
     */
    private function pauseLockedSourceClaim(
        Connection $connection,
        object $campaign,
        object $recipient,
        object $message,
        TelegramBroadcastRecipientClaim $claim,
        string $resultCode,
    ): void {
        if ((string) $campaign->state === TelegramBroadcastCampaignState::Active->value) {
            $version = $this->positiveInt(
                $campaign->state_version,
                'Broadcast campaign state version',
            );
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $campaign->id)
                ->where('state', TelegramBroadcastCampaignState::Active->value)
                ->where('state_version', $version)
                ->update([
                    'state' => TelegramBroadcastCampaignState::Paused->value,
                    'state_version' => $version + 1,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast safety pause transition was lost.');
            }
        }

        if (! is_string($recipient->claim_token_hash)
            || ! hash_equals($recipient->claim_token_hash, $claim->claimTokenHash())
        ) {
            throw new DomainException('Broadcast source recipient claim is stale during safety pause.');
        }

        $this->releaseLockedClaim(
            $connection,
            $recipient,
            $message,
            false,
            $resultCode,
        );
    }

    private function finalizeSourceResult(
        TelegramBroadcastRecipientClaim $claim,
        TelegramMutationResult $result,
    ): void {
        $campaignPublicId = $claim->campaignPublicId;

        $this->database->connection()->transaction(function (Connection $connection) use ($claim, $result): void {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id']);
            if ($campaign === null) {
                throw new RuntimeException('Broadcast campaign disappeared after source delivery.');
            }

            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first(['id', 'delivery_state', 'claim_token_hash']);
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'broadcast_recipient_id',
                    'state',
                ]);
            $this->assertClaimRows($claim, $recipient, $message);
            if ((string) $message->state !== 'sending') {
                if (in_array((string) $message->state, ['succeeded', 'retryable', 'failed', 'uncertain'], true)) {
                    return;
                }

                throw new RuntimeException('Broadcast source recipient message lost provider-boundary state.');
            }

            [$messageState, $recipientState, $messageId, $failureCode] = match ($result->outcome) {
                TelegramMutationOutcome::Success => [
                    'succeeded',
                    'sent',
                    $this->requiredMessageId($result->messageId),
                    null,
                ],
                TelegramMutationOutcome::DefinitiveNoEffectRetryable,
                TelegramMutationOutcome::RetryAfter => [
                    'retryable',
                    'failed_transient',
                    null,
                    $result->resultCode,
                ],
                TelegramMutationOutcome::DefinitiveFailure => [
                    'failed',
                    'failed_permanent',
                    null,
                    $result->resultCode,
                ],
                TelegramMutationOutcome::UncertainResult => [
                    'uncertain',
                    'uncertain',
                    null,
                    $result->resultCode,
                ],
            };

            $now = $this->timestamp();
            $retryNotBefore = $this->retryNotBefore($result);
            $messageUpdated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->where('state', 'sending')
                ->update([
                    'state' => $messageState,
                    'telegram_message_id' => $messageId,
                    'result_code' => $result->resultCode,
                    'retry_not_before' => $retryNotBefore,
                    'provider_boundary_finished_at' => $now,
                    'updated_at' => $now,
                ]);
            $recipientUpdated = $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->where('delivery_state', 'sending')
                ->where('claim_token_hash', $claim->claimTokenHash())
                ->update([
                    'delivery_state' => $recipientState,
                    'telegram_message_id' => $messageId,
                    'failure_code' => $failureCode,
                    'retry_not_before' => $retryNotBefore,
                    'sent_at' => $recipientState === 'sent' ? $now : null,
                    'claim_token_hash' => null,
                    'claim_expires_at' => null,
                    'updated_at' => $now,
                ]);
            if ($messageUpdated !== 1 || $recipientUpdated !== 1) {
                throw new RuntimeException('Broadcast source delivery result transition failed.');
            }
        }, 3);

        $this->campaigns->completeIfFinished($campaignPublicId);
    }

    private function finalizeSourceInterruption(
        TelegramBroadcastRecipientClaim $claim,
        string $resultCode,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($claim, $resultCode): void {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id']);
            if ($campaign === null) {
                throw new RuntimeException('Broadcast campaign disappeared during source interruption recovery.');
            }
            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first(['id', 'delivery_state', 'claim_token_hash']);
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first(['id', 'broadcast_recipient_id', 'state']);
            $this->assertClaimRows($claim, $recipient, $message);
            $this->markLockedUncertain($connection, $recipient, $message, $resultCode);
        }, 3);
    }

    private function recoverClaimFailure(TelegramBroadcastRecipientClaim $claim, Throwable $exception): void
    {
        $code = $exception instanceof AuthorizationException
            ? 'broadcast_creator_authorization_lost'
            : 'broadcast_recipient_processing_exception';

        try {
            $this->database->connection()->transaction(function (Connection $connection) use ($claim, $code): void {
                $campaign = $connection->table('broadcast_campaigns')
                    ->where('public_id', $claim->campaignPublicId)
                    ->where('bot_id', $this->runtime->botId())
                    ->lockForUpdate()
                    ->first(['id']);
                if ($campaign === null) {
                    return;
                }
                $recipient = $connection->table('broadcast_recipients')
                    ->where('public_id', $claim->recipientPublicId)
                    ->where('broadcast_campaign_id', (int) $campaign->id)
                    ->lockForUpdate()
                    ->first(['id', 'delivery_state', 'claim_token_hash', 'delivery_operation_public_id']);
                $message = $connection->table('broadcast_recipient_messages')
                    ->where('public_id', $claim->recipientMessagePublicId)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'broadcast_recipient_id',
                        'state',
                        'delivery_operation_public_id',
                    ]);
                if ($recipient === null || $message === null) {
                    return;
                }
                if (! is_string($recipient->claim_token_hash)
                    || ! hash_equals($recipient->claim_token_hash, $claim->claimTokenHash())
                ) {
                    return;
                }

                if ((string) $message->state === 'sending'
                    && $message->delivery_operation_public_id === null
                ) {
                    $this->markLockedUncertain($connection, $recipient, $message, $code);

                    return;
                }

                if ($recipient->delivery_operation_public_id !== null
                    || $message->delivery_operation_public_id !== null
                ) {
                    $connection->table('broadcast_recipients')
                        ->where('id', (int) $recipient->id)
                        ->update([
                            'claim_token_hash' => null,
                            'claim_expires_at' => null,
                            'updated_at' => $this->timestamp(),
                        ]);

                    return;
                }

                if ((string) $message->state === 'prepared') {
                    $this->releaseLockedClaim($connection, $recipient, $message, false, $code);

                    return;
                }

                $this->markLockedUncertain($connection, $recipient, $message, $code);
            }, 3);
        } catch (Throwable) {
            // Recovery is retried by the expired-claim path. Never create a second effect here.
        }
    }

    /** @requirement COM-002 DAT-003 DAT-004 OPS-003 QUA-004 */
    private function recoverExpiredClaims(int $limit): int
    {
        $rows = $this->database->connection()->table('broadcast_recipients as recipient')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->where('campaign.bot_id', $this->runtime->botId())
            ->where('recipient.delivery_state', 'sending')
            ->whereNotNull('recipient.claim_token_hash')
            ->whereNotNull('recipient.claim_expires_at')
            ->whereRaw('recipient.claim_expires_at <= CURRENT_TIMESTAMP(6)')
            ->orderBy('recipient.claim_expires_at')
            ->orderBy('recipient.id')
            ->limit($limit)
            ->get(['recipient.id', 'recipient.broadcast_campaign_id']);

        $recovered = 0;
        foreach ($rows as $candidate) {
            $changed = $this->database->connection()->transaction(function (Connection $connection) use ($candidate): int {
                $campaign = $connection->table('broadcast_campaigns')
                    ->where('id', (int) $candidate->broadcast_campaign_id)
                    ->lockForUpdate()
                    ->first(['id', 'state']);
                if ($campaign === null) {
                    return 0;
                }

                $recipient = $connection->table('broadcast_recipients')
                    ->where('id', (int) $candidate->id)
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'delivery_state',
                        'claim_token_hash',
                        'claim_expires_at',
                        'delivery_operation_public_id',
                    ]);
                if ($recipient === null
                    || (string) $recipient->delivery_state !== 'sending'
                    || $recipient->claim_token_hash === null
                    || $recipient->claim_expires_at === null
                    || (string) $recipient->claim_expires_at > $this->timestamp()
                ) {
                    return 0;
                }

                $message = $connection->table('broadcast_recipient_messages')
                    ->where('broadcast_recipient_id', (int) $recipient->id)
                    ->whereIn('action', ['send', 'retry'])
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first([
                        'id',
                        'state',
                        'delivery_operation_public_id',
                        'telegram_message_id',
                        'result_code',
                    ]);
                if ($message === null) {
                    $connection->table('broadcast_recipients')
                        ->where('id', (int) $recipient->id)
                        ->update([
                            'delivery_state' => 'queued',
                            'claim_token_hash' => null,
                            'claim_expires_at' => null,
                            'updated_at' => $this->timestamp(),
                        ]);

                    return 1;
                }

                if ($recipient->delivery_operation_public_id !== null
                    || $message->delivery_operation_public_id !== null
                ) {
                    $connection->table('broadcast_recipients')
                        ->where('id', (int) $recipient->id)
                        ->update([
                            'claim_token_hash' => null,
                            'claim_expires_at' => null,
                            'updated_at' => $this->timestamp(),
                        ]);

                    return 1;
                }

                return match ((string) $message->state) {
                    'prepared' => $this->releaseExpiredPrepared(
                        $connection,
                        $recipient,
                        (string) $campaign->state === TelegramBroadcastCampaignState::Cancelled->value,
                    ),
                    'sending' => $this->recoverExpiredSourceSending($connection, $recipient, $message),
                    'succeeded' => $this->recoverTerminalRecipient(
                        $connection,
                        $recipient,
                        'sent',
                        $message->telegram_message_id,
                        null,
                    ),
                    'retryable' => $this->recoverTerminalRecipient(
                        $connection,
                        $recipient,
                        'failed_transient',
                        null,
                        $message->result_code,
                    ),
                    'failed' => $this->recoverTerminalRecipient(
                        $connection,
                        $recipient,
                        'failed_permanent',
                        null,
                        $message->result_code,
                    ),
                    'uncertain' => $this->recoverTerminalRecipient(
                        $connection,
                        $recipient,
                        'uncertain',
                        null,
                        $message->result_code,
                    ),
                    'skipped' => $this->recoverTerminalRecipient(
                        $connection,
                        $recipient,
                        'skipped',
                        null,
                        $message->result_code,
                    ),
                    default => $this->recoverExpiredUnknown($connection, $recipient, $message),
                };
            }, 3);
            $recovered += $changed;
        }

        return $recovered;
    }

    /** @requirement COM-002 DAT-003 DAT-004 OPS-003 QUA-004 */
    private function reconcileLinkedDeliveries(int $limit): int
    {
        $recipients = $this->database->connection()->table('broadcast_recipients as recipient')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->where('campaign.bot_id', $this->runtime->botId())
            ->where('recipient.delivery_state', 'sending')
            ->whereNotNull('recipient.delivery_operation_public_id')
            ->orderBy('recipient.id')
            ->limit($limit)
            ->get(['recipient.public_id']);

        $reconciled = 0;
        foreach ($recipients as $recipient) {
            if ($this->reconcileRecipientDelivery((string) $recipient->public_id)) {
                $reconciled++;
            }
        }

        return $reconciled;
    }

    private function reconcileRecipientDelivery(string $recipientPublicId): bool
    {
        $campaignPublicId = null;
        $changed = $this->database->connection()->transaction(function (Connection $connection) use (
            $recipientPublicId,
            &$campaignPublicId,
        ): bool {
            /** @var object{
             *     id:int|string,
             *     broadcast_campaign_id:int|string,
             *     delivery_state:string,
             *     delivery_operation_public_id:string|null,
             *     campaign_public_id:string,
             *     campaign_state:string,
             *     campaign_state_version:int|string
             * }|null $recipient
             */
            $recipient = $connection->table('broadcast_recipients as recipient')
                ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
                ->where('recipient.public_id', $recipientPublicId)
                ->where('campaign.bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first([
                    'recipient.id',
                    'recipient.broadcast_campaign_id',
                    'recipient.delivery_state',
                    'recipient.delivery_operation_public_id',
                    'campaign.public_id as campaign_public_id',
                    'campaign.state as campaign_state',
                    'campaign.state_version as campaign_state_version',
                ]);
            if ($recipient === null || $recipient->delivery_operation_public_id === null) {
                return false;
            }
            $campaignPublicId = (string) $recipient->campaign_public_id;

            $operation = $connection->table('telegram_delivery_operations')
                ->where('public_id', (string) $recipient->delivery_operation_public_id)
                ->first([
                    'state',
                    'outbox_event_id',
                    'telegram_message_id',
                    'result_code',
                ]);
            if ($operation === null) {
                throw new RuntimeException('Broadcast linked Telegram delivery operation is missing.');
            }

            /** @var object{
             *     id:int|string,
             *     public_id:string,
             *     broadcast_message_version_id:int|string,
             *     requested_by_administrator_id:int|string|null,
             *     state:string
             * }|null $message
             */
            $message = $connection->table('broadcast_recipient_messages')
                ->where('broadcast_recipient_id', (int) $recipient->id)
                ->where('delivery_operation_public_id', (string) $recipient->delivery_operation_public_id)
                ->lockForUpdate()
                ->first([
                    'id',
                    'public_id',
                    'broadcast_message_version_id',
                    'requested_by_administrator_id',
                    'state',
                ]);
            if ($message === null) {
                throw new RuntimeException('Broadcast linked recipient-message evidence is missing.');
            }

            $operationState = TelegramDeliveryOperationState::tryFrom((string) $operation->state)
                ?? throw new RuntimeException('Broadcast linked Telegram delivery state is invalid.');

            if ($operationState === TelegramDeliveryOperationState::Prepared
                && $this->outboxRequiresReview($connection, (string) $operation->outbox_event_id)
            ) {
                return $this->reconcilePreEffectReview(
                    $connection,
                    $recipient,
                    $message,
                );
            }

            if (in_array($operationState, [
                TelegramDeliveryOperationState::Prepared,
                TelegramDeliveryOperationState::Sending,
                TelegramDeliveryOperationState::Retryable,
            ], true)) {
                $connection->table('broadcast_recipient_messages')
                    ->where('id', (int) $message->id)
                    ->update([
                        'state' => 'queued',
                        'result_code' => $operation->result_code,
                        'updated_at' => $this->timestamp(),
                    ]);

                return false;
            }

            [$messageState, $recipientState, $messageId, $failureCode] = match ($operationState) {
                TelegramDeliveryOperationState::Succeeded => [
                    'succeeded',
                    'sent',
                    $this->requiredMessageId($operation->telegram_message_id),
                    null,
                ],
                TelegramDeliveryOperationState::ReviewRequired => [
                    'retryable',
                    'failed_transient',
                    null,
                    $this->resultCode($operation->result_code, 'telegram_delivery_review_required'),
                ],
                TelegramDeliveryOperationState::FailedFinal => [
                    'failed',
                    'failed_permanent',
                    null,
                    $this->resultCode($operation->result_code, 'telegram_delivery_failed_final'),
                ],
                TelegramDeliveryOperationState::Uncertain => [
                    'uncertain',
                    'uncertain',
                    null,
                    $this->resultCode($operation->result_code, 'telegram_delivery_uncertain'),
                ],
            };

            $now = $this->timestamp();
            $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->update([
                    'state' => $messageState,
                    'telegram_message_id' => $messageId,
                    'result_code' => $operation->result_code,
                    'updated_at' => $now,
                ]);
            $recipientValues = [
                'delivery_state' => $recipientState,
                'telegram_message_id' => $messageId,
                'failure_code' => $failureCode,
                'retry_not_before' => null,
                'sent_at' => $recipientState === 'sent' ? $now : null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $now,
            ];
            $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->update($recipientValues);

            return true;
        }, 3);

        if ($changed && is_string($campaignPublicId)) {
            $this->campaigns->completeIfFinished($campaignPublicId);
        }

        return $changed;
    }

    private function outboxRequiresReview(Connection $connection, string $outboxEventId): bool
    {
        return $connection->table('outbox_messages')
            ->where('id', $outboxEventId)
            ->whereNull('processed_at')
            ->where('dispatch_state', 'review_required')
            ->exists();
    }

    /**
     * @param object{
     *     id:int|string,
     *     broadcast_campaign_id:int|string,
     *     delivery_state:string,
     *     delivery_operation_public_id:string,
     *     campaign_public_id:string,
     *     campaign_state:string,
     *     campaign_state_version:int|string
     * } $recipient
     * @param object{
     *     id:int|string,
     *     public_id:string,
     *     broadcast_message_version_id:int|string,
     *     requested_by_administrator_id:int|string|null,
     *     state:string
     * } $message
     */
    private function reconcilePreEffectReview(
        Connection $connection,
        object $recipient,
        object $message,
    ): bool {
        if ((string) $recipient->delivery_state !== 'sending'
            || ! in_array((string) $message->state, ['prepared', 'queued'], true)
        ) {
            return false;
        }

        $campaignState = TelegramBroadcastCampaignState::tryFrom((string) $recipient->campaign_state)
            ?? throw new RuntimeException('Broadcast campaign state is invalid during pre-effect reconciliation.');
        $administratorId = $this->positiveNullableInt(
            $message->requested_by_administrator_id,
            'Broadcast delivery administrator ID',
        );
        $authorizationLost = false;
        if ($campaignState === TelegramBroadcastCampaignState::Active) {
            if ($administratorId === null) {
                $authorizationLost = true;
            } else {
                try {
                    $this->administrators->authorize(
                        $administratorId,
                        TelegramBroadcastCampaignService::PERMISSION,
                    );
                } catch (AuthorizationException) {
                    $authorizationLost = true;
                }
            }
        }

        if ($authorizationLost) {
            $version = $this->positiveInt(
                $recipient->campaign_state_version,
                'Broadcast campaign state version',
            );
            $updated = $connection->table('broadcast_campaigns')
                ->where('id', (int) $recipient->broadcast_campaign_id)
                ->where('state', TelegramBroadcastCampaignState::Active->value)
                ->where('state_version', $version)
                ->update([
                    'state' => TelegramBroadcastCampaignState::Paused->value,
                    'state_version' => $version + 1,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast authorization-loss pause transition was lost.');
            }
            $campaignState = TelegramBroadcastCampaignState::Paused;
        }

        if ($campaignState === TelegramBroadcastCampaignState::Paused) {
            $now = $this->timestamp();
            $replacementPublicId = (string) Str::ulid();
            $connection->table('broadcast_recipient_messages')->insert([
                'public_id' => $replacementPublicId,
                'broadcast_recipient_id' => (int) $recipient->id,
                'broadcast_message_version_id' => (int) $message->broadcast_message_version_id,
                'action' => 'retry',
                'operation_group_public_id' => null,
                'request_key_hash' => hash(
                    'sha256',
                    'telegram-broadcast-pre-effect-resume-v1|'.$message->public_id,
                ),
                'state' => 'prepared',
                'delivery_operation_public_id' => null,
                'telegram_message_id' => null,
                'result_code' => null,
                'retry_not_before' => null,
                'provider_boundary_started_at' => null,
                'provider_boundary_finished_at' => null,
                'requested_by_administrator_id' => $administratorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->update([
                    'state' => 'failed',
                    'result_code' => $authorizationLost
                        ? TelegramBroadcastDeliveryEffectGuard::AUTHORIZATION_LOST
                        : TelegramBroadcastDeliveryEffectGuard::PAUSED_BEFORE_EFFECT,
                    'retry_not_before' => null,
                    'updated_at' => $now,
                ]);
            $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->update([
                    'delivery_state' => 'queued',
                    'delivery_operation_public_id' => null,
                    'failure_code' => null,
                    'retry_not_before' => null,
                    'claim_token_hash' => null,
                    'claim_expires_at' => null,
                    'updated_at' => $now,
                ]);

            return true;
        }

        if ($campaignState === TelegramBroadcastCampaignState::Cancelled) {
            $now = $this->timestamp();
            $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->update([
                    'state' => 'skipped',
                    'result_code' => TelegramBroadcastDeliveryEffectGuard::CANCELLED_BEFORE_EFFECT,
                    'retry_not_before' => null,
                    'updated_at' => $now,
                ]);
            $connection->table('broadcast_recipients')
                ->where('id', (int) $recipient->id)
                ->update([
                    'delivery_state' => 'skipped',
                    'failure_code' => TelegramBroadcastDeliveryEffectGuard::CANCELLED_BEFORE_EFFECT,
                    'retry_not_before' => null,
                    'claim_token_hash' => null,
                    'claim_expires_at' => null,
                    'updated_at' => $now,
                ]);

            return true;
        }

        $now = $this->timestamp();
        $connection->table('broadcast_recipient_messages')
            ->where('id', (int) $message->id)
            ->update([
                'state' => 'failed',
                'result_code' => 'telegram_broadcast_pre_effect_review_required',
                'retry_not_before' => null,
                'updated_at' => $now,
            ]);
        $connection->table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update([
                'delivery_state' => 'failed_permanent',
                'failure_code' => 'telegram_broadcast_pre_effect_review_required',
                'retry_not_before' => null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $now,
            ]);

        return true;
    }

    private function pauseForSafety(TelegramBroadcastRecipientClaim $claim, string $resultCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($claim, $resultCode): void {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id', 'state', 'state_version']);
            if ($campaign === null) {
                return;
            }
            if ((string) $campaign->state === TelegramBroadcastCampaignState::Active->value) {
                $connection->table('broadcast_campaigns')
                    ->where('id', (int) $campaign->id)
                    ->where('state', TelegramBroadcastCampaignState::Active->value)
                    ->where('state_version', (int) $campaign->state_version)
                    ->update([
                        'state' => TelegramBroadcastCampaignState::Paused->value,
                        'state_version' => (int) $campaign->state_version + 1,
                        'updated_at' => $this->timestamp(),
                    ]);
            }

            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first(['id', 'delivery_state', 'claim_token_hash']);
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first(['id', 'broadcast_recipient_id', 'state']);
            if ($recipient === null || $message === null) {
                return;
            }
            if (! is_string($recipient->claim_token_hash)
                || ! hash_equals($recipient->claim_token_hash, $claim->claimTokenHash())
            ) {
                return;
            }
            if ((string) $message->state === 'prepared') {
                $this->releaseLockedClaim($connection, $recipient, $message, false, $resultCode);
            }
        }, 3);
    }

    private function releaseClaimWithoutEffect(
        TelegramBroadcastRecipientClaim $claim,
        string $resultCode,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($claim, $resultCode): void {
            $campaign = $connection->table('broadcast_campaigns')
                ->where('public_id', $claim->campaignPublicId)
                ->where('bot_id', $this->runtime->botId())
                ->lockForUpdate()
                ->first(['id', 'state']);
            if ($campaign === null) {
                return;
            }
            $recipient = $connection->table('broadcast_recipients')
                ->where('public_id', $claim->recipientPublicId)
                ->where('broadcast_campaign_id', (int) $campaign->id)
                ->lockForUpdate()
                ->first(['id', 'delivery_state', 'claim_token_hash']);
            $message = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $claim->recipientMessagePublicId)
                ->lockForUpdate()
                ->first(['id', 'broadcast_recipient_id', 'state']);
            if ($recipient === null || $message === null) {
                return;
            }
            if (! is_string($recipient->claim_token_hash)
                || ! hash_equals($recipient->claim_token_hash, $claim->claimTokenHash())
                || (string) $message->state !== 'prepared'
            ) {
                return;
            }

            $this->releaseLockedClaim(
                $connection,
                $recipient,
                $message,
                (string) $campaign->state === TelegramBroadcastCampaignState::Cancelled->value,
                $resultCode,
            );
        }, 3);
    }

    private function assertClaimStillActive(TelegramBroadcastRecipientClaim $claim): void
    {
        $row = $this->database->connection()->table('broadcast_recipients as recipient')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->where('recipient.public_id', $claim->recipientPublicId)
            ->where('campaign.public_id', $claim->campaignPublicId)
            ->where('campaign.bot_id', $this->runtime->botId())
            ->first([
                'campaign.state',
                'recipient.delivery_state',
                'recipient.claim_token_hash',
            ]);
        if ($row === null
            || (string) $row->state !== TelegramBroadcastCampaignState::Active->value
            || (string) $row->delivery_state !== 'sending'
            || ! is_string($row->claim_token_hash)
            || ! hash_equals($row->claim_token_hash, $claim->claimTokenHash())
        ) {
            $this->releaseClaimWithoutEffect($claim, 'broadcast_campaign_inactive');
            throw new DomainException('Broadcast recipient claim is no longer active.');
        }
    }

    /** @return array<string,mixed> */
    private function claimContext(TelegramBroadcastRecipientClaim $claim): array
    {
        $row = $this->database->connection()->table('broadcast_recipients as recipient')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->join('administrators as creator', 'creator.id', '=', 'campaign.actor_administrator_id')
            ->join('broadcast_recipient_messages as recipient_message', function ($join) use ($claim): void {
                $join->on('recipient_message.broadcast_recipient_id', '=', 'recipient.id')
                    ->where('recipient_message.public_id', '=', $claim->recipientMessagePublicId);
            })
            ->join('broadcast_message_versions as message', 'message.id', '=', 'recipient_message.broadcast_message_version_id')
            ->where('recipient.public_id', $claim->recipientPublicId)
            ->where('campaign.public_id', $claim->campaignPublicId)
            ->where('campaign.bot_id', $this->runtime->botId())
            ->first([
                'campaign.state as campaign_state',
                'campaign.actor_administrator_id as creator_administrator_id',
                'campaign.bot_id',
                'campaign.correlation_id',
                'creator.user_id as creator_user_id',
                'recipient.telegram_user_id',
                'recipient.delivery_state',
                'recipient.claim_token_hash',
                'recipient_message.state as recipient_message_state',
                'message.mode as message_mode',
                'message.source_kind',
                'message.text as message_text',
                'message.caption_override',
                'message.source_chat_id',
                'message.source_message_id',
                'message.inline_keyboard_snapshot',
            ]);
        if ($row === null
            || (string) $row->delivery_state !== 'sending'
            || (string) $row->recipient_message_state !== 'prepared'
            || ! is_string($row->claim_token_hash)
            || ! hash_equals($row->claim_token_hash, $claim->claimTokenHash())
        ) {
            throw new DomainException('Broadcast recipient claim is stale.');
        }

        return [
            'campaign_state' => (string) $row->campaign_state,
            'creator_administrator_id' => $this->positiveInt($row->creator_administrator_id, 'Broadcast creator administrator ID'),
            'creator_user_id' => $this->positiveInt($row->creator_user_id, 'Broadcast creator user ID'),
            'bot_id' => (string) $row->bot_id,
            'correlation_id' => (string) $row->correlation_id,
            'telegram_user_id' => $this->positiveInt($row->telegram_user_id, 'Broadcast recipient Telegram ID'),
            'message_mode' => (string) $row->message_mode,
            'source_kind' => $row->source_kind === null ? null : (string) $row->source_kind,
            'message_text' => $row->message_text === null ? null : (string) $row->message_text,
            'caption_override' => $row->caption_override === null ? null : (string) $row->caption_override,
            'source_chat_id' => $row->source_chat_id === null ? null : $this->positiveInt($row->source_chat_id, 'Broadcast source chat ID'),
            'source_message_id' => $row->source_message_id === null ? null : $this->positiveInt($row->source_message_id, 'Broadcast source message ID'),
            'inline_keyboard_snapshot' => $row->inline_keyboard_snapshot === null ? null : (string) $row->inline_keyboard_snapshot,
        ];
    }

    /** @param  array<string,mixed>  $context */
    private function sourceStillBoundToCreator(array $context): bool
    {
        $sourceChatId = $context['source_chat_id'] ?? null;
        if (! is_int($sourceChatId) || $sourceChatId < 1) {
            return false;
        }

        $telegramUserId = $this->database->connection()->table('telegram_accounts')
            ->where('user_id', $context['creator_user_id'])
            ->where('bot_id', $context['bot_id'])
            ->where('is_bot', false)
            ->value('telegram_user_id');

        return (is_int($telegramUserId) || is_string($telegramUserId))
            && (int) $telegramUserId === $sourceChatId;
    }

    private function keyboard(mixed $snapshot): ?TelegramInlineKeyboardSnapshot
    {
        if ($snapshot === null) {
            return null;
        }
        if (! is_string($snapshot)) {
            throw new RuntimeException('Broadcast inline keyboard snapshot is invalid.');
        }

        $keyboard = TelegramInlineKeyboardSnapshot::restore($snapshot);
        if ($keyboard->callbackPublicIds() !== []) {
            throw new DomainException('Broadcast delivery cannot reuse recipient-bound callback buttons.');
        }

        return $keyboard;
    }

    private function releaseLockedClaim(
        Connection $connection,
        object $recipient,
        object $message,
        bool $cancelled,
        string $resultCode,
    ): void {
        /** @var object{id:int|string} $recipient */
        /** @var object{id:int|string,state:string} $message */
        $now = $this->timestamp();
        if ($cancelled) {
            $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $message->id)
                ->where('state', 'prepared')
                ->update([
                    'state' => 'skipped',
                    'result_code' => $resultCode,
                    'updated_at' => $now,
                ]);
        }

        $updated = $connection->table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update([
                'delivery_state' => $cancelled ? 'skipped' : 'queued',
                'failure_code' => $cancelled ? $resultCode : null,
                'retry_not_before' => null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Broadcast recipient claim release failed.');
        }
    }

    private function markLockedUncertain(
        Connection $connection,
        object $recipient,
        object $message,
        string $resultCode,
    ): void {
        /** @var object{id:int|string} $recipient */
        /** @var object{id:int|string,state:string} $message */
        $now = $this->timestamp();
        $connection->table('broadcast_recipient_messages')
            ->where('id', (int) $message->id)
            ->update([
                'state' => 'uncertain',
                'result_code' => $resultCode,
                'provider_boundary_finished_at' => $now,
                'updated_at' => $now,
            ]);
        $connection->table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update([
                'delivery_state' => 'uncertain',
                'failure_code' => $resultCode,
                'retry_not_before' => null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $now,
            ]);
    }

    private function releaseExpiredPrepared(
        Connection $connection,
        object $recipient,
        bool $cancelled,
    ): int {
        /** @var object{id:int|string} $recipient */
        $updated = $connection->table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update([
                'delivery_state' => $cancelled ? 'skipped' : 'queued',
                'failure_code' => $cancelled ? 'broadcast_cancelled_before_effect' : null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $this->timestamp(),
            ]);

        return $updated === 1 ? 1 : 0;
    }

    private function recoverExpiredSourceSending(
        Connection $connection,
        object $recipient,
        object $message,
    ): int {
        $this->markLockedUncertain(
            $connection,
            $recipient,
            $message,
            'broadcast_source_interrupted_after_boundary',
        );

        return 1;
    }

    private function recoverTerminalRecipient(
        Connection $connection,
        object $recipient,
        string $state,
        mixed $messageId,
        mixed $resultCode,
    ): int {
        /** @var object{id:int|string} $recipient */
        $messageId = $state === 'sent'
            ? $this->requiredMessageId($messageId)
            : null;
        $failureCode = in_array($state, ['failed_transient', 'failed_permanent', 'uncertain'], true)
            ? $this->resultCode($resultCode, 'broadcast_delivery_recovered_failure')
            : null;
        $now = $this->timestamp();
        $updated = $connection->table('broadcast_recipients')
            ->where('id', (int) $recipient->id)
            ->update([
                'delivery_state' => $state,
                'telegram_message_id' => $messageId,
                'failure_code' => $failureCode,
                'retry_not_before' => null,
                'sent_at' => $state === 'sent' ? $now : null,
                'claim_token_hash' => null,
                'claim_expires_at' => null,
                'updated_at' => $now,
            ]);

        return $updated === 1 ? 1 : 0;
    }

    private function recoverExpiredUnknown(
        Connection $connection,
        object $recipient,
        object $message,
    ): int {
        $this->markLockedUncertain(
            $connection,
            $recipient,
            $message,
            'broadcast_recipient_recovery_state_unknown',
        );

        return 1;
    }

    private function assertClaimRows(
        TelegramBroadcastRecipientClaim $claim,
        ?object $recipient,
        ?object $message,
    ): void {
        if ($recipient === null || $message === null) {
            throw new DomainException('Broadcast recipient claim is stale.');
        }

        /** @var object{id:int|string,delivery_state:string,claim_token_hash:?string} $recipient */
        /** @var object{id:int|string,broadcast_recipient_id:int|string,state:string} $message */
        if ((int) $message->broadcast_recipient_id !== (int) $recipient->id
            || (string) $recipient->delivery_state !== 'sending'
            || ! is_string($recipient->claim_token_hash)
            || ! hash_equals($recipient->claim_token_hash, $claim->claimTokenHash())
        ) {
            throw new DomainException('Broadcast recipient claim is stale.');
        }
    }

    private function deliveryCorrelationId(TelegramBroadcastRecipientClaim $claim): string
    {
        return 'tgb:'.$claim->recipientMessagePublicId;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function requiredMessageId(mixed $value): int
    {
        return $this->positiveInt($value, 'Broadcast Telegram message ID');
    }

    private function positiveNullableInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInt($value, $label);
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }

    private function retryNotBefore(TelegramMutationResult $result): ?string
    {
        if ($result->outcome !== TelegramMutationOutcome::RetryAfter) {
            return null;
        }

        $seconds = $result->retryAfterSeconds;
        if ($seconds === null) {
            throw new RuntimeException('Broadcast provider retry delay is unavailable.');
        }

        return $this->clock->now()
            ->modify('+'.$seconds.' seconds')
            ->format('Y-m-d H:i:s.u');
    }

    private function resultCode(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $value) === 1) {
            return $value;
        }

        return $fallback;
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Broadcast delivery limit must be between 1 and 100.');
        }
    }
}
