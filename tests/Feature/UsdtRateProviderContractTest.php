<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Usdt\Application\UsdtCircuitBreaker;
use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use App\Modules\Payments\Usdt\Application\UsdtRateResolver;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRatePolicy;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Modules\Payments\Usdt\Infrastructure\ManualUsdtRateProvider;
use App\Modules\Payments\Usdt\Infrastructure\NobitexUsdtRateProvider;
use App\Modules\Payments\Usdt\Infrastructure\TetherlandUsdtRateProvider;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class MutableUsdtProviderClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class StubUsdtRateProvider implements UsdtRateProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly string $providerCode,
        public string $rateIrr,
        public DateTimeImmutable $fetchedAt,
        public bool $fails = false,
    ) {}

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(UsdtRateSide $side): UsdtRate
    {
        $this->calls++;
        if ($this->fails) {
            throw new RuntimeException('stub transport failure');
        }

        return new UsdtRate(
            $this->providerCode,
            $this->rateIrr,
            $this->fetchedAt,
            hash('sha256', $this->providerCode.'|'.$this->rateIrr.'|'.$side->value),
        );
    }
}

/** @requirement USDT-001 USDT-002 SEC-001 SEC-002 QUA-001 */
final class UsdtRateProviderContractTest extends TestCase
{
    public function test_manual_provider_and_fixed_precision_conversion_are_deterministic_without_float(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $manual = new ManualUsdtRateProvider('1000000', $clock);
        $rate = $manual->fetch(UsdtRateSide::Buy);

        self::assertSame('manual', $rate->source);
        self::assertSame('1000000.00000000', $rate->rateIrr);
        self::assertSame($clock->value, $rate->fetchedAt);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $rate->responseHash);
        self::assertSame('990000.00000000', UsdtDecimal::applyMargin($rate->rateIrr, 100));
        self::assertSame('1.010103', UsdtDecimal::roundUpIrrToUsdt(1_000_001, '990000', 6));
        self::assertSame('0.000001', UsdtDecimal::roundUpIrrToUsdt(1, '1000000', 6));
        self::assertSame('6000000.000000', UsdtDecimal::roundUpIrrToUsdt(9_000_000_000_000, '1500000', 6));
        self::assertSame('1.010200', UsdtDecimal::roundUpIrrToUsdt(1_000_001, '990000', 4));
    }

    public function test_nobitex_public_rls_market_stats_normalize_buy_sell_and_last(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        Http::fake([
            NobitexUsdtRateProvider::ENDPOINT => Http::response([
                'status' => 'ok',
                'stats' => [
                    'usdt-rls' => [
                        'bestSell' => '1000000',
                        'bestBuy' => '990000',
                        'latest' => '995000',
                    ],
                ],
            ]),
        ]);
        $provider = new NobitexUsdtRateProvider($this->app->make(Factory::class), $clock);

        self::assertSame('1000000.00000000', $provider->fetch(UsdtRateSide::Buy)->rateIrr);
        self::assertSame('990000.00000000', $provider->fetch(UsdtRateSide::Sell)->rateIrr);
        self::assertSame('995000.00000000', $provider->fetch(UsdtRateSide::Last)->rateIrr);
        self::assertSame($clock->value, $provider->fetch(UsdtRateSide::Buy)->fetchedAt);
        Http::assertSentCount(4);
        Http::assertSent(static fn ($request): bool => $request->url() === NobitexUsdtRateProvider::ENDPOINT);
        self::assertStringStartsWith('https://', NobitexUsdtRateProvider::ENDPOINT);
        self::assertFalse($this->constructorAcceptsUrl(NobitexUsdtRateProvider::class));
    }

    public function test_tetherland_is_unavailable_without_network_and_only_explicit_verified_fallback_can_continue(): void
    {
        Http::fake();
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $tetherland = new TetherlandUsdtRateProvider;

        $this->assertRuntimeMessage(
            'Tetherland rate provider is unavailable pending a verified official API contract.',
            fn (): UsdtRate => $tetherland->fetch(UsdtRateSide::Buy),
        );
        Http::assertNothingSent();

        $closed = $this->resolver([$tetherland], $clock, false, 500, 3, 60, ['tetherland']);
        $this->assertRuntimeMessage(
            'No acceptable external USDT rate is available.',
            fn (): UsdtRate => $closed->resolve(),
        );
        Http::assertNothingSent();

        $verifiedFallback = new StubUsdtRateProvider('nobitex', '1000000', $clock->value);
        $allowed = $this->resolver(
            [$tetherland, $verifiedFallback],
            $clock,
            false,
            500,
            3,
            60,
            ['tetherland', 'nobitex'],
        );
        self::assertSame('nobitex', $allowed->resolve()->source);
        self::assertSame(1, $verifiedFallback->calls);
        Http::assertNothingSent();
    }

    public function test_http_adapter_fails_closed_for_malformed_oversized_redirect_and_transport_failures(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $http = $this->app->make(Factory::class);
        Http::fake([NobitexUsdtRateProvider::ENDPOINT => Http::response('not-json')]);
        $this->assertProviderThrows(fn (): UsdtRate => (new NobitexUsdtRateProvider($http, $clock))->fetch(UsdtRateSide::Buy));

        Http::fake([NobitexUsdtRateProvider::ENDPOINT => Http::response(str_repeat('x', 65_537))]);
        $this->assertProviderThrows(fn (): UsdtRate => (new NobitexUsdtRateProvider($http, $clock))->fetch(UsdtRateSide::Buy));

        Http::fake([NobitexUsdtRateProvider::ENDPOINT => Http::response('', 302, ['Location' => 'https://example.invalid/redirect'])]);
        $this->assertProviderThrows(fn (): UsdtRate => (new NobitexUsdtRateProvider($http, $clock))->fetch(UsdtRateSide::Buy));
        Http::assertNotSent(static fn ($request): bool => $request->url() === 'https://example.invalid/redirect');

        Http::fake(static fn () => throw new RuntimeException('simulated network failure'));
        $this->assertProviderThrows(fn (): UsdtRate => (new NobitexUsdtRateProvider($http, $clock))->fetch(UsdtRateSide::Buy));
    }

    public function test_resolver_uses_deterministic_primary_then_fallback_and_rejects_stale_or_out_of_bounds_sources(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $primary = new StubUsdtRateProvider('nobitex', '1000000', $clock->value);
        $fallback = new StubUsdtRateProvider('secondary', '1005000', $clock->value);
        $resolver = $this->resolver([$primary, $fallback], $clock, priority: ['nobitex', 'secondary']);
        self::assertSame('nobitex', $resolver->resolve()->source);
        self::assertSame(1, $primary->calls);
        self::assertSame(1, $fallback->calls);

        $primary->fails = true;
        self::assertSame('secondary', $resolver->resolve()->source);

        $primary->fails = false;
        $primary->fetchedAt = $clock->value->modify('-121 seconds');
        self::assertSame('secondary', $resolver->resolve()->source);

        $primary->fetchedAt = $clock->value;
        $primary->rateIrr = '99999';
        self::assertSame('secondary', $resolver->resolve()->source);
    }

    public function test_divergence_fails_closed_unless_emergency_manual_policy_is_explicit(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $primary = new StubUsdtRateProvider('nobitex', '1000000', $clock->value);
        $secondary = new StubUsdtRateProvider('secondary', '1200000', $clock->value);
        $manual = new ManualUsdtRateProvider('1050000', $clock);

        $closed = $this->resolver([$primary, $secondary, $manual], $clock, false, 500, priority: ['nobitex', 'secondary']);
        $this->assertRuntimeMessage('USDT rate sources diverged beyond the configured threshold.', fn (): UsdtRate => $closed->resolve());

        $allowed = $this->resolver([$primary, $secondary, $manual], $clock, true, 500, priority: ['nobitex', 'secondary']);
        $selected = $allowed->resolve();
        self::assertSame('manual', $selected->source);
        self::assertSame('1050000.00000000', $selected->rateIrr);
    }

    public function test_circuit_breaker_skips_open_primary_until_cooldown(): void
    {
        $clock = new MutableUsdtProviderClock(new DateTimeImmutable('2026-08-09T12:00:00+00:00'));
        $primary = new StubUsdtRateProvider('nobitex', '1000000', $clock->value, true);
        $fallback = new StubUsdtRateProvider('secondary', '1000000', $clock->value);
        $resolver = $this->resolver([$primary, $fallback], $clock, false, 500, 1, 60, ['nobitex', 'secondary']);

        self::assertSame('secondary', $resolver->resolve()->source);
        self::assertSame(1, $primary->calls);
        self::assertSame('secondary', $resolver->resolve()->source);
        self::assertSame(1, $primary->calls);

        $clock->value = $clock->value->modify('+61 seconds');
        $primary->fails = false;
        $primary->fetchedAt = $clock->value;
        $fallback->fetchedAt = $clock->value;
        self::assertSame('nobitex', $resolver->resolve()->source);
        self::assertSame(2, $primary->calls);
    }

    /** @param  list<UsdtRateProvider>  $providers */
    private function resolver(
        array $providers,
        MutableUsdtProviderClock $clock,
        bool $manualFallback = false,
        int $divergenceBps = 500,
        int $failureThreshold = 3,
        int $cooldownSeconds = 60,
        array $priority = ['nobitex'],
    ): UsdtRateResolver {
        $policy = new UsdtRatePolicy(
            $priority,
            UsdtRateSide::Buy,
            120,
            '100000',
            '10000000',
            $divergenceBps,
            $manualFallback,
            $failureThreshold,
            $cooldownSeconds,
        );
        $cache = new Repository(new ArrayStore);
        $circuit = new UsdtCircuitBreaker($cache, $clock, $failureThreshold, $cooldownSeconds);

        return new UsdtRateResolver($providers, $policy, $circuit, $clock);
    }

    private function constructorAcceptsUrl(string $class): bool
    {
        $constructor = (new ReflectionMethod($class, '__construct'))->getParameters();
        foreach ($constructor as $parameter) {
            if (str_contains(strtolower($parameter->getName()), 'url') || str_contains(strtolower($parameter->getName()), 'endpoint')) {
                return true;
            }
        }

        return false;
    }

    private function assertProviderThrows(callable $callback): void
    {
        $thrown = null;
        try {
            $callback();
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'Expected provider request failure.');
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
