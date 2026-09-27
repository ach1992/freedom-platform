<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\Clock;
use App\Shared\Application\OperationalAlertRecorder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class SupportAlertService
{
    public const NEW_TICKET_EVENT = 'support.ticket_new';

    public const SLA_DELAY_EVENT = 'support.ticket_sla_delay';

    public const DELIVERY_FAILURE_EVENT = 'support.customer_delivery_failure';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private SupportAlertPolicy $policy,
        private OperationalAlertRecorder $alerts,
    ) {}

    public function recordNewTicket(SupportTicketSnapshot $ticket): void
    {
        if (! $this->policy->newTicketEnabled()) {
            return;
        }

        $this->alerts->raise(
            'info',
            self::NEW_TICKET_EVENT,
            hash('sha256', 'support-new-ticket:'.$ticket->trackingNumber),
            'support.ticket.'.$ticket->id,
            [
                'ticket_id' => $ticket->id,
                'tracking_number' => $ticket->trackingNumber,
                'state' => $ticket->state->value,
                'priority' => $ticket->priority->value,
            ],
        );
    }

    public function resolveSla(string $trackingNumber): void
    {
        if ($trackingNumber === '') {
            throw new InvalidArgumentException('Support ticket tracking number is required.');
        }

        $this->alerts->resolve(
            self::SLA_DELAY_EVENT,
            $this->slaDeduplicationKey($trackingNumber),
        );
    }

    public function scanSla(int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Support SLA scan limit must be between 1 and 1000.');
        }

        $thresholdSeconds = $this->policy->slaThresholdSeconds();
        if ($thresholdSeconds === null) {
            return 0;
        }

        $cutoff = $this->clock->now()->modify('-'.$thresholdSeconds.' seconds');
        $candidateIds = $this->database->connection()->table('support_tickets')
            ->whereIn('state', [
                SupportTicketState::New->value,
                SupportTicketState::AwaitingSupport->value,
            ])
            ->where('updated_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $raised = 0;
        foreach ($candidateIds as $candidateId) {
            $didRaise = $this->database->connection()->transaction(
                function (Connection $connection) use ($candidateId, $cutoff, $thresholdSeconds): bool {
                    /** @var object{id:int|string,tracking_number:string,state:string,priority:string,updated_at:string}|null $ticket */
                    $ticket = $connection->table('support_tickets')
                        ->where('id', $candidateId)
                        ->lockForUpdate()
                        ->first(['id', 'tracking_number', 'state', 'priority', 'updated_at']);
                    if ($ticket === null
                        || ! in_array((string) $ticket->state, [
                            SupportTicketState::New->value,
                            SupportTicketState::AwaitingSupport->value,
                        ], true)
                    ) {
                        return false;
                    }

                    $updatedAt = $this->utcTimestamp((string) $ticket->updated_at);
                    if ($updatedAt > $cutoff) {
                        return false;
                    }

                    $this->alerts->raise(
                        'warning',
                        self::SLA_DELAY_EVENT,
                        $this->slaDeduplicationKey((string) $ticket->tracking_number),
                        'support.ticket.'.(int) $ticket->id,
                        [
                            'ticket_id' => (int) $ticket->id,
                            'tracking_number' => (string) $ticket->tracking_number,
                            'state' => (string) $ticket->state,
                            'priority' => (string) $ticket->priority,
                            'threshold_seconds' => $thresholdSeconds,
                        ],
                    );

                    return true;
                },
                3,
            );
            if ($didRaise) {
                $raised++;
            }
        }

        return $raised;
    }

    public function recordDeliveryFailure(
        SupportTicketCustomerNotificationSnapshot $notification,
        string $stage,
        string $correlationId,
        string $deliveryState,
        string $outboxState,
        ?string $deliveryOperationPublicId = null,
        ?string $resultCode = null,
    ): void {
        if (! $this->policy->deliveryFailureEnabled()) {
            return;
        }

        $context = [
            'ticket_id' => $notification->ticketId,
            'tracking_number' => $notification->trackingNumber,
            'message_id' => $notification->messageId,
            'stage' => $stage,
            'delivery_state' => $deliveryState,
            'outbox_state' => $outboxState,
        ];
        if ($deliveryOperationPublicId !== null) {
            $context['delivery_operation_id'] = $deliveryOperationPublicId;
        }
        if ($resultCode !== null && $resultCode !== '') {
            $context['result_code'] = mb_substr($resultCode, 0, 191);
        }

        $this->alerts->raise(
            'error',
            self::DELIVERY_FAILURE_EVENT,
            SupportCustomerReplyNotification::deduplicationKey($notification->messageId),
            $correlationId,
            $context,
        );
    }

    private function slaDeduplicationKey(string $trackingNumber): string
    {
        return hash('sha256', 'support-sla-delay:'.$trackingNumber);
    }

    private function utcTimestamp(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new RuntimeException('Support ticket timestamp is invalid.');
        }
    }
}
