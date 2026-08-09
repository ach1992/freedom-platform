<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PaymentEligibilityRuleDefinition
{
    public function __construct(
        public PaymentConfigurationState $state,
        public PaymentEligibilityEffect $effect,
        public int $priority,
        public bool $isOverride = false,
        public ?string $accountType = null,
        public ?string $tierCode = null,
        public ?string $identityStatus = null,
        public ?int $customerTagId = null,
        public ?int $minimumAmountIrr = null,
        public ?int $maximumAmountIrr = null,
        public ?PaymentEligibilityAction $action = null,
        public ?int $planOfferingId = null,
        public ?int $productId = null,
        public ?int $salesServerId = null,
        public ?DateTimeImmutable $effectiveFrom = null,
        public ?DateTimeImmutable $effectiveUntil = null,
    ) {
        if ($priority < 0 || $priority > 65535) {
            throw new InvalidArgumentException('Payment eligibility rule priority must be between 0 and 65535.');
        }
        if ($accountType !== null && ! in_array($accountType, ['customer', 'agent'], true)) {
            throw new InvalidArgumentException('Payment eligibility account type is invalid.');
        }
        if ($tierCode !== null && preg_match('/\A[a-z0-9_.-]{1,32}\z/', $tierCode) !== 1) {
            throw new InvalidArgumentException('Payment eligibility tier code is invalid.');
        }
        if ($identityStatus !== null && preg_match('/\A[a-z0-9_.-]{1,32}\z/', $identityStatus) !== 1) {
            throw new InvalidArgumentException('Payment eligibility identity status is invalid.');
        }
        if ($customerTagId !== null && $customerTagId < 1) {
            throw new InvalidArgumentException('Payment eligibility customer tag ID must be positive.');
        }
        if ($minimumAmountIrr !== null && $minimumAmountIrr < 0) {
            throw new InvalidArgumentException('Payment eligibility minimum amount must be non-negative integer IRR.');
        }
        if ($maximumAmountIrr !== null && $maximumAmountIrr < 0) {
            throw new InvalidArgumentException('Payment eligibility maximum amount must be non-negative integer IRR.');
        }
        if ($minimumAmountIrr !== null && $maximumAmountIrr !== null && $maximumAmountIrr < $minimumAmountIrr) {
            throw new InvalidArgumentException('Payment eligibility maximum amount cannot be below minimum amount.');
        }
        foreach ([
            'plan offering ID' => $planOfferingId,
            'product ID' => $productId,
            'sales server ID' => $salesServerId,
        ] as $label => $id) {
            if ($id !== null && $id < 1) {
                throw new InvalidArgumentException("Payment eligibility {$label} must be positive.");
            }
        }
        $from = $effectiveFrom?->setTimezone(new DateTimeZone('UTC'));
        $until = $effectiveUntil?->setTimezone(new DateTimeZone('UTC'));
        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException('Payment eligibility effective-until must be after effective-from.');
        }
    }

    public function specificity(): int
    {
        $values = [
            $this->accountType,
            $this->tierCode,
            $this->identityStatus,
            $this->customerTagId,
            $this->minimumAmountIrr,
            $this->maximumAmountIrr,
            $this->action,
            $this->planOfferingId,
            $this->productId,
            $this->salesServerId,
            $this->effectiveFrom,
            $this->effectiveUntil,
        ];

        return count(array_filter($values, static fn (mixed $value): bool => $value !== null));
    }

    /** @return array<string, bool|int|string|null> */
    public function snapshot(): array
    {
        $snapshot = [
            'account_type' => $this->accountType,
            'action' => $this->action?->value,
            'customer_tag_id' => $this->customerTagId,
            'effect' => $this->effect->value,
            'effective_from' => $this->formatDate($this->effectiveFrom),
            'effective_until' => $this->formatDate($this->effectiveUntil),
            'identity_status' => $this->identityStatus,
            'is_override' => $this->isOverride,
            'maximum_amount_irr' => $this->maximumAmountIrr,
            'minimum_amount_irr' => $this->minimumAmountIrr,
            'plan_offering_id' => $this->planOfferingId,
            'priority' => $this->priority,
            'product_id' => $this->productId,
            'sales_server_id' => $this->salesServerId,
            'state' => $this->state->value,
            'tier_code' => $this->tierCode,
        ];
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
