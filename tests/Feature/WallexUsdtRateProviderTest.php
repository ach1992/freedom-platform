<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Modules\Payments\Usdt\Infrastructure\WallexUsdtRateProvider;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class WallexUsdtRateClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

/** @requirement USDT-002 SEC-001 SEC-002 QUA-001 */
final class WallexUsdtRateProviderTest extends TestCase
{
    public function test_public_usdttmn_spot_price_is_converted_from_toman_to_irr_without_auth_or_float(): void
    {
        $clock = new WallexUsdtRateClock(new DateTimeImmutable('2026-08-14T05:30:00+00:00'));
        Http::fake([
            WallexUsdtRateProvider::ENDPOINT => Http::response([
                'success' => true,
                'message' => 'The operation was successful',
                'result' => [
                    'markets' => [[
                        'symbol' => 'USDTTMN',
                        'base_asset' => 'USDT',
                        'quote_asset' => 'TMN',
                        'price' => '82131.0000000000000000',
                        'is_spot' => true,
                        'is_tmn_based' => true,
                    ]],
                ],
            ]),
        ]);

        $provider = new WallexUsdtRateProvider($this->app->make(Factory::class), $clock);
        $rate = $provider->fetch(UsdtRateSide::Buy);

        self::assertSame('wallex', $rate->source);
        self::assertSame('821310.00000000', $rate->rateIrr);
        self::assertSame($clock->now()->format(DATE_ATOM), $rate->fetchedAt->format(DATE_ATOM));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $rate->responseHash);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->url() === WallexUsdtRateProvider::ENDPOINT
            && ! $request->hasHeader('x-api-key')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_public_current_price_is_deterministic_for_each_rate_side(): void
    {
        $clock = new WallexUsdtRateClock(new DateTimeImmutable('2026-08-14T05:30:00+00:00'));
        Http::fake([
            WallexUsdtRateProvider::ENDPOINT => Http::response($this->payload('90000')),
        ]);
        $provider = new WallexUsdtRateProvider($this->app->make(Factory::class), $clock);

        self::assertSame('900000.00000000', $provider->fetch(UsdtRateSide::Buy)->rateIrr);
        self::assertSame('900000.00000000', $provider->fetch(UsdtRateSide::Sell)->rateIrr);
        self::assertSame('900000.00000000', $provider->fetch(UsdtRateSide::Last)->rateIrr);
        Http::assertSentCount(3);
    }

    public function test_wrong_or_inactive_market_fails_closed(): void
    {
        $clock = new WallexUsdtRateClock(new DateTimeImmutable('2026-08-14T05:30:00+00:00'));
        Http::fake([
            WallexUsdtRateProvider::ENDPOINT => Http::response([
                'success' => true,
                'result' => [
                    'markets' => [[
                        'symbol' => 'USDTTMN',
                        'base_asset' => 'USDT',
                        'quote_asset' => 'TMN',
                        'price' => '82131',
                        'is_spot' => false,
                        'is_tmn_based' => true,
                    ]],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Wallex USDTTMN spot market is unavailable.');
        (new WallexUsdtRateProvider($this->app->make(Factory::class), $clock))->fetch(UsdtRateSide::Buy);
    }

    public function test_float_or_excess_precision_price_is_rejected_instead_of_rounded(): void
    {
        $clock = new WallexUsdtRateClock(new DateTimeImmutable('2026-08-14T05:30:00+00:00'));
        Http::fake([
            WallexUsdtRateProvider::ENDPOINT => Http::response($this->payload(82131.5)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Wallex USDTTMN price is invalid.');
        (new WallexUsdtRateProvider($this->app->make(Factory::class), $clock))->fetch(UsdtRateSide::Buy);
    }

    public function test_redirect_is_not_followed(): void
    {
        $clock = new WallexUsdtRateClock(new DateTimeImmutable('2026-08-14T05:30:00+00:00'));
        Http::fake([
            WallexUsdtRateProvider::ENDPOINT => Http::response('', 302, ['Location' => 'https://example.invalid/redirect']),
        ]);

        try {
            (new WallexUsdtRateProvider($this->app->make(Factory::class), $clock))->fetch(UsdtRateSide::Buy);
            self::fail('Expected Wallex redirect to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Wallex public market request failed.', $exception->getMessage());
        }
        Http::assertNotSent(static fn ($request): bool => $request->url() === 'https://example.invalid/redirect');
    }

    /** @return array<string,mixed> */
    private function payload(mixed $price): array
    {
        return [
            'success' => true,
            'message' => 'The operation was successful',
            'result' => [
                'markets' => [[
                    'symbol' => 'USDTTMN',
                    'base_asset' => 'USDT',
                    'quote_asset' => 'TMN',
                    'price' => $price,
                    'is_spot' => true,
                    'is_tmn_based' => true,
                ]],
            ],
        ];
    }
}
