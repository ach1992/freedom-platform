<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Payments\Usdt\Application\UsdtAmountQuoteService;
use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDestinationWalletService;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

final readonly class UsdtRuntimeFactory
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private CacheRepository $cache,
        private DatabaseManager $database,
        private QuoteService $quotes,
        private AdministratorPermissionAuthorizer $authorizer,
        private Clock $clock,
    ) {}

    public function rateResolver(): UsdtRateResolver
    {
        $sideValue = $this->string('usdt.rate.side');
        $side = UsdtRateSide::tryFrom($sideValue);
        if ($side === null) {
            throw new InvalidArgumentException('Configured USDT rate side is invalid.');
        }

        $priority = $this->stringList('usdt.rate.priority');
        $manual = $this->config->get('usdt.rate.manual_irr');
        $providers = [
            new NobitexUsdtRateProvider(
                $this->http,
                $this->integer('usdt.rate.http_timeout_seconds'),
                $this->integer('usdt.rate.http_connect_timeout_seconds'),
                $this->integer('usdt.rate.http_max_response_bytes'),
            ),
            new TetherlandUsdtRateProvider(
                $this->http,
                $this->clock,
                $this->integer('usdt.rate.tetherland_irr_multiplier'),
                $this->integer('usdt.rate.http_timeout_seconds'),
                $this->integer('usdt.rate.http_connect_timeout_seconds'),
                $this->integer('usdt.rate.http_max_response_bytes'),
            ),
        ];
        if (is_string($manual) && $manual !== '') {
            $providers[] = new ManualUsdtRateProvider($manual, $this->clock);
        }

        $policy = new UsdtRatePolicy(
            $priority,
            $side,
            $this->integer('usdt.rate.max_age_seconds'),
            $this->string('usdt.rate.min_irr'),
            $this->string('usdt.rate.max_irr'),
            $this->integer('usdt.rate.max_divergence_bps'),
            $this->boolean('usdt.rate.emergency_manual_fallback'),
            $this->integer('usdt.rate.circuit_failure_threshold'),
            $this->integer('usdt.rate.circuit_cooldown_seconds'),
        );
        $circuit = new UsdtCircuitBreaker(
            $this->cache,
            $this->clock,
            $policy->circuitFailureThreshold,
            $policy->circuitCooldownSeconds,
        );

        /** @var list<UsdtRateProvider> $providers */
        return new UsdtRateResolver($providers, $policy, $circuit, $this->clock);
    }

    public function destinationWallets(): UsdtDestinationWalletService
    {
        return new UsdtDestinationWalletService($this->database, $this->authorizer, $this->clock);
    }

    public function amountQuotes(): UsdtAmountQuoteService
    {
        return new UsdtAmountQuoteService(
            $this->database,
            $this->quotes,
            $this->destinationWallets(),
            $this->rateResolver(),
            $this->clock,
            $this->integer('usdt.quote.margin_bps'),
            $this->integer('usdt.quote.rounding_precision'),
            $this->integer('usdt.quote.validity_seconds'),
            $this->integer('usdt.rate.max_age_seconds'),
            $this->string('usdt.quote.destination_wallet_code'),
        );
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException('USDT runtime configuration is invalid: '.$key);
        }

        return $value;
    }

    private function integer(string $key): int
    {
        $value = $this->config->get($key);
        if (! is_int($value)) {
            throw new InvalidArgumentException('USDT runtime configuration is invalid: '.$key);
        }

        return $value;
    }

    private function boolean(string $key): bool
    {
        $value = $this->config->get($key);
        if (! is_bool($value)) {
            throw new InvalidArgumentException('USDT runtime configuration is invalid: '.$key);
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key);
        if (! is_array($value) || $value === []) {
            throw new InvalidArgumentException('USDT runtime configuration is invalid: '.$key);
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException('USDT runtime configuration is invalid: '.$key);
            }
            $result[] = $item;
        }

        return $result;
    }
}
