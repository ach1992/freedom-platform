<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Domain\QuoteAction;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emits one safe, transactionally coupled continuation command after a paid Order for an existing
 * Service mutation is materialized. Orders owns the commercial event; Provisioning owns its effect.
 */
final readonly class PaidServiceMutationOrderOutboxPublisher
{
    public const OUTBOX_EVENT_TYPE = 'orders.paid_service_mutation.materialized';

    public const OUTBOX_CONTRACT_VERSION = 1;

    public const OUTBOX_AGGREGATE_TYPE = 'order';

    public const OUTBOX_EVENT_KEY_PREFIX = 'order-paid-service-mutation:';

    public function __construct(private OutboxPublisher $outbox) {}

    /** @requirement BUY-002 PAY-002 SVC-003 SVC-004 SVC-005 ARCH-004 SEC-008 QUA-004 */
    public function publishIfRequired(
        string $orderPublicId,
        string $purchaseSettlementPublicId,
        string $quotePublicId,
        string $actionSnapshot,
        string $correlationId,
    ): void {
        $action = QuoteAction::tryFrom($actionSnapshot)
            ?? throw new RuntimeException('Paid Order Quote action is invalid.');
        if ($action === QuoteAction::Purchase) {
            return;
        }
        if (! in_array($action, [
            QuoteAction::Renew,
            QuoteAction::AddData,
            QuoteAction::AddDays,
            QuoteAction::AddDataDays,
            QuoteAction::Reconfigure,
        ], true)) {
            throw new RuntimeException('Paid Order Quote action has no Service mutation continuation.');
        }

        $payload = new SafeOutboxPayload([
            'action' => $action->value,
            'order_public_id' => $orderPublicId,
            'purchase_settlement_public_id' => $purchaseSettlementPublicId,
            'quote_public_id' => $quotePublicId,
        ]);
        $this->outbox->publish(
            (string) Str::uuid(),
            self::OUTBOX_EVENT_KEY_PREFIX.$orderPublicId,
            self::OUTBOX_EVENT_TYPE,
            self::OUTBOX_AGGREGATE_TYPE,
            $orderPublicId,
            $payload,
            $correlationId,
            self::OUTBOX_CONTRACT_VERSION,
        );
    }
}
