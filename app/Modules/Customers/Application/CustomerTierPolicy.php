<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerTierCode;
use InvalidArgumentException;

final readonly class CustomerTierPolicy
{
    public function __construct(
        public int $id,
        public CustomerTierCode $code,
        public int $sortOrder,
        public bool $automatic,
        public int $minimumSuccessfulPurchases,
        public int $minimumMembershipDays,
        public bool $totalSpendEnabled,
        public int $minimumTotalSpendIrr,
    ) {
        if ($id < 1 || $sortOrder < 0 || $minimumSuccessfulPurchases < 0 || $minimumMembershipDays < 0 || $minimumTotalSpendIrr < 0) {
            throw new InvalidArgumentException('Customer tier policy values are invalid.');
        }
    }

    /** @param array<string, mixed> $policy */
    public static function fromDatabase(
        int $id,
        string $code,
        int $sortOrder,
        array $policy,
    ): self {
        $tierCode = CustomerTierCode::tryFrom($code);

        if ($tierCode === null) {
            throw new InvalidArgumentException('Customer tier code is invalid.');
        }

        return new self(
            $id,
            $tierCode,
            $sortOrder,
            self::boolean($policy['automatic'] ?? false),
            self::nonNegativeInteger($policy['min_successful_purchases'] ?? 0),
            self::nonNegativeInteger($policy['min_membership_days'] ?? 0),
            self::boolean($policy['total_spend_enabled'] ?? false),
            self::nonNegativeInteger($policy['min_total_spend_irr'] ?? 0),
        );
    }

    public function accepts(CustomerTierMetrics $metrics): bool
    {
        if (! $this->automatic
            || $metrics->successfulPurchaseCount < $this->minimumSuccessfulPurchases
            || $metrics->membershipDays < $this->minimumMembershipDays
        ) {
            return false;
        }

        return ! $this->totalSpendEnabled || $metrics->totalSpendIrr >= $this->minimumTotalSpendIrr;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        if (! is_numeric($value) || (int) $value < 0) {
            throw new InvalidArgumentException('Customer tier policy threshold is invalid.');
        }

        return (int) $value;
    }
}
