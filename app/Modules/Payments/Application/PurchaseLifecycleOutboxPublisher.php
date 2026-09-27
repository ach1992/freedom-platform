<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DomainException;
use Illuminate\Support\Str;

final readonly class PurchaseLifecycleOutboxPublisher
{
    public const CUSTOMER_TIER_EVENT_TYPE = 'purchase.customer_tier.recalculation_requested';

    public const REFERRAL_ACCRUAL_EVENT_TYPE = 'purchase.referral_reward.accrual_requested';

    public const REFERRAL_REFUND_EVENT_TYPE = 'purchase.referral_reward.refund_requested';

    public const CONTRACT_VERSION = 1;

    public function __construct(private OutboxPublisher $outbox) {}

    /** @requirement USR-002 REF-001 ARCH-004 DAT-002 DAT-003 QUA-004 */
    public function publishSettlement(string $settlementPublicId, int $userId, string $correlationId): void
    {
        if (! Str::isUlid($settlementPublicId) || $userId < 1) {
            throw new DomainException('Purchase lifecycle settlement trigger identity is invalid.');
        }

        $this->outbox->publish(
            (string) Str::uuid(),
            'purchase.customer_tier.recalculate:'.$settlementPublicId,
            self::CUSTOMER_TIER_EVENT_TYPE,
            'purchase_settlement',
            $settlementPublicId,
            new SafeOutboxPayload([
                'purchase_settlement_public_id' => $settlementPublicId,
                'user_id' => $userId,
            ]),
            $correlationId,
            self::CONTRACT_VERSION,
        );

        $this->outbox->publish(
            (string) Str::uuid(),
            'purchase.referral_reward.accrue:'.$settlementPublicId,
            self::REFERRAL_ACCRUAL_EVENT_TYPE,
            'purchase_settlement',
            $settlementPublicId,
            new SafeOutboxPayload([
                'purchase_settlement_public_id' => $settlementPublicId,
            ]),
            $correlationId,
            self::CONTRACT_VERSION,
        );
    }

    /** @requirement REF-001 ARCH-004 DAT-002 DAT-003 QUA-004 */
    public function publishRefund(
        string $refundPublicId,
        string $settlementPublicId,
        string $correlationId,
    ): void {
        if (! Str::isUlid($refundPublicId) || ! Str::isUlid($settlementPublicId)) {
            throw new DomainException('Purchase lifecycle refund trigger identity is invalid.');
        }

        $this->outbox->publish(
            (string) Str::uuid(),
            'purchase.referral_reward.refund:'.$refundPublicId,
            self::REFERRAL_REFUND_EVENT_TYPE,
            'purchase_refund',
            $refundPublicId,
            new SafeOutboxPayload([
                'purchase_refund_public_id' => $refundPublicId,
                'purchase_settlement_public_id' => $settlementPublicId,
            ]),
            $correlationId,
            self::CONTRACT_VERSION,
        );
    }
}
