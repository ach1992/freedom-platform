<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Domain;

use InvalidArgumentException;

final readonly class UsdtRatePolicy
{
    /**
     * @param list<string> $priority
     */
    public function __construct(
        public array $priority,
        public UsdtRateSide $side,
        public int $maxAgeSeconds,
        public string $minRateIrr,
        public string $maxRateIrr,
        public int $maxDivergenceBps,
        public bool $emergencyManualFallback,
        public int $circuitFailureThreshold,
        public int $circuitCooldownSeconds,
    ) {
        if ($priority === [] || count($priority) !== count(array_unique($priority))) {
            throw new InvalidArgumentException('USDT provider priority must contain unique providers.');
        }
        foreach ($priority as $code) {
            if (preg_match('/\A[a-z][a-z0-9_-]{1,31}\z/', $code) !== 1) {
                throw new InvalidArgumentException('USDT provider code is invalid.');
            }
        }
        if ($maxAgeSeconds < 1 || $maxAgeSeconds > 3600) {
            throw new InvalidArgumentException('USDT maximum rate age is invalid.');
        }
        if ($maxDivergenceBps < 0 || $maxDivergenceBps > 10_000) {
            throw new InvalidArgumentException('USDT maximum source divergence is invalid.');
        }
        if ($circuitFailureThreshold < 1 || $circuitFailureThreshold > 100 || $circuitCooldownSeconds < 1 || $circuitCooldownSeconds > 86_400) {
            throw new InvalidArgumentException('USDT circuit-breaker policy is invalid.');
        }
    }
}
