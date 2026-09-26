<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class PurchaseSettlementTierOutboxHandler implements OutboxEventHandler
{
    private const EVENT_TYPE = 'purchase.customer_tier.recalculation_requested';

    private const CONTRACT_VERSION = 1;

    private const EVENT_KEY_PREFIX = 'purchase.customer_tier.recalculate:';

    public function __construct(private CustomerTierAutomaticRecalculationService $tiers) {}

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return self::CONTRACT_VERSION;
    }

    /** @requirement USR-002 ARCH-004 DAT-002 DAT-003 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $settlementPublicId = $message->payload['purchase_settlement_public_id'] ?? null;
        $userId = $message->payload['user_id'] ?? null;
        if ($message->eventType !== self::EVENT_TYPE
            || $message->contractVersion !== self::CONTRACT_VERSION
            || $message->aggregateType !== 'purchase_settlement'
            || ! is_string($settlementPublicId)
            || ! Str::isUlid($settlementPublicId)
            || ! hash_equals($message->aggregateId, $settlementPublicId)
            || ! hash_equals(self::EVENT_KEY_PREFIX.$settlementPublicId, $message->eventKey)
            || ! is_int($userId)
            || $userId < 1) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $this->tiers->afterPurchase($userId, $settlementPublicId, $message->correlationId);
        } catch (DomainException|InvalidArgumentException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        return OutboxDispatchOutcome::Success;
    }
}
