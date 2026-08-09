<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final readonly class NobitexUsdtRateProvider implements UsdtRateProvider
{
    public const ENDPOINT = 'https://api.nobitex.ir/v3/orderbook/USDTIRT';

    public function __construct(
        private Factory $http,
        private int $timeoutSeconds = 4,
        private int $connectTimeoutSeconds = 2,
        private int $maxResponseBytes = 65_536,
    ) {}

    public function code(): string
    {
        return 'nobitex';
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
                        throw new RuntimeException('Nobitex response exceeds the configured size limit.');
                    }
                },
            ])
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->get(self::ENDPOINT);

        if (! $response->successful()) {
            throw new RuntimeException('Nobitex public market request failed.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxResponseBytes) {
            throw new RuntimeException('Nobitex response exceeds the configured size limit.');
        }

        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new RuntimeException('Nobitex returned an invalid market payload.');
        }

        $irt = match ($side) {
            UsdtRateSide::Buy => $this->bookPrice($payload['asks'] ?? null),
            UsdtRateSide::Sell => $this->bookPrice($payload['bids'] ?? null),
            UsdtRateSide::Last => $this->scalarPrice($payload['lastTradePrice'] ?? null),
        };
        $lastUpdate = $payload['lastUpdate'] ?? null;
        if ((! is_int($lastUpdate) && ! is_string($lastUpdate)) || ! ctype_digit((string) $lastUpdate)) {
            throw new RuntimeException('Nobitex market timestamp is invalid.');
        }
        $milliseconds = (int) $lastUpdate;
        if ($milliseconds < 1_000_000_000_000) {
            throw new RuntimeException('Nobitex market timestamp is invalid.');
        }
        $fetchedAt = (new DateTimeImmutable('@'.intdiv($milliseconds, 1000)))->setTimezone(new DateTimeZone('UTC'));
        $rateIrr = UsdtDecimal::rate(bcmul($irt, '10', 8));

        return new UsdtRate($this->code(), $rateIrr, $fetchedAt, hash('sha256', $body));
    }

    private function bookPrice(mixed $book): string
    {
        if (! is_array($book) || ! isset($book[0]) || ! is_array($book[0]) || ! array_key_exists(0, $book[0])) {
            throw new RuntimeException('Nobitex order book is invalid.');
        }

        return $this->scalarPrice($book[0][0]);
    }

    private function scalarPrice(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Nobitex market price is invalid.');
        }

        return UsdtDecimal::rate((string) $value);
    }
}
