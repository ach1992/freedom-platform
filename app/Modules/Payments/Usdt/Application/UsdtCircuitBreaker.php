<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Shared\Application\Clock;
use Illuminate\Contracts\Cache\Repository;

final readonly class UsdtCircuitBreaker
{
    public function __construct(
        private Repository $cache,
        private Clock $clock,
        private int $failureThreshold,
        private int $cooldownSeconds,
    ) {}

    public function allows(string $provider): bool
    {
        $state = $this->state($provider);

        return ($state['open_until'] ?? 0) <= $this->clock->now()->getTimestamp();
    }

    public function recordSuccess(string $provider): void
    {
        $this->cache->forget($this->key($provider));
    }

    public function recordFailure(string $provider): void
    {
        $state = $this->state($provider);
        $failures = ($state['failures'] ?? 0) + 1;
        $openUntil = 0;
        if ($failures >= $this->failureThreshold) {
            $openUntil = $this->clock->now()->getTimestamp() + $this->cooldownSeconds;
            $failures = 0;
        }
        $this->cache->put($this->key($provider), ['failures' => $failures, 'open_until' => $openUntil], max(60, $this->cooldownSeconds * 2));
    }

    /** @return array{failures:int,open_until:int} */
    private function state(string $provider): array
    {
        $value = $this->cache->get($this->key($provider), []);
        if (! is_array($value)) {
            return ['failures' => 0, 'open_until' => 0];
        }

        $failures = $value['failures'] ?? 0;
        $openUntil = $value['open_until'] ?? 0;
        if (! is_int($failures) || ! is_int($openUntil)) {
            return ['failures' => 0, 'open_until' => 0];
        }

        return ['failures' => $failures, 'open_until' => $openUntil];
    }

    private function key(string $provider): string
    {
        return 'payments:usdt:circuit:'.$provider;
    }
}
