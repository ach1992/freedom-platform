<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Shared\Application\Clock;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class UsdtRateResolver
{
    /** @var array<string, UsdtRateProvider> */
    private array $providers;

    /**
     * @param list<UsdtRateProvider> $providers
     */
    public function __construct(
        array $providers,
        private UsdtRatePolicy $policy,
        private UsdtCircuitBreaker $circuitBreaker,
        private Clock $clock,
    ) {
        $indexed = [];
        foreach ($providers as $provider) {
            $code = $provider->code();
            if (isset($indexed[$code])) {
                throw new InvalidArgumentException('Duplicate USDT rate provider code.');
            }
            $indexed[$code] = $provider;
        }
        foreach ($policy->priority as $code) {
            if (! isset($indexed[$code])) {
                throw new InvalidArgumentException('Configured USDT rate provider is unavailable.');
            }
        }
        if (bccomp(UsdtDecimal::rate($policy->minRateIrr), UsdtDecimal::rate($policy->maxRateIrr), 8) > 0) {
            throw new InvalidArgumentException('USDT sanity-rate bounds are invalid.');
        }
        $this->providers = $indexed;
    }

    /** @requirement USDT-002 SEC-003 */
    public function resolve(): UsdtRate
    {
        $external = [];
        foreach ($this->policy->priority as $code) {
            if ($code === 'manual') {
                continue;
            }
            if (! $this->circuitBreaker->allows($code)) {
                continue;
            }

            try {
                $rate = $this->providers[$code]->fetch($this->policy->side);
                $this->validate($code, $rate);
                $this->circuitBreaker->recordSuccess($code);
                $external[] = $rate;
            } catch (Throwable) {
                $this->circuitBreaker->recordFailure($code);
            }
        }

        if ($external !== []) {
            $selected = $external[0];
            if (isset($external[1])) {
                $divergence = UsdtDecimal::divergenceBps($selected->rateIrr, $external[1]->rateIrr);
                if (bccomp($divergence, (string) $this->policy->maxDivergenceBps, 4) > 0) {
                    $this->circuitBreaker->recordFailure($selected->source);
                    $this->circuitBreaker->recordFailure($external[1]->source);

                    return $this->manualOrFail('USDT rate sources diverged beyond the configured threshold.');
                }
            }

            return $selected;
        }

        return $this->manualOrFail('No acceptable external USDT rate is available.');
    }

    private function manualOrFail(string $message): UsdtRate
    {
        if (! $this->policy->emergencyManualFallback || ! isset($this->providers['manual'])) {
            throw new RuntimeException($message);
        }

        $rate = $this->providers['manual']->fetch($this->policy->side);
        $this->validate('manual', $rate);

        return $rate;
    }

    private function validate(string $expectedSource, UsdtRate $rate): void
    {
        if ($rate->source !== $expectedSource || preg_match('/\A[a-f0-9]{64}\z/', $rate->responseHash) !== 1) {
            throw new RuntimeException('USDT rate provider returned invalid provenance.');
        }

        $normalized = UsdtDecimal::rate($rate->rateIrr);
        if (bccomp($normalized, UsdtDecimal::rate($this->policy->minRateIrr), 8) < 0 || bccomp($normalized, UsdtDecimal::rate($this->policy->maxRateIrr), 8) > 0) {
            throw new RuntimeException('USDT rate is outside configured sanity bounds.');
        }

        $now = $this->clock->now()->getTimestamp();
        $fetched = $rate->fetchedAt->getTimestamp();
        if ($fetched > $now + 5 || $now - $fetched > $this->policy->maxAgeSeconds) {
            throw new RuntimeException('USDT rate is stale or future-dated.');
        }
    }
}
