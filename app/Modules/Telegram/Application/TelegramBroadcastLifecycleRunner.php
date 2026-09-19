<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\TelegramBroadcastLifecycleTransport;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use App\Modules\Telegram\Domain\TelegramBroadcastMessageMode;
use App\Modules\Telegram\Domain\TelegramBroadcastSourceKind;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class TelegramBroadcastLifecycleRunner
{
    private const DIRECT_BOUNDARY_RECOVERY_MINUTES = 5;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $administrators,
        private TelegramBroadcastTextDeliveryGateway $textDelivery,
        private TelegramBroadcastLifecycleTransport $transport,
    ) {}

    /** @requirement COM-003 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function processBatch(int $limit = 25): int
    {
        $this->assertLimit($limit);
        $this->recoverInterruptedDirectMutations(min(100, $limit * 2));
        $this->reconcileLinkedDeliveries(min(100, $limit * 2));

        $processed = 0;
        while ($processed < $limit) {
            $publicId = $this->nextPreparedOperationPublicId();
            if ($publicId === null) {
                break;
            }

            try {
                $this->processPrepared($publicId);
            } catch (Throwable) {
                $this->failPreparedSafely($publicId, 'broadcast_lifecycle_processing_exception');
            }
            $processed++;
        }

        $this->reconcileLinkedDeliveries(min(100, $limit * 2));

        return $processed;
    }

    public function reconcile(int $limit = 100): int
    {
        $this->assertLimit($limit);

        return $this->recoverInterruptedDirectMutations($limit)
            + $this->reconcileLinkedDeliveries($limit);
    }

    private function nextPreparedOperationPublicId(): ?string
    {
        return $this->database->connection()->transaction(function (Connection $connection): ?string {
            /** @var object{public_id:string}|null $row */
            $row = $connection->table('broadcast_recipient_messages')
                ->whereIn('action', ['edit', 'buttons', 'pin', 'unpin', 'delete'])
                ->where('state', 'prepared')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['public_id']);

            return $row?->public_id;
        }, 3);
    }

    private function processPrepared(string $operationPublicId): void
    {
        $context = $this->context($operationPublicId);
        $action = TelegramBroadcastLifecycleAction::tryFrom($context['action'])
            ?? throw new RuntimeException('Broadcast lifecycle action is invalid.');

        try {
            $this->administrators->authorize(
                $context['requested_by_administrator_id'],
                TelegramBroadcastCampaignService::PERMISSION,
            );
        } catch (AuthorizationException) {
            $this->failPreparedSafely($operationPublicId, 'broadcast_lifecycle_authorization_lost');

            return;
        }

        if (! $this->hasSuccessfulOwnerTest(
            $context['campaign_id'],
            $context['message_version'],
        )) {
            $this->failPreparedSafely($operationPublicId, 'broadcast_lifecycle_owner_test_invalid');

            return;
        }

        if ($context['recipient_delivery_state'] !== 'sent'
            || $context['recipient_lifecycle_state'] === 'deleted'
            || $context['recipient_message_id'] !== $context['target_message_id']
        ) {
            $this->skipPrepared(
                $operationPublicId,
                'broadcast_lifecycle_target_no_longer_eligible',
            );

            return;
        }

        $mode = TelegramBroadcastMessageMode::tryFrom($context['message_mode'])
            ?? throw new RuntimeException('Broadcast lifecycle message mode is invalid.');

        if ($action === TelegramBroadcastLifecycleAction::Delete) {
            $this->queueDurableDelete($operationPublicId, $context);

            return;
        }

        if ($mode === TelegramBroadcastMessageMode::NewText
            && in_array($action, [
                TelegramBroadcastLifecycleAction::Edit,
                TelegramBroadcastLifecycleAction::Buttons,
            ], true)
        ) {
            $this->queueDurableTextEdit($operationPublicId, $context);

            return;
        }

        $this->directMutation($operationPublicId, $context, $action, $mode);
    }

    /** @param array<string,mixed> $context */
    private function queueDurableTextEdit(string $operationPublicId, array $context): void
    {
        $text = $context['message_text'];
        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Broadcast lifecycle text is unavailable.');
        }
        $keyboard = $this->keyboard($context['inline_keyboard_snapshot']);
        $receipt = $this->textDelivery->queueEdit(
            $context['telegram_user_id'],
            $context['target_message_id'],
            $text,
            'tg-broadcast-lifecycle:'.$operationPublicId,
            $this->correlationId($operationPublicId),
            $keyboard,
        );

        $this->linkDeliveryOperation($operationPublicId, $receipt);
        $this->reconcileOperation($operationPublicId);
    }

    /** @param array<string,mixed> $context */
    private function queueDurableDelete(string $operationPublicId, array $context): void
    {
        $receipt = $this->textDelivery->queueDelete(
            $context['telegram_user_id'],
            $context['target_message_id'],
            'tg-broadcast-lifecycle:'.$operationPublicId,
            $this->correlationId($operationPublicId),
        );

        $this->linkDeliveryOperation($operationPublicId, $receipt);
        $this->reconcileOperation($operationPublicId);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function directMutation(
        string $operationPublicId,
        array $context,
        TelegramBroadcastLifecycleAction $action,
        TelegramBroadcastMessageMode $mode,
    ): void {
        $entered = $this->enterDirectBoundary($operationPublicId);
        if (! $entered) {
            return;
        }

        try {
            $request = match ($action) {
                TelegramBroadcastLifecycleAction::Edit => $this->captionEditRequest($context, $mode),
                TelegramBroadcastLifecycleAction::Buttons => $this->buttonsRequest($context, $mode),
                TelegramBroadcastLifecycleAction::Pin => TelegramBroadcastLifecycleMutationRequest::pin(
                    $context['telegram_user_id'],
                    $context['target_message_id'],
                ),
                TelegramBroadcastLifecycleAction::Unpin => TelegramBroadcastLifecycleMutationRequest::unpin(
                    $context['telegram_user_id'],
                    $context['target_message_id'],
                ),
                TelegramBroadcastLifecycleAction::Delete => throw new RuntimeException(
                    'Broadcast delete cannot enter direct lifecycle transport.',
                ),
            };
            $result = $this->transport->mutate($request);
        } catch (Throwable) {
            $result = new TelegramMutationResult(
                TelegramMutationOutcome::UncertainResult,
                'broadcast_lifecycle_transport_exception',
            );
        }

        $this->finalizeDirectResult($operationPublicId, $action, $result);
    }

    /** @param array<string,mixed> $context */
    private function captionEditRequest(
        array $context,
        TelegramBroadcastMessageMode $mode,
    ): TelegramBroadcastLifecycleMutationRequest {
        $kind = $context['source_kind'] === null
            ? null
            : TelegramBroadcastSourceKind::tryFrom((string) $context['source_kind']);
        $caption = $context['caption_override'];

        if ($mode !== TelegramBroadcastMessageMode::Copy
            || $kind?->supportsCaption() !== true
            || ! is_string($caption)
        ) {
            throw new DomainException('Broadcast lifecycle caption edit is not provider-compatible.');
        }

        return TelegramBroadcastLifecycleMutationRequest::editCaption(
            $context['telegram_user_id'],
            $context['target_message_id'],
            $caption,
            $this->resolvedKeyboard($context['inline_keyboard_snapshot']),
        );
    }

    /** @param array<string,mixed> $context */
    private function buttonsRequest(
        array $context,
        TelegramBroadcastMessageMode $mode,
    ): TelegramBroadcastLifecycleMutationRequest {
        if ($mode !== TelegramBroadcastMessageMode::Copy) {
            throw new DomainException('Broadcast direct button mutation is limited to copied messages.');
        }

        return TelegramBroadcastLifecycleMutationRequest::buttons(
            $context['telegram_user_id'],
            $context['target_message_id'],
            $this->resolvedKeyboard($context['inline_keyboard_snapshot']),
        );
    }

    private function enterDirectBoundary(string $operationPublicId): bool
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($operationPublicId): bool {
            /** @var object{id:int|string,state:string,provider_boundary_started_at:?string}|null $operation */
            $operation = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $operationPublicId)
                ->lockForUpdate()
                ->first(['id', 'state', 'provider_boundary_started_at']);
            if ($operation === null) {
                throw new DomainException('Broadcast lifecycle operation is unavailable.');
            }
            if ((string) $operation->state === 'sending') {
                $this->markOperationUncertain(
                    $connection,
                    (int) $operation->id,
                    'broadcast_lifecycle_interrupted_after_boundary',
                );

                return false;
            }
            if ((string) $operation->state !== 'prepared') {
                return false;
            }

            $now = $this->timestamp();
            $updated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $operation->id)
                ->where('state', 'prepared')
                ->update([
                    'state' => 'sending',
                    'provider_boundary_started_at' => $now,
                    'provider_boundary_finished_at' => null,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast lifecycle provider-boundary transition failed.');
            }

            return true;
        }, 3);
    }

    private function finalizeDirectResult(
        string $operationPublicId,
        TelegramBroadcastLifecycleAction $action,
        TelegramMutationResult $result,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use (
            $operationPublicId,
            $action,
            $result,
        ): void {
            /** @var object{id:int|string,broadcast_recipient_id:int|string,state:string}|null $operation */
            $operation = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $operationPublicId)
                ->lockForUpdate()
                ->first(['id', 'broadcast_recipient_id', 'state']);
            if ($operation === null) {
                throw new RuntimeException('Broadcast lifecycle operation disappeared after provider effect.');
            }
            if ((string) $operation->state !== 'sending') {
                if (in_array((string) $operation->state, ['succeeded', 'retryable', 'failed', 'uncertain'], true)) {
                    return;
                }

                throw new RuntimeException('Broadcast lifecycle operation lost provider-boundary state.');
            }

            $state = match ($result->outcome) {
                TelegramMutationOutcome::Success => 'succeeded',
                TelegramMutationOutcome::RetryAfter,
                TelegramMutationOutcome::DefinitiveNoEffectRetryable => 'retryable',
                TelegramMutationOutcome::DefinitiveFailure => 'failed',
                TelegramMutationOutcome::UncertainResult => 'uncertain',
            };
            $now = $this->timestamp();
            $updated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $operation->id)
                ->where('state', 'sending')
                ->update([
                    'state' => $state,
                    'result_code' => $result->resultCode,
                    'provider_boundary_finished_at' => $now,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast lifecycle provider result transition failed.');
            }

            if ($state === 'succeeded') {
                $connection->table('broadcast_recipients')
                    ->where('id', (int) $operation->broadcast_recipient_id)
                    ->update([
                        'lifecycle_state' => $action->successfulRecipientState(),
                        'updated_at' => $now,
                    ]);
            }
        }, 3);
    }

    private function linkDeliveryOperation(
        string $operationPublicId,
        TelegramDeliveryOperationReceipt $receipt,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use (
            $operationPublicId,
            $receipt,
        ): void {
            /** @var object{id:int|string,state:string,delivery_operation_public_id:?string}|null $operation */
            $operation = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $operationPublicId)
                ->lockForUpdate()
                ->first(['id', 'state', 'delivery_operation_public_id']);
            if ($operation === null) {
                throw new RuntimeException('Broadcast lifecycle operation disappeared before delivery link.');
            }
            if ($operation->delivery_operation_public_id !== null) {
                if (! hash_equals($operation->delivery_operation_public_id, $receipt->publicId)) {
                    throw new RuntimeException('Broadcast lifecycle operation is linked to another delivery operation.');
                }

                return;
            }
            if ((string) $operation->state !== 'prepared') {
                throw new RuntimeException('Broadcast lifecycle operation is not prepared for delivery linking.');
            }

            $updated = $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $operation->id)
                ->where('state', 'prepared')
                ->whereNull('delivery_operation_public_id')
                ->update([
                    'state' => 'queued',
                    'delivery_operation_public_id' => $receipt->publicId,
                    'result_code' => $receipt->resultCode,
                    'updated_at' => $this->timestamp(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Broadcast lifecycle delivery-link transition failed.');
            }
        }, 3);
    }

    private function reconcileLinkedDeliveries(int $limit): int
    {
        /** @var \Illuminate\Support\Collection<int,object{public_id:string}> $rows */
        $rows = $this->database->connection()->table('broadcast_recipient_messages')
            ->whereIn('action', ['edit', 'buttons', 'delete'])
            ->where('state', 'queued')
            ->whereNotNull('delivery_operation_public_id')
            ->orderBy('id')
            ->limit($limit)
            ->get(['public_id']);

        $changed = 0;
        foreach ($rows as $row) {
            if ($this->reconcileOperation($row->public_id)) {
                $changed++;
            }
        }

        return $changed;
    }

    private function reconcileOperation(string $operationPublicId): bool
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($operationPublicId): bool {
            /** @var object{
             *     id:int|string,
             *     broadcast_recipient_id:int|string,
             *     action:string,
             *     state:string,
             *     delivery_operation_public_id:?string
             * }|null $operation
             */
            $operation = $connection->table('broadcast_recipient_messages')
                ->where('public_id', $operationPublicId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'broadcast_recipient_id',
                    'action',
                    'state',
                    'delivery_operation_public_id',
                ]);
            if ($operation === null || $operation->delivery_operation_public_id === null) {
                return false;
            }

            /** @var object{state:string,result_code:?string}|null $delivery */
            $delivery = $connection->table('telegram_delivery_operations')
                ->where('public_id', $operation->delivery_operation_public_id)
                ->first(['state', 'result_code']);
            if ($delivery === null) {
                throw new RuntimeException('Broadcast lifecycle linked delivery operation is missing.');
            }
            $deliveryState = TelegramDeliveryOperationState::tryFrom($delivery->state)
                ?? throw new RuntimeException('Broadcast lifecycle linked delivery state is invalid.');

            if (in_array($deliveryState, [
                TelegramDeliveryOperationState::Prepared,
                TelegramDeliveryOperationState::Sending,
                TelegramDeliveryOperationState::Retryable,
            ], true)) {
                return false;
            }

            [$state, $resultCode] = match ($deliveryState) {
                TelegramDeliveryOperationState::Succeeded => ['succeeded', $delivery->result_code],
                TelegramDeliveryOperationState::ReviewRequired => [
                    'retryable',
                    $this->resultCode($delivery->result_code, 'telegram_lifecycle_review_required'),
                ],
                TelegramDeliveryOperationState::FailedFinal => [
                    'failed',
                    $this->resultCode($delivery->result_code, 'telegram_lifecycle_failed_final'),
                ],
                TelegramDeliveryOperationState::Uncertain => [
                    'uncertain',
                    $this->resultCode($delivery->result_code, 'telegram_lifecycle_uncertain'),
                ],
                default => throw new RuntimeException('Broadcast lifecycle delivery state mapping is incomplete.'),
            };

            $now = $this->timestamp();
            $connection->table('broadcast_recipient_messages')
                ->where('id', (int) $operation->id)
                ->update([
                    'state' => $state,
                    'result_code' => $resultCode,
                    'updated_at' => $now,
                ]);

            if ($state === 'succeeded') {
                $action = TelegramBroadcastLifecycleAction::tryFrom($operation->action)
                    ?? throw new RuntimeException('Broadcast lifecycle action is invalid during reconciliation.');
                $connection->table('broadcast_recipients')
                    ->where('id', (int) $operation->broadcast_recipient_id)
                    ->update([
                        'lifecycle_state' => $action->successfulRecipientState(),
                        'updated_at' => $now,
                    ]);
            }

            return true;
        }, 3);
    }

    private function recoverInterruptedDirectMutations(int $limit): int
    {
        /** @var \Illuminate\Support\Collection<int,object{id:int|string}> $rows */
        $rows = $this->database->connection()->table('broadcast_recipient_messages')
            ->whereIn('action', ['edit', 'buttons', 'pin', 'unpin'])
            ->where('state', 'sending')
            ->whereNull('delivery_operation_public_id')
            ->whereNotNull('provider_boundary_started_at')
            ->whereRaw(
                'provider_boundary_started_at <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL '.
                self::DIRECT_BOUNDARY_RECOVERY_MINUTES.
                ' MINUTE)',
            )
            ->orderBy('provider_boundary_started_at')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);

        $changed = 0;
        foreach ($rows as $row) {
            $changed += $this->database->connection()->transaction(function (Connection $connection) use ($row): int {
                /** @var object{id:int|string,state:string}|null $operation */
                $operation = $connection->table('broadcast_recipient_messages')
                    ->where('id', (int) $row->id)
                    ->lockForUpdate()
                    ->first(['id', 'state']);
                if ($operation === null || (string) $operation->state !== 'sending') {
                    return 0;
                }

                $this->markOperationUncertain(
                    $connection,
                    (int) $operation->id,
                    'broadcast_lifecycle_interrupted_after_boundary',
                );

                return 1;
            }, 3);
        }

        return $changed;
    }

    private function failPreparedSafely(string $operationPublicId, string $resultCode): void
    {
        try {
            $this->database->connection()->transaction(function (Connection $connection) use (
                $operationPublicId,
                $resultCode,
            ): void {
                /** @var object{id:int|string,state:string}|null $operation */
                $operation = $connection->table('broadcast_recipient_messages')
                    ->where('public_id', $operationPublicId)
                    ->lockForUpdate()
                    ->first(['id', 'state']);
                if ($operation === null || (string) $operation->state !== 'prepared') {
                    return;
                }

                $connection->table('broadcast_recipient_messages')
                    ->where('id', (int) $operation->id)
                    ->where('state', 'prepared')
                    ->update([
                        'state' => 'failed',
                        'result_code' => $resultCode,
                        'updated_at' => $this->timestamp(),
                    ]);
            }, 3);
        } catch (Throwable) {
            // No provider boundary was entered. A later pass may safely retry the local transition.
        }
    }

    private function skipPrepared(string $operationPublicId, string $resultCode): void
    {
        $this->database->connection()->table('broadcast_recipient_messages')
            ->where('public_id', $operationPublicId)
            ->where('state', 'prepared')
            ->update([
                'state' => 'skipped',
                'result_code' => $resultCode,
                'updated_at' => $this->timestamp(),
            ]);
    }

    private function markOperationUncertain(Connection $connection, int $operationId, string $resultCode): void
    {
        $now = $this->timestamp();
        $connection->table('broadcast_recipient_messages')
            ->where('id', $operationId)
            ->where('state', 'sending')
            ->update([
                'state' => 'uncertain',
                'result_code' => $resultCode,
                'provider_boundary_finished_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /** @return array<string,mixed> */
    private function context(string $operationPublicId): array
    {
        /** @var object{
         *     campaign_id:int|string,
         *     action:string,
         *     requested_by_administrator_id:int|string,
         *     target_message_id:int|string,
         *     recipient_delivery_state:string,
         *     recipient_lifecycle_state:string,
         *     recipient_message_id:int|string|null,
         *     telegram_user_id:int|string,
         *     message_version:int|string,
         *     message_mode:string,
         *     source_kind:?string,
         *     message_text:?string,
         *     caption_override:?string,
         *     inline_keyboard_snapshot:?string
         * }|null $row
         */
        $row = $this->database->connection()->table('broadcast_recipient_messages as operation')
            ->join('broadcast_recipients as recipient', 'recipient.id', '=', 'operation.broadcast_recipient_id')
            ->join('broadcast_campaigns as campaign', 'campaign.id', '=', 'recipient.broadcast_campaign_id')
            ->join('broadcast_message_versions as message', 'message.id', '=', 'operation.broadcast_message_version_id')
            ->where('operation.public_id', $operationPublicId)
            ->where('operation.state', 'prepared')
            ->first([
                'campaign.id as campaign_id',
                'operation.action',
                'operation.requested_by_administrator_id',
                'operation.telegram_message_id as target_message_id',
                'recipient.delivery_state as recipient_delivery_state',
                'recipient.lifecycle_state as recipient_lifecycle_state',
                'recipient.telegram_message_id as recipient_message_id',
                'recipient.telegram_user_id',
                'message.version as message_version',
                'message.mode as message_mode',
                'message.source_kind',
                'message.text as message_text',
                'message.caption_override',
                'message.inline_keyboard_snapshot',
            ]);
        if ($row === null || $row->requested_by_administrator_id === null) {
            throw new DomainException('Broadcast lifecycle operation is unavailable or already claimed.');
        }

        return [
            'campaign_id' => $this->positiveInt($row->campaign_id, 'Broadcast lifecycle campaign ID'),
            'action' => $row->action,
            'requested_by_administrator_id' => $this->positiveInt(
                $row->requested_by_administrator_id,
                'Broadcast lifecycle administrator ID',
            ),
            'target_message_id' => $this->positiveInt(
                $row->target_message_id,
                'Broadcast lifecycle target message ID',
            ),
            'recipient_delivery_state' => $row->recipient_delivery_state,
            'recipient_lifecycle_state' => $row->recipient_lifecycle_state,
            'recipient_message_id' => $row->recipient_message_id === null
                ? null
                : $this->positiveInt($row->recipient_message_id, 'Broadcast recipient message ID'),
            'telegram_user_id' => $this->positiveInt($row->telegram_user_id, 'Broadcast lifecycle Telegram user ID'),
            'message_version' => $this->positiveInt($row->message_version, 'Broadcast lifecycle message version'),
            'message_mode' => $row->message_mode,
            'source_kind' => $row->source_kind,
            'message_text' => $row->message_text,
            'caption_override' => $row->caption_override,
            'inline_keyboard_snapshot' => $row->inline_keyboard_snapshot,
        ];
    }

    private function hasSuccessfulOwnerTest(int $campaignId, int $messageVersion): bool
    {
        return $this->database->connection()->table('broadcast_campaign_tests as test')
            ->join('broadcast_message_versions as message', 'message.id', '=', 'test.broadcast_message_version_id')
            ->join('administrators as owner', 'owner.id', '=', 'test.owner_administrator_id')
            ->where('test.broadcast_campaign_id', $campaignId)
            ->where('message.broadcast_campaign_id', $campaignId)
            ->where('message.version', $messageVersion)
            ->where('test.state', 'succeeded')
            ->where('owner.is_owner', true)
            ->where('owner.status', 'active')
            ->exists();
    }

    private function keyboard(mixed $snapshot): ?TelegramInlineKeyboardSnapshot
    {
        if ($snapshot === null) {
            return null;
        }
        if (! is_string($snapshot)) {
            throw new RuntimeException('Broadcast lifecycle keyboard snapshot is invalid.');
        }

        $keyboard = TelegramInlineKeyboardSnapshot::restore($snapshot);
        if ($keyboard->callbackPublicIds() !== []) {
            throw new DomainException('Broadcast lifecycle cannot reuse recipient-bound callback buttons.');
        }

        return $keyboard;
    }

    private function resolvedKeyboard(mixed $snapshot): ?TelegramResolvedInlineKeyboardMarkup
    {
        $keyboard = $this->keyboard($snapshot);

        return $keyboard === null
            ? null
            : TelegramResolvedInlineKeyboardMarkup::resolve($keyboard, []);
    }

    private function correlationId(string $operationPublicId): string
    {
        return 'tgbl:'.substr(hash('sha256', $operationPublicId), 0, 40);
    }

    private function resultCode(mixed $value, string $fallback): string
    {
        if (is_string($value) && preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $value) === 1) {
            return $value;
        }

        return $fallback;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return $validated;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Broadcast lifecycle limit must be between 1 and 100.');
        }
    }
}
