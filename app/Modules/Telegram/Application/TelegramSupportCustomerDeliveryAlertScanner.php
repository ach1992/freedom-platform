<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Support\Application\SupportAlertPolicy;
use App\Modules\Support\Application\SupportAlertService;
use App\Modules\Support\Application\SupportCustomerDeliveryAlertScanner;
use App\Modules\Support\Application\SupportCustomerReplyNotification;
use App\Modules\Support\Application\SupportTicketCustomerNotificationSnapshot;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * @phpstan-type SupportDeliveryRow object{
 *     public_id:string,
 *     request_key_hash:string,
 *     state:string,
 *     result_code:?string,
 *     correlation_id:string,
 *     action:string,
 *     bot_id:string,
 *     recipient_chat_id:int|string,
 *     target_message_id:int|string|null,
 *     presentation_text:?string,
 *     telegram_outbox_event_id:string,
 *     telegram_outbox_event_key:string,
 *     telegram_outbox_event_type:string,
 *     telegram_outbox_contract_version:int|string,
 *     telegram_outbox_aggregate_type:string,
 *     telegram_outbox_aggregate_id:string,
 *     telegram_outbox_payload:string,
 *     telegram_outbox_correlation_id:string,
 *     outbox_state:string,
 *     review_reason:?string
 * }
 */
final readonly class TelegramSupportCustomerDeliveryAlertScanner implements SupportCustomerDeliveryAlertScanner
{
    public function __construct(
        private DatabaseManager $database,
        private SupportTicketService $support,
        private SupportAlertPolicy $policy,
        private SupportAlertService $alerts,
        private Clock $clock,
    ) {}

    /** @requirement SUP-002 ARCH-004 DAT-002 DAT-003 OPS-003 QUA-004 */
    public function scan(int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Support delivery alert scan limit must be between 1 and 1000.');
        }
        if (! $this->policy->deliveryFailureEnabled()) {
            return 0;
        }

        return $this->scanNotificationHandoffFailures($limit)
            + $this->scanTelegramDeliveryFailures($limit);
    }

    private function scanNotificationHandoffFailures(int $limit): int
    {
        $query = $this->database->connection()->table('outbox_messages')
            ->where('event_type', SupportCustomerReplyNotification::EVENT_TYPE)
            ->where('contract_version', SupportCustomerReplyNotification::CONTRACT_VERSION)
            ->where('aggregate_type', SupportCustomerReplyNotification::AGGREGATE_TYPE)
            ->where('dispatch_state', 'review_required')
            ->whereNull('processed_at');

        $total = (int) (clone $query)->count('id');
        if ($total === 0) {
            return 0;
        }

        $windowSize = min($limit, $total);
        $offset = $this->rotationOffset($total, $windowSize);
        $columns = [
            'event_key',
            'aggregate_id',
            'payload',
            'correlation_id',
            'dispatch_state',
            'review_reason',
            'last_error_code',
        ];
        $rows = (clone $query)
            ->orderBy('id')
            ->offset($offset)
            ->limit($windowSize)
            ->get($columns);

        $remaining = $windowSize - $rows->count();
        if ($remaining > 0) {
            $rows = $rows->concat(
                (clone $query)
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get($columns),
            );
        }

        $recorded = 0;
        foreach ($rows as $row) {
            try {
                $payload = json_decode((string) $row->payload, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('Support notification Outbox payload is invalid.');
            }
            if (! is_array($payload) || array_is_list($payload)) {
                throw new RuntimeException('Support notification Outbox payload must be an object.');
            }

            $messageId = $this->positiveInt($payload['message_id'] ?? null, 'Support notification message ID');
            $ticketId = $this->positiveInt($payload['ticket_id'] ?? null, 'Support notification ticket ID');
            if (! hash_equals(SupportCustomerReplyNotification::eventKey($messageId), (string) $row->event_key)
                || ! hash_equals((string) $messageId, (string) $row->aggregate_id)
                || ! hash_equals(SupportCustomerReplyNotification::correlationId($messageId), (string) $row->correlation_id)
            ) {
                throw new RuntimeException('Support notification Outbox identity is inconsistent.');
            }

            $notification = $this->support->customerNotificationForMessage($messageId);
            if ($notification->ticketId !== $ticketId) {
                throw new RuntimeException('Support notification ticket identity is inconsistent.');
            }

            $this->alerts->recordDeliveryFailure(
                $notification,
                'notification_handoff',
                (string) $row->correlation_id,
                'not_queued',
                (string) $row->dispatch_state,
                null,
                $this->boundedCode($row->review_reason ?? $row->last_error_code ?? null),
            );
            $recorded++;
        }

        return $recorded;
    }

    private function scanTelegramDeliveryFailures(int $limit): int
    {
        $terminalStates = [
            TelegramDeliveryOperationState::FailedFinal->value,
            TelegramDeliveryOperationState::Uncertain->value,
            TelegramDeliveryOperationState::ReviewRequired->value,
        ];

        $query = $this->database->connection()
            ->table('telegram_delivery_operations as operation')
            ->join('outbox_messages as outbox', 'outbox.id', '=', 'operation.outbox_event_id')
            ->where('operation.correlation_id', 'like', 'support.reply.%')
            ->where('operation.action', TelegramDeliveryAction::Send->value)
            ->whereNull('operation.target_message_id')
            ->where(
                'operation.presentation_text',
                TelegramDeliveryConfidentialPresentationService::DURABLE_MARKER,
            )
            ->where('outbox.event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)
            ->where(
                'outbox.contract_version',
                TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL,
            )
            ->where('outbox.aggregate_type', TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE)
            ->where(function (Builder $query) use ($terminalStates): void {
                $query->whereIn('operation.state', $terminalStates)
                    ->orWhere('outbox.dispatch_state', 'review_required');
            });

        $total = (int) (clone $query)->count('operation.id');
        if ($total === 0) {
            return 0;
        }

        $windowSize = min($limit, $total);
        $offset = $this->rotationOffset($total, $windowSize);
        $columns = [
            'operation.public_id',
            'operation.request_key_hash',
            'operation.state',
            'operation.result_code',
            'operation.correlation_id',
            'operation.action',
            'operation.bot_id',
            'operation.recipient_chat_id',
            'operation.target_message_id',
            'operation.presentation_text',
            'outbox.id as telegram_outbox_event_id',
            'outbox.event_key as telegram_outbox_event_key',
            'outbox.event_type as telegram_outbox_event_type',
            'outbox.contract_version as telegram_outbox_contract_version',
            'outbox.aggregate_type as telegram_outbox_aggregate_type',
            'outbox.aggregate_id as telegram_outbox_aggregate_id',
            'outbox.payload as telegram_outbox_payload',
            'outbox.correlation_id as telegram_outbox_correlation_id',
            'outbox.dispatch_state as outbox_state',
            'outbox.review_reason',
        ];
        $rows = (clone $query)
            ->orderBy('operation.id')
            ->offset($offset)
            ->limit($windowSize)
            ->get($columns);

        $remaining = $windowSize - $rows->count();
        if ($remaining > 0) {
            $rows = $rows->concat(
                (clone $query)
                    ->orderBy('operation.id')
                    ->limit($remaining)
                    ->get($columns),
            );
        }

        $recorded = 0;
        foreach ($rows as $row) {
            /** @var SupportDeliveryRow $row */
            $messageId = $this->messageIdFromCorrelation((string) $row->correlation_id);
            $handoff = $this->supportNotificationHandoff($messageId);
            if ($handoff === null) {
                continue;
            }
            if (! hash_equals(
                TelegramSupportCustomerReplyDeliveryIdentity::requestKeyHash(
                    $handoff['event_id'],
                    $messageId,
                ),
                (string) $row->request_key_hash,
            )) {
                continue;
            }
            if (! $this->isExactSupportTelegramDelivery($row, $handoff['notification'])) {
                continue;
            }

            $this->alerts->recordDeliveryFailure(
                $handoff['notification'],
                'telegram_delivery',
                (string) $row->correlation_id,
                (string) $row->state,
                (string) $row->outbox_state,
                (string) $row->public_id,
                $this->boundedCode($row->result_code ?? $row->review_reason ?? null),
            );
            $recorded++;
        }

        return $recorded;
    }

    /**
     * @return array{event_id:string,notification:SupportTicketCustomerNotificationSnapshot}|null
     */
    private function supportNotificationHandoff(int $messageId): ?array
    {
        $row = $this->database->connection()
            ->table('outbox_messages')
            ->where('event_key', SupportCustomerReplyNotification::eventKey($messageId))
            ->first([
                'id',
                'event_key',
                'event_type',
                'contract_version',
                'aggregate_type',
                'aggregate_id',
                'payload',
                'correlation_id',
                'dispatch_state',
                'processed_at',
            ]);
        if ($row === null) {
            return null;
        }

        if ((string) $row->dispatch_state !== 'processed'
            || $row->processed_at === null
        ) {
            return null;
        }

        if ((string) $row->event_type !== SupportCustomerReplyNotification::EVENT_TYPE
            || (int) $row->contract_version !== SupportCustomerReplyNotification::CONTRACT_VERSION
            || (string) $row->aggregate_type !== SupportCustomerReplyNotification::AGGREGATE_TYPE
            || ! hash_equals((string) $messageId, (string) $row->aggregate_id)
            || ! hash_equals(
                SupportCustomerReplyNotification::correlationId($messageId),
                (string) $row->correlation_id,
            )
        ) {
            throw new RuntimeException('Support notification Outbox envelope is inconsistent.');
        }

        try {
            $payload = json_decode((string) $row->payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Support notification Outbox payload is invalid.');
        }
        if (! is_array($payload)
            || array_is_list($payload)
            || count($payload) !== 2
            || ! array_key_exists('message_id', $payload)
            || ! array_key_exists('ticket_id', $payload)
        ) {
            throw new RuntimeException('Support notification Outbox payload shape is invalid.');
        }

        $payloadMessageId = $this->positiveInt(
            $payload['message_id'],
            'Support notification message ID',
        );
        $ticketId = $this->positiveInt(
            $payload['ticket_id'],
            'Support notification ticket ID',
        );
        if ($payloadMessageId !== $messageId) {
            throw new RuntimeException('Support notification Outbox message identity is inconsistent.');
        }

        $notification = $this->support->customerNotificationForMessage($messageId);
        if ($notification->ticketId !== $ticketId) {
            throw new RuntimeException('Support notification ticket identity is inconsistent.');
        }

        return [
            'event_id' => (string) $row->id,
            'notification' => $notification,
        ];
    }

    /** @param SupportDeliveryRow $row */
    private function isExactSupportTelegramDelivery(
        object $row,
        SupportTicketCustomerNotificationSnapshot $notification,
    ): bool {
        if ((string) $row->telegram_outbox_event_type !== TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE
            || (int) $row->telegram_outbox_contract_version
                !== TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_CONFIDENTIAL
            || (string) $row->telegram_outbox_event_key
                !== TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.(string) $row->public_id
            || (string) $row->telegram_outbox_aggregate_type
                !== TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE
            || ! hash_equals((string) $row->public_id, (string) $row->telegram_outbox_aggregate_id)
            || ! hash_equals((string) $row->correlation_id, (string) $row->telegram_outbox_correlation_id)
            || (string) $row->action !== TelegramDeliveryAction::Send->value
            || $row->target_message_id !== null
            || ! hash_equals(
                TelegramDeliveryConfidentialPresentationService::DURABLE_MARKER,
                (string) $row->presentation_text,
            )
        ) {
            return false;
        }

        try {
            $payload = json_decode((string) $row->telegram_outbox_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Support Telegram delivery Outbox payload is invalid.');
        }
        if ($payload !== ['telegram_delivery_operation_public_id' => (string) $row->public_id]) {
            return false;
        }

        if (! $this->database->connection()
            ->table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', (string) $row->public_id)
            ->exists()
        ) {
            return false;
        }

        return $this->database->connection()
            ->table('telegram_accounts')
            ->where('bot_id', (string) $row->bot_id)
            ->where('user_id', $notification->requesterUserId)
            ->where('is_bot', 0)
            ->where('telegram_user_id', (int) $row->recipient_chat_id)
            ->exists();
    }

    private function rotationOffset(int $total, int $windowSize): int
    {
        if ($total < 1 || $windowSize < 1 || $windowSize > $total) {
            throw new RuntimeException('Support delivery alert rotation window is invalid.');
        }

        $minute = intdiv($this->clock->now()->getTimestamp(), 60);

        return ($minute * $windowSize) % $total;
    }

    private function messageIdFromCorrelation(string $correlationId): int
    {
        if (preg_match('/\Asupport\.reply\.([1-9][0-9]*)\z/', $correlationId, $matches) !== 1) {
            throw new RuntimeException('Support Telegram delivery correlation identity is invalid.');
        }

        return $this->positiveInt($matches[1], 'Support delivery message ID');
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $validated;
    }

    private function boundedCode(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 191);
    }
}
