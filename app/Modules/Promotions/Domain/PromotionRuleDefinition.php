<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PromotionRuleDefinition
{
    public function __construct(
        public PromotionRuleState $state,
        public int $priority,
        public PromotionDiscountType $discountType,
        public ?int $fixedDiscountIrr,
        public ?int $percentageBasisPoints,
        public int $minimumOrderIrr,
        public ?int $maximumDiscountIrr,
        public ?DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public ?int $totalUseLimit,
        public ?int $perUserUseLimit,
        public bool $firstPurchaseOnly,
        public PromotionAudience $audience,
        public ?string $tierCode = null,
        public ?int $customerTagId = null,
        public ?int $planOfferingId = null,
        public ?int $productId = null,
        public ?int $salesServerId = null,
        public ?PromotionAction $action = null,
        public ?string $referralSourceCode = null,
        public bool $allowsFreeOrder = false,
    ) {
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('Promotion rule priority must be between 0 and 65535.');
        }
        if ($minimumOrderIrr < 0) {
            throw new InvalidArgumentException('Promotion minimum order must be non-negative integer IRR.');
        }
        if ($maximumDiscountIrr !== null && $maximumDiscountIrr < 1) {
            throw new InvalidArgumentException('Promotion maximum discount must be positive integer IRR.');
        }

        if ($discountType === PromotionDiscountType::Fixed) {
            if ($fixedDiscountIrr === null || $fixedDiscountIrr < 1 || $percentageBasisPoints !== null) {
                throw new InvalidArgumentException('Fixed promotion rule requires only a positive fixed integer-IRR discount.');
            }
        } elseif ($percentageBasisPoints === null
            || $percentageBasisPoints < 1
            || $percentageBasisPoints > 10000
            || $fixedDiscountIrr !== null) {
            throw new InvalidArgumentException('Percentage promotion rule requires only 1 to 10000 basis points.');
        }

        $from = $effectiveFrom?->setTimezone(new DateTimeZone('UTC'));
        $until = $effectiveUntil?->setTimezone(new DateTimeZone('UTC'));
        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException('Promotion effective-until must be after effective-from.');
        }
        if ($totalUseLimit !== null && $totalUseLimit < 1) {
            throw new InvalidArgumentException('Promotion total use limit must be positive.');
        }
        if ($perUserUseLimit !== null && $perUserUseLimit < 1) {
            throw new InvalidArgumentException('Promotion per-user use limit must be positive.');
        }
        if ($totalUseLimit !== null && $perUserUseLimit !== null && $perUserUseLimit > $totalUseLimit) {
            throw new InvalidArgumentException('Promotion per-user use limit cannot exceed total use limit.');
        }

        foreach ([
            'customer tag ID' => $customerTagId,
            'plan offering ID' => $planOfferingId,
            'product ID' => $productId,
            'sales server ID' => $salesServerId,
        ] as $label => $id) {
            if ($id !== null && $id < 1) {
                throw new InvalidArgumentException("Promotion {$label} must be positive.");
            }
        }
        if ($tierCode !== null && preg_match('/\A[a-z0-9_.-]{1,32}\z/', $tierCode) !== 1) {
            throw new InvalidArgumentException('Promotion tier code is invalid.');
        }
        if ($referralSourceCode !== null && preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $referralSourceCode) !== 1) {
            throw new InvalidArgumentException('Promotion referral source code is invalid.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    public function snapshot(): array
    {
        $snapshot = [
            'action' => $this->action?->value,
            'allows_free_order' => $this->allowsFreeOrder,
            'audience' => $this->audience->value,
            'customer_tag_id' => $this->customerTagId,
            'discount_type' => $this->discountType->value,
            'effective_from' => $this->formatDate($this->effectiveFrom),
            'effective_until' => $this->formatDate($this->effectiveUntil),
            'first_purchase_only' => $this->firstPurchaseOnly,
            'fixed_discount_irr' => $this->fixedDiscountIrr,
            'maximum_discount_irr' => $this->maximumDiscountIrr,
            'minimum_order_irr' => $this->minimumOrderIrr,
            'per_user_use_limit' => $this->perUserUseLimit,
            'percentage_basis_points' => $this->percentageBasisPoints,
            'plan_offering_id' => $this->planOfferingId,
            'priority' => $this->priority,
            'product_id' => $this->productId,
            'referral_source_code' => $this->referralSourceCode,
            'sales_server_id' => $this->salesServerId,
            'state' => $this->state->value,
            'tier_code' => $this->tierCode,
            'total_use_limit' => $this->totalUseLimit,
        ];
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
