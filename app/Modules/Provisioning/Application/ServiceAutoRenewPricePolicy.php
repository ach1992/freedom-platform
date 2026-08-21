<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use InvalidArgumentException;

/** @requirement SVC-007 DAT-002 QUA-003 */
final readonly class ServiceAutoRenewPricePolicy
{
    public function allows(
        int $acceptedPriceIrr,
        int $currentPriceIrr,
        AutoRenewPriceChangeMode $mode,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
    ): bool {
        if ($acceptedPriceIrr < 0 || $currentPriceIrr < 0) {
            throw new InvalidArgumentException('Auto-renew prices must be non-negative integer IRR.');
        }
        if ($absoluteIncreaseLimitIrr !== null && $absoluteIncreaseLimitIrr < 0) {
            throw new InvalidArgumentException('Auto-renew absolute price increase limit must be non-negative integer IRR.');
        }
        if ($percentageIncreaseLimitBps !== null && ($percentageIncreaseLimitBps < 0 || $percentageIncreaseLimitBps > 1_000_000)) {
            throw new InvalidArgumentException('Auto-renew percentage price increase limit is invalid.');
        }

        if ($currentPriceIrr === $acceptedPriceIrr) {
            return true;
        }

        return match ($mode) {
            AutoRenewPriceChangeMode::Stop => false,
            AutoRenewPriceChangeMode::Continue => true,
            AutoRenewPriceChangeMode::WithinLimit => $this->withinConfiguredLimits(
                $acceptedPriceIrr,
                $currentPriceIrr,
                $absoluteIncreaseLimitIrr,
                $percentageIncreaseLimitBps,
            ),
        };
    }

    private function withinConfiguredLimits(
        int $acceptedPriceIrr,
        int $currentPriceIrr,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
    ): bool {
        if ($currentPriceIrr < $acceptedPriceIrr) {
            return true;
        }
        if ($absoluteIncreaseLimitIrr === null && $percentageIncreaseLimitBps === null) {
            return false;
        }

        $increaseIrr = $currentPriceIrr - $acceptedPriceIrr;
        if ($absoluteIncreaseLimitIrr !== null && $increaseIrr > $absoluteIncreaseLimitIrr) {
            return false;
        }
        if ($percentageIncreaseLimitBps !== null
            && $increaseIrr > $this->percentageAllowanceIrr($acceptedPriceIrr, $percentageIncreaseLimitBps)) {
            return false;
        }

        return true;
    }

    private function percentageAllowanceIrr(int $acceptedPriceIrr, int $basisPoints): int
    {
        if ($acceptedPriceIrr === 0 || $basisPoints === 0) {
            return 0;
        }

        $whole = intdiv($acceptedPriceIrr, 10_000);
        if ($whole > intdiv(PHP_INT_MAX, $basisPoints)) {
            return PHP_INT_MAX;
        }
        $wholeAllowance = $whole * $basisPoints;
        $remainderAllowance = intdiv(($acceptedPriceIrr % 10_000) * $basisPoints, 10_000);
        if ($wholeAllowance > PHP_INT_MAX - $remainderAllowance) {
            return PHP_INT_MAX;
        }

        return $wholeAllowance + $remainderAllowance;
    }
}
