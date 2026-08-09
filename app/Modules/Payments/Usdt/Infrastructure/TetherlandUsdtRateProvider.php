<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final readonly class TetherlandUsdtRateProvider implements UsdtRateProvider
{
    public const ENDPOINT = 'https://api.tetherland.com/currencies';

    public function __construct(
        private Factory $http,
        private Clock $clock,
        private int $irrMultiplier = 10,
        private int $timeoutSeconds = 4,
        private int $connectTimeoutSeconds = 2,
        private int $maxResponseBytes = 65_536,
    ) {
        if (! in_array($irrMultiplier, [1, 10], true)) {
            throw new RuntimeException('Tetherland IRR multiplier must be one or ten.');
        }
    }

    public function code(): string
    {
        return 'tetherland';
    }

    /** @requirement USDT-002 SEC-003 */
    public function fetch(UsdtRateSide $side): UsdtRate
    {
        $response = $this->http
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'on_headers' => function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxResponseBytes) {
                        throw new RuntimeException('Tetherland response exceeds the configured size limit.');
                    }
                },
            ])
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->get(self::ENDPOINT);

        if (! $response->successful()) {
            throw new RuntimeException('Tetherland public price request failed.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxResponseBytes) {
            throw new RuntimeException('Tetherland response exceeds the configured size limit.');
        }
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Tetherland returned an invalid currency payload.');
        }

        $entry = $this->usdtEntry($payload);
        $price = $this->price($entry, $side);
        $rateIrr = UsdtDecimal::rate(bcmul($price, (string) $this->irrMultiplier, 8));
        $fetchedAt = $this->timestamp($entry, $payload) ?? $this->clock->now();

        return new UsdtRate($this->code(), $rateIrr, $fetchedAt, hash('sha256', $body));
    }

    /** @param array<string|int, mixed> $payload @return array<string, mixed> */
    private function usdtEntry(array $payload): array
    {
        $containers = [$payload];
        foreach (['data', 'currencies', 'result'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $containers[] = $payload[$key];
            }
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach (['currencies', 'result'] as $key) {
                if (isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
                    $containers[] = $payload['data'][$key];
                }
            }
        }

        foreach ($containers as $container) {
            foreach (['USDT', 'usdt'] as $key) {
                if (isset($container[$key]) && is_array($container[$key])) {
                    return $container[$key];
                }
            }
            foreach ($container as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $symbol = $candidate['symbol'] ?? $candidate['code'] ?? $candidate['currency'] ?? null;
                if (is_string($symbol) && strtoupper($symbol) === 'USDT') {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException('Tetherland USDT price entry is missing.');
    }

    /** @param array<string, mixed> $entry */
    private function price(array $entry, UsdtRateSide $side): string
    {
        $keys = match ($side) {
            UsdtRateSide::Buy => ['buy', 'buy_price', 'buyPrice', 'price', 'last', 'lastPrice'],
            UsdtRateSide::Sell => ['sell', 'sell_price', 'sellPrice', 'price', 'last', 'lastPrice'],
            UsdtRateSide::Last => ['price', 'last', 'lastPrice', 'buy', 'buy_price', 'buyPrice'],
        };
        foreach ($keys as $key) {
            $value = $entry[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                return UsdtDecimal::rate((string) $value);
            }
        }

        throw new RuntimeException('Tetherland USDT price is missing.');
    }

    /** @param array<string, mixed> $entry @param array<string|int, mixed> $payload */
    private function timestamp(array $entry, array $payload): ?DateTimeImmutable
    {
        foreach ([$entry, $payload] as $source) {
            foreach (['updated_at', 'updatedAt', 'timestamp', 'lastUpdate'] as $key) {
                $value = $source[$key] ?? null;
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $seconds = (int) $value;
                    if ($seconds > 1_000_000_000_000) {
                        $seconds = intdiv($seconds, 1000);
                    }
                    if ($seconds > 0) {
                        return new DateTimeImmutable('@'.$seconds);
                    }
                }
                if (is_string($value) && $value !== '') {
                    try {
                        return new DateTimeImmutable($value);
                    } catch (\Exception) {
                        continue;
                    }
                }
            }
        }

        return null;
    }
}
