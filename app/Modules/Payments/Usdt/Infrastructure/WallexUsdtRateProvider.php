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

final readonly class WallexUsdtRateProvider implements UsdtRateProvider
{
    public const ENDPOINT = 'https://api.wallex.ir/hector/web/v1/markets';

    public function __construct(
        private Factory $http,
        private Clock $clock,
        private int $timeoutSeconds = 4,
        private int $connectTimeoutSeconds = 2,
        private int $maxResponseBytes = 65_536,
    ) {}

    public function code(): string
    {
        return 'wallex';
    }

    /** @requirement USDT-002 SEC-001 SEC-002 */
    public function fetch(UsdtRateSide $side): UsdtRate
    {
        unset($side);

        $response = $this->http
            ->withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'on_headers' => function (ResponseInterface $response): void {
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxResponseBytes) {
                        throw new RuntimeException('Wallex response exceeds the configured size limit.');
                    }
                },
            ])
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->get(self::ENDPOINT);

        if (! $response->successful()) {
            throw new RuntimeException('Wallex public market request failed.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxResponseBytes) {
            throw new RuntimeException('Wallex response exceeds the configured size limit.');
        }

        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['success'] ?? null) !== true) {
            throw new RuntimeException('Wallex returned an invalid market payload.');
        }
        $result = $payload['result'] ?? null;
        $markets = is_array($result) ? ($result['markets'] ?? null) : null;
        if (! is_array($markets)) {
            throw new RuntimeException('Wallex returned an invalid market payload.');
        }

        $market = null;
        foreach ($markets as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            if (($candidate['symbol'] ?? null) === 'USDTTMN'
                && ($candidate['base_asset'] ?? null) === 'USDT'
                && ($candidate['quote_asset'] ?? null) === 'TMN'
                && ($candidate['is_spot'] ?? null) === true
                && ($candidate['is_tmn_based'] ?? null) === true) {
                $market = $candidate;
                break;
            }
        }
        if ($market === null) {
            throw new RuntimeException('Wallex USDTTMN spot market is unavailable.');
        }

        $rateIrr = $this->tomanToIrr($market['price'] ?? null);
        $fetchedAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        return new UsdtRate($this->code(), $rateIrr, $fetchedAt, hash('sha256', $body));
    }

    private function tomanToIrr(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Wallex USDTTMN price is invalid.');
        }

        $raw = (string) $value;
        if (preg_match('/\A(0|[1-9][0-9]{0,18})(?:\.([0-9]+))?\z/', $raw, $matches) !== 1) {
            throw new RuntimeException('Wallex USDTTMN price is invalid.');
        }

        $fraction = rtrim($matches[2] ?? '', '0');
        if (strlen($fraction) > 9) {
            throw new RuntimeException('Wallex USDTTMN price exceeds supported fixed precision.');
        }
        $canonicalToman = $matches[1].($fraction === '' ? '' : '.'.$fraction);
        $irr = bcmul($canonicalToman, '10', 8);

        return UsdtDecimal::rate($irr);
    }
}
