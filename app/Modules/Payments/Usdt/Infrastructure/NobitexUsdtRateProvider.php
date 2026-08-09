<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\UsdtDecimal;
use App\Modules\Payments\Usdt\Domain\UsdtRate;
use App\Modules\Payments\Usdt\Domain\UsdtRateProvider;
use App\Modules\Payments\Usdt\Domain\UsdtRateSide;
use App\Shared\Application\Clock;
use DateTimeZone;
use Illuminate\Http\Client\Factory;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final readonly class NobitexUsdtRateProvider implements UsdtRateProvider
{
    public const ENDPOINT = 'https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls';

    public function __construct(
        private Factory $http,
        private Clock $clock,
        private int $timeoutSeconds = 4,
        private int $connectTimeoutSeconds = 2,
        private int $maxResponseBytes = 65_536,
    ) {}

    public function code(): string
    {
        return 'nobitex';
    }

    /** @requirement USDT-002 SEC-001 SEC-002 */
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
        $stats = is_array($payload) ? ($payload['stats'] ?? null) : null;
        $market = is_array($stats) ? ($stats['usdt-rls'] ?? null) : null;
        if (($payload['status'] ?? null) !== 'ok' || ! is_array($market)) {
            throw new RuntimeException('Nobitex returned an invalid market payload.');
        }

        $rateIrr = match ($side) {
            UsdtRateSide::Buy => $this->scalarPrice($market['bestSell'] ?? null),
            UsdtRateSide::Sell => $this->scalarPrice($market['bestBuy'] ?? null),
            UsdtRateSide::Last => $this->scalarPrice($market['latest'] ?? null),
        };
        $fetchedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return new UsdtRate($this->code(), $rateIrr, $fetchedAt, hash('sha256', $body));
    }

    private function scalarPrice(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Nobitex market price is invalid.');
        }

        return UsdtDecimal::rate((string) $value);
    }
}
