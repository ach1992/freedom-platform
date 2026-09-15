<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BenefitCodeDefinition
{
    public function __construct(
        public BenefitCodeState $state,
        public BenefitCodeAudience $audience,
        public bool $singleUse,
        public ?int $totalUseLimit,
        public ?int $perUserUseLimit,
        public ?DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveUntil,
        public ?int $planOfferingId,
        public ?int $productId,
        public ?int $salesServerId,
        public ?int $walletCreditIrr,
        public ?string $discountRuleCode,
    ) {
        foreach ([$planOfferingId, $productId, $salesServerId] as $scopeId) {
            if ($scopeId !== null && $scopeId < 1) {
                throw new InvalidArgumentException('Benefit code scope IDs must be positive.');
            }
        }
        if ($totalUseLimit !== null && $totalUseLimit < 1) {
            throw new InvalidArgumentException('Benefit code total use limit must be positive.');
        }
        if ($perUserUseLimit !== null && $perUserUseLimit < 1) {
            throw new InvalidArgumentException('Benefit code per-user use limit must be positive.');
        }
        if ($totalUseLimit !== null && $perUserUseLimit !== null && $perUserUseLimit > $totalUseLimit) {
            throw new InvalidArgumentException('Benefit code per-user limit cannot exceed the total use limit.');
        }
        if ($singleUse && $totalUseLimit !== 1) {
            throw new InvalidArgumentException('Single-use benefit codes require a total use limit of one.');
        }
        if ($effectiveFrom !== null && $effectiveUntil !== null && $effectiveUntil <= $effectiveFrom) {
            throw new InvalidArgumentException('Benefit code effective window is invalid.');
        }
        if ($walletCreditIrr !== null && $walletCreditIrr < 1) {
            throw new InvalidArgumentException('Benefit code wallet credit must be positive integer IRR.');
        }
        if ($discountRuleCode !== null && preg_match('/\A[a-z0-9_.:-]{1,128}\z/', $discountRuleCode) !== 1) {
            throw new InvalidArgumentException('Benefit code discount rule code is invalid.');
        }
    }

    public function hasScope(): bool
    {
        return $this->planOfferingId !== null || $this->productId !== null || $this->salesServerId !== null;
    }
}
