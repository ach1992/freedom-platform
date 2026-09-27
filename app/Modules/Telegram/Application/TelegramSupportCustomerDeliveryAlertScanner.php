<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Support\Application\SupportAlertPolicy;
use App\Modules\Support\Application\SupportAlertService;
use App\Modules\Support\Application\SupportCustomerDeliveryAlertScanner;
use App\Modules\Support\Application\SupportCustomerReplyNotification;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class TelegramSupportCustomerDeliveryAlertScanner implements SupportCustomerDeliveryAlertScanner
{
    public function __construct(
        private DatabaseManager $database,
        private SupportTicketService $support,
        private SupportAlertPolicy $policy,
        private SupportAlertService $alerts,
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

        $recorded = $this->scanNotificationHandoffFailures($limit);
        if ($recorded >= $limit) {
            return $recorded;
        }

        return $recorded + $this->scanTelegramDeliveryFailures($limit - $recorded);
    }

    private function scanNotificationHandoffFailures(int $limit): int
    {
        $rows = $this->database->connection()->table('outbox_messages')
            ->where('event_type', SupportCustomerReplyNotification::EVENT_TYPE)
            ->where('contract_version', SupportCustomerReplyNotification::CONTRACT_VERSION)
            ->where('aggregate_type', SupportCustomerReplyNotification::AGGREGATE_TYPE)
            ->where('dispatch_state', 'review_required')
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'event_key',
                'aggregate_id',
                'payload',
                'correlation_id',
                'dispatch_state',
                'review_reason',
                'last_error_code',
            ]);

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

        $rows = $this->database->connection()
            ->table('telegram_delivery_operations as operation')
            ->join('outbox_messages as outbox', 'outbox.id', '=', 'operation.outbox_event_id')
            ->where('operation.correlation_id', 'like', 'support.reply.%')
            ->where(function (Builder $query) use ($terminalStates): void {
                $query->whereIn('operation.state', $terminalStates)
                    ->orWhere('outbox.dispatch_state', 'review_required');
            })
            ->orderBy('operation.id')
            ->limit($limit)
            ->get([
                'operation.public_id',
                'operation.request_key_hash',
                'operation.state',
                'operation.result_code',
                'operation.correlation_id',
                'outbox.dispatch_state as outbox_state',
                'outbox.review_reason',
            ]);

        $recorded = 0;
        foreach ($rows as $row) {
            $messageId = $this->messageIdFromCorrelation((string) $row->correlation_id);
            if (! hash_equals(
                hash('sha256', 'tg-support-customer-reply:'.$messageId),
                (string) $row->request_key_hash,
            )) {
                throw new RuntimeException('Support Telegram delivery request identity is inconsistent.');
            }

            $notification = $this->support->customerNotificationForMessage($messageId);
            $this->alerts->recordDeliveryFailure(
                $notification,
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
