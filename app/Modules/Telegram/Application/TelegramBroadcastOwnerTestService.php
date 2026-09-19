<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramSourceMessageSender;
use App\Modules\Telegram\Domain\TelegramBroadcastCampaignState;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

final readonly class TelegramBroadcastOwnerTestService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private TelegramDeliveryRuntime $runtime,
        private TelegramBroadcastTextDeliveryGateway $textDelivery,
        private TelegramSourceMessageSender $sourceMessages,
        private Clock $clock,
    ) {}

    /** @requirement COM-002 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function send(
        int $actorUserId,
        string $campaignPublicId,
        int $expectedStateVersion,
        #[SensitiveParameter] string $requestKey,
    ): TelegramBroadcastOwnerTestReceipt {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertOwner($administratorId, $actorUserId);
        $this->assertPublicId($campaignPublicId);
        if ($expectedStateVersion < 1) {
            throw new DomainException('Broadcast campaign state version is invalid.');
        }

        $requestHash = $this->requestHash($requestKey);
        $connection = $this->database->connection();
        $context = $this->testContext(
            $connection,
            $actorUserId,
            $administratorId,
            $campaignPublicId,
            $expectedStateVersion,
        );

        try {
            $testPublicId = $connection->transaction(function (Connection $connection) use (
                $context,
                $requestHash,
            ): string {
                $existing = $connection->table('broadcast_campaign_tests')
                    ->where('request_key_hash', $requestHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertReplayMatches($existing, $context);

                    return (string) $existing->public_id;
                }

                $publicId = (string) Str::ulid();
                $now = $this->timestamp();
                $connection->table('broadcast_campaign_tests')->insert([
                    'public_id' => $publicId,
                    'broadcast_campaign_id' => $context['campaign_id'],
                    'broadcast_message_version_id' => $context['message_version_id'],
                    'owner_administrator_id' => $context['administrator_id'],
                    'telegram_account_id' => $context['telegram_account_id'],
                    'request_key_hash' => $requestHash,
                    'state' => 'prepared',
                    'delivery_operation_public_id' => null,
                    'telegram_message_id' => null,
                    'result_code' => null,
                    'provider_boundary_started_at' => null,
                    'provider_boundary_finished_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $publicId;
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existing = $connection->table('broadcast_campaign_tests')
                ->where('request_key_hash', $requestHash)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertReplayMatches($existing, $context);
            $testPublicId = (string) $existing->public_id;
        }

        $test = $connection->table('broadcast_campaign_tests')
            ->where('public_id', $testPublicId)
            ->first();
        if ($test === null) {
            throw new RuntimeException('Broadcast Owner test disappeared after creation.');
        }
        if ((string) $test->state !== 'prepared') {
            return $this->reconcile($actorUserId, $campaignPublicId, $testPublicId, true);
        }

        $mode = TelegramBroadcastMessageMode::tryFrom($context['mode'])
            ?? throw new RuntimeException('Broadcast Owner test message mode is invalid.');

        if ($mode === TelegramBroadcastMessageMode::NewText) {
            $this->assertCampaignCreatorAuthorized($context);
            $text = $context['text'];
            if (! is_string($text) || $text === '') {
                throw new RuntimeException('Broadcast Owner test text is unavailable.');
            }
            $keyboard = $this->keyboard($context['inline_keyboard_snapshot']);
            $receipt = $this->textDelivery->queueSend(
                $context['telegram_user_id'],
                $text,
                'tg-broadcast-owner-test:'.$testPublicId,
                $context['correlation_id'].':test',
                $keyboard,
            );

            $connection->transaction(function (Connection $connection) use ($testPublicId, $receipt): void {
                $row = $connection->table('broadcast_campaign_tests')
                    ->where('public_id', $testPublicId)
                    ->lockForUpdate()
                    ->first(['state', 'delivery_operation_public_id']);
                if ($row === null) {
                    throw new RuntimeException('Broadcast Owner test disappeared before delivery link.');
                }
                if ((string) $row->state !== 'prepared') {
                    return;
                }

                $updated = $connection->table('broadcast_campaign_tests')
                    ->where('public_id', $testPublicId)
                    ->where('state', 'prepared')
                    ->update([
                        'state' => 'queued',
                        'delivery_operation_public_id' => $receipt->publicId,
                        'updated_at' => $this->timestamp(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('Broadcast Owner test lost delivery-link authority.');
                }
            }, 3);

            return $this->reconcile($actorUserId, $campaignPublicId, $testPublicId, false);
        }

        return $this->sendSourceMessage(
            $actorUserId,
            $campaignPublicId,
            $testPublicId,
            $context,
            $mode,
        );
    }

    /** @requirement COM-002 ACL-002 DAT-003 SEC-002 OPS-003 */
    public function reconcileCurrent(
        int $actorUserId,
        string $campaignPublicId,
    ): ?TelegramBroadcastOwnerTestReceipt {
        $this->administrators->authorizeUser($actorUserId, TelegramBroadcastCampaignService::PERMISSION);
        $this->assertPublicId($campaignPublicId);

        $row = $this->database->connection()->table('broadcast_campaign_tests as test')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'test.broadcast_campaign_id')
            ->join('broadcast_message_versions as message', 'message.id', '=', 'test.broadcast_message_version_id')
            ->where('campaign.public_id', $campaignPublicId)
            ->whereColumn('message.version', 'campaign.current_message_version')
            ->orderByDesc('test.id')
            ->first(['test.public_id']);
        if ($row === null) {
            return null;
        }

        return $this->reconcile($actorUserId, $campaignPublicId, (string) $row->public_id, true);
    }

    private function sendSourceMessage(
        int $actorUserId,
        string $campaignPublicId,
        string $testPublicId,
        array $context,
        TelegramBroadcastMessageMode $mode,
    ): TelegramBroadcastOwnerTestReceipt {
        $this->administrators->authorizeUser($actorUserId, TelegramBroadcastCampaignService::PERMISSION);
        $this->assertCampaignCreatorAuthorized($context);
        $this->assertSourceStillBound($context['creator_user_id'], $context['bot_id'], $context['source_chat_id']);

        $connection = $this->database->connection();
        $entered = $connection->transaction(function (Connection $connection) use ($testPublicId): bool {
            $row = $connection->table('broadcast_campaign_tests')
                ->where('public_id', $testPublicId)
                ->lockForUpdate()
                ->first(['state', 'provider_boundary_started_at']);
            if ($row === null) {
                throw new RuntimeException('Broadcast Owner test disappeared before source-message effect.');
            }
            if ((string) $row->state === 'sending') {
                $connection->table('broadcast_campaign_tests')
                    ->where('public_id', $testPublicId)
                    ->where('state', 'sending')
                    ->update([
                        'state' => 'uncertain',
                        'result_code' => 'broadcast_owner_test_interrupted_source_effect',
                        'provider_boundary_finished_at' => $this->timestamp(),
                        'updated_at' => $this->timestamp(),
                    ]);

                return false;
            }
            if ((string) $row->state !== 'prepared') {
                return false;
            }

            $now = $this->timestamp();
            $updated = $connection->table('broadcast_campaign_tests')
                ->where('public_id', $testPublicId)
                ->where('state', 'prepared')
                ->update([
                    'state' => 'sending',
                    'provider_boundary_started_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast Owner test lost source-effect authority.');
            }

            return true;
        }, 3);

        if (! $entered) {
            return $this->reconcile($actorUserId, $campaignPublicId, $testPublicId, true);
        }

        $sourceMode = $mode === TelegramBroadcastMessageMode::Copy
            ? TelegramSourceMessageMode::Copy
            : TelegramSourceMessageMode::Forward;
        $sourceChatId = $context['source_chat_id'];
        $sourceMessageId = $context['source_message_id'];
        if (! is_int($sourceChatId) || $sourceChatId < 1 || ! is_int($sourceMessageId) || $sourceMessageId < 1) {
            throw new RuntimeException('Broadcast Owner test source identity is invalid.');
        }
        $keyboard = $this->keyboard($context['inline_keyboard_snapshot']);
        $resolvedKeyboard = $keyboard === null
            ? null
            : TelegramResolvedInlineKeyboardMarkup::resolve($keyboard, []);

        $result = $this->sourceMessages->send(
            $context['telegram_user_id'],
            new TelegramResolvedSourceMessagePresentation($sourceMode, $sourceChatId, $sourceMessageId),
            $resolvedKeyboard,
        );

        $connection->transaction(function (Connection $connection) use ($testPublicId, $result): void {
            $row = $connection->table('broadcast_campaign_tests')
                ->where('public_id', $testPublicId)
                ->lockForUpdate()
                ->first(['state']);
            if ($row === null) {
                throw new RuntimeException('Broadcast Owner test disappeared after source-message effect.');
            }
            if ((string) $row->state !== 'sending') {
                return;
            }

            [$state, $messageId] = match ($result->outcome) {
                TelegramMutationOutcome::Success => ['succeeded', $result->messageId],
                TelegramMutationOutcome::UncertainResult => ['uncertain', null],
                TelegramMutationOutcome::RetryAfter,
                TelegramMutationOutcome::DefinitiveNoEffectRetryable,
                TelegramMutationOutcome::DefinitiveFailure => ['failed', null],
            };
            $now = $this->timestamp();
            $updated = $connection->table('broadcast_campaign_tests')
                ->where('public_id', $testPublicId)
                ->where('state', 'sending')
                ->update([
                    'state' => $state,
                    'telegram_message_id' => $messageId,
                    'result_code' => $result->resultCode,
                    'provider_boundary_finished_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast Owner test lost source-result authority.');
            }
        }, 3);

        return $this->reconcile($actorUserId, $campaignPublicId, $testPublicId, false);
    }

    private function reconcile(
        int $actorUserId,
        string $campaignPublicId,
        string $testPublicId,
        bool $replayed,
    ): TelegramBroadcastOwnerTestReceipt {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        $this->assertOwner($administratorId, $actorUserId);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $campaignPublicId,
            $testPublicId,
            $administratorId,
            $replayed,
        ): TelegramBroadcastOwnerTestReceipt {
            $row = $connection->table('broadcast_campaign_tests as test')
                ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'test.broadcast_campaign_id')
                ->join('broadcast_message_versions as message', 'message.id', '=', 'test.broadcast_message_version_id')
                ->where('test.public_id', $testPublicId)
                ->where('campaign.public_id', $campaignPublicId)
                ->lockForUpdate()
                ->first([
                    'test.id',
                    'test.public_id',
                    'test.owner_administrator_id',
                    'test.state',
                    'test.delivery_operation_public_id',
                    'test.telegram_message_id',
                    'test.result_code',
                    'message.version as tested_message_version',
                    'campaign.current_message_version',
                ]);
            if ($row === null || (int) $row->owner_administrator_id !== $administratorId) {
                throw new DomainException('Broadcast Owner test is unavailable for this actor.');
            }

            $deliveryPublicId = $row->delivery_operation_public_id === null
                ? null
                : (string) $row->delivery_operation_public_id;
            if ($deliveryPublicId !== null && in_array((string) $row->state, ['queued', 'sending'], true)) {
                $operation = $connection->table('telegram_delivery_operations')
                    ->where('public_id', $deliveryPublicId)
                    ->first([
                        'state',
                        'telegram_message_id',
                        'result_code',
                    ]);
                if ($operation === null) {
                    throw new RuntimeException('Broadcast Owner test delivery operation is missing.');
                }

                $operationState = TelegramDeliveryOperationState::tryFrom((string) $operation->state)
                    ?? throw new RuntimeException('Broadcast Owner test delivery state is invalid.');
                [$state, $messageId, $resultCode] = match ($operationState) {
                    TelegramDeliveryOperationState::Prepared,
                    TelegramDeliveryOperationState::Retryable => ['queued', null, $operation->result_code],
                    TelegramDeliveryOperationState::Sending => ['sending', null, $operation->result_code],
                    TelegramDeliveryOperationState::Succeeded => [
                        'succeeded',
                        $this->positiveNullableInt($operation->telegram_message_id, 'Broadcast Owner test message ID'),
                        $operation->result_code,
                    ],
                    TelegramDeliveryOperationState::FailedFinal,
                    TelegramDeliveryOperationState::ReviewRequired => ['failed', null, $operation->result_code],
                    TelegramDeliveryOperationState::Uncertain => ['uncertain', null, $operation->result_code],
                };

                if ($state !== (string) $row->state
                    || $messageId !== ($row->telegram_message_id === null ? null : (int) $row->telegram_message_id)
                    || ($resultCode !== null && ! hash_equals((string) ($row->result_code ?? ''), (string) $resultCode))
                ) {
                    $connection->table('broadcast_campaign_tests')
                        ->where('id', (int) $row->id)
                        ->update([
                            'state' => $state,
                            'telegram_message_id' => $messageId,
                            'result_code' => $resultCode,
                            'updated_at' => $this->timestamp(),
                        ]);
                    $row->state = $state;
                    $row->telegram_message_id = $messageId;
                    $row->result_code = $resultCode;
                }
            }

            return new TelegramBroadcastOwnerTestReceipt(
                (string) $row->public_id,
                (string) $row->state,
                $replayed,
                $deliveryPublicId,
                $row->telegram_message_id === null ? null : (int) $row->telegram_message_id,
                $row->result_code === null ? null : (string) $row->result_code,
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    private function testContext(
        Connection $connection,
        int $actorUserId,
        int $administratorId,
        string $campaignPublicId,
        int $expectedStateVersion,
    ): array {
        $botId = $this->runtime->botId();
        $row = $connection->table('broadcast_campaigns as campaign')
            ->join('administrators as creator', 'creator.id', '=', 'campaign.actor_administrator_id')
            ->join('broadcast_message_versions as message', function ($join): void {
                $join->on('message.broadcast_campaign_id', '=', 'campaign.id')
                    ->on('message.version', '=', 'campaign.current_message_version');
            })
            ->join('telegram_accounts as telegram', function ($join) use ($actorUserId, $botId): void {
                $join->where('telegram.user_id', '=', $actorUserId)
                    ->where('telegram.bot_id', '=', $botId)
                    ->where('telegram.is_bot', '=', false);
            })
            ->where('campaign.public_id', $campaignPublicId)
            ->first([
                'campaign.id as campaign_id',
                'campaign.actor_administrator_id',
                'creator.user_id as creator_user_id',
                'campaign.bot_id',
                'campaign.state',
                'campaign.state_version',
                'campaign.correlation_id',
                'campaign.current_message_version',
                'message.id as message_version_id',
                'message.mode',
                'message.text',
                'message.source_chat_id',
                'message.source_message_id',
                'message.inline_keyboard_snapshot',
                'telegram.id as telegram_account_id',
                'telegram.telegram_user_id',
            ]);
        if ($row === null
            || ! hash_equals((string) $row->bot_id, $botId)
            || (string) $row->state !== TelegramBroadcastCampaignState::Draft->value
            || (int) $row->state_version !== $expectedStateVersion
        ) {
            throw new DomainException('Broadcast campaign is unavailable or changed before Owner test.');
        }

        return [
            'campaign_id' => $this->positiveInt($row->campaign_id, 'Broadcast campaign ID'),
            'administrator_id' => $administratorId,
            'creator_administrator_id' => $this->positiveInt($row->actor_administrator_id, 'Broadcast creator administrator ID'),
            'creator_user_id' => $this->positiveInt($row->creator_user_id, 'Broadcast creator user ID'),
            'bot_id' => $botId,
            'correlation_id' => (string) $row->correlation_id,
            'message_version_id' => $this->positiveInt($row->message_version_id, 'Broadcast message version ID'),
            'mode' => (string) $row->mode,
            'text' => $row->text === null ? null : (string) $row->text,
            'source_chat_id' => $row->source_chat_id === null ? null : $this->positiveInt($row->source_chat_id, 'Broadcast source chat ID'),
            'source_message_id' => $row->source_message_id === null ? null : $this->positiveInt($row->source_message_id, 'Broadcast source message ID'),
            'inline_keyboard_snapshot' => $row->inline_keyboard_snapshot === null ? null : (string) $row->inline_keyboard_snapshot,
            'telegram_account_id' => $this->positiveInt($row->telegram_account_id, 'Broadcast Owner Telegram account ID'),
            'telegram_user_id' => $this->positiveInt($row->telegram_user_id, 'Broadcast Owner Telegram user ID'),
        ];
    }

    private function assertReplayMatches(object $row, array $context): void
    {
        if ((int) $row->broadcast_campaign_id !== $context['campaign_id']
            || (int) $row->broadcast_message_version_id !== $context['message_version_id']
            || (int) $row->owner_administrator_id !== $context['administrator_id']
            || (int) $row->telegram_account_id !== $context['telegram_account_id']
        ) {
            throw new DomainException('Broadcast Owner test request key was reused with different input.');
        }
    }

    /** @param array<string,mixed> $context */
    private function assertCampaignCreatorAuthorized(array $context): void
    {
        $creatorUserId = $this->positiveInt(
            $context['creator_user_id'] ?? null,
            'Broadcast creator user ID',
        );
        $expectedAdministratorId = $this->positiveInt(
            $context['creator_administrator_id'] ?? null,
            'Broadcast creator administrator ID',
        );
        $authorizedAdministratorId = $this->administrators->authorizeUser(
            $creatorUserId,
            TelegramBroadcastCampaignService::PERMISSION,
        );
        if ($authorizedAdministratorId !== $expectedAdministratorId) {
            throw new DomainException('Broadcast campaign creator authorization changed before provider effect.');
        }
    }

    private function assertOwner(int $administratorId, int $actorUserId): void
    {
        $owner = $this->database->connection()->table('administrators')
            ->where('id', $administratorId)
            ->where('user_id', $actorUserId)
            ->where('status', 'active')
            ->where('is_owner', true)
            ->exists();
        if (! $owner) {
            throw new DomainException('Broadcast test delivery is restricted to an active Owner.');
        }
    }

    private function assertSourceStillBound(int $actorUserId, string $botId, mixed $sourceChatId): void
    {
        $sourceChatId = $this->positiveInt($sourceChatId, 'Broadcast source chat ID');
        $telegramUserId = $this->database->connection()->table('telegram_accounts')
            ->where('user_id', $actorUserId)
            ->where('bot_id', $botId)
            ->where('is_bot', false)
            ->value('telegram_user_id');
        if ((! is_int($telegramUserId) && ! is_string($telegramUserId))
            || (int) $telegramUserId !== $sourceChatId
        ) {
            throw new DomainException('Broadcast source message is no longer bound to the authorized administrator.');
        }
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
            throw new DomainException('Broadcast Owner test cannot reuse recipient-bound callback buttons.');
        }

        return $keyboard;
    }

    private function requestHash(string $requestKey): string
    {
        if ($requestKey === ''
            || strlen($requestKey) > 512
            || ! mb_check_encoding($requestKey, 'UTF-8')
            || str_contains($requestKey, "\0")
        ) {
            throw new DomainException('Broadcast Owner test request key is invalid.');
        }

        return hash('sha256', 'telegram-broadcast-owner-test-v1|'.$requestKey);
    }

    private function assertPublicId(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Broadcast campaign public ID is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }

    private function positiveNullableInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInt($value, $label);
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && preg_match('/duplicate|unique/i', $exception->getMessage()) === 1;
    }
}
