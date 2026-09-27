<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Application;

use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class PurchaseRefundReferralOutboxHandler implements OutboxEventHandler
{
    private const EVENT_TYPE = 'purchase.referral_reward.refund_requested';

    private const CONTRACT_VERSION = 1;

    private const EVENT_KEY_PREFIX = 'purchase.referral_reward.refund:';

    public function __construct(private ReferralRewardLifecycleService $rewards) {}

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return self::CONTRACT_VERSION;
    }

    /** @requirement REF-001 ARCH-004 DAT-002 DAT-003 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $refundPublicId = $message->payload['purchase_refund_public_id'] ?? null;
        $settlementPublicId = $message->payload['purchase_settlement_public_id'] ?? null;
        if ($message->eventType !== self::EVENT_TYPE
            || $message->contractVersion !== self::CONTRACT_VERSION
            || $message->aggregateType !== 'purchase_refund'
            || ! is_string($refundPublicId)
            || ! Str::isUlid($refundPublicId)
            || ! is_string($settlementPublicId)
            || ! Str::isUlid($settlementPublicId)
            || ! hash_equals($message->aggregateId, $refundPublicId)
            || ! hash_equals(self::EVENT_KEY_PREFIX.$refundPublicId, $message->eventKey)) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $this->rewards->applyPurchaseRefund($refundPublicId, $message->correlationId);
        } catch (DomainException|InvalidArgumentException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        return OutboxDispatchOutcome::Success;
    }
}
