<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

use App\Modules\Orders\Application\QuoteService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * @phpstan-type UsdtAmountQuoteRow object{
 *     id:int|string,
 *     public_id:string,
 *     quote_key:string,
 *     request_payload_hash:string,
 *     source_quote_public_id:string,
 *     user_id:int|string,
 *     network:string,
 *     destination_address:string,
 *     destination_wallet_version:int|string,
 *     rate_source:string,
 *     raw_rate_irr:string,
 *     margin_bps:int|string,
 *     final_rate_irr:string,
 *     order_amount_irr:int|string,
 *     exact_usdt:string,
 *     rounding_precision:int|string,
 *     rate_fetched_at:string,
 *     expires_at:string,
 *     provider_response_hash:string,
 *     configuration_snapshot_hash:string
 * }
 */
final readonly class UsdtAmountQuoteService
{
    public function __construct(
        private DatabaseManager $database,
        private QuoteService $quotes,
        private UsdtDestinationWalletService $destinations,
        private UsdtRateResolver $rates,
        private Clock $clock,
        private int $marginBps = 0,
        private int $roundingPrecision = 6,
        private int $validitySeconds = 900,
        private int $rateMaxAgeSeconds = 120,
        private string $destinationWalletCode = 'primary',
    ) {
        if ($marginBps < 0 || $marginBps >= 10_000 || $roundingPrecision < 0 || $roundingPrecision > 6 || $validitySeconds < 1 || $validitySeconds > 3600 || $rateMaxAgeSeconds < 1 || $rateMaxAgeSeconds > 3600) {
            throw new InvalidArgumentException('USDT amount quote policy is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_-]{1,63}\z/', $destinationWalletCode) !== 1) {
            throw new InvalidArgumentException('USDT destination wallet code is invalid.');
        }
    }

    /** @requirement USDT-001 USDT-002 DAT-002 DAT-003 */
    public function create(string $quoteKey, string $sourceQuotePublicId): UsdtAmountQuoteReceipt
    {
        $this->assertKey($quoteKey);
        if (! Str::isUlid($sourceQuotePublicId)) {
            throw new InvalidArgumentException('USDT amount quote request is invalid.');
        }

        $requestHash = hash('sha256', json_encode([
            'source_quote_public_id' => $sourceQuotePublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $connection = $this->database->connection();
        /** @var UsdtAmountQuoteRow|null $existing */
        $existing = $connection->table('usdt_amount_quotes')->where('quote_key', $quoteKey)->first();
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $requestHash);
        }

        $source = $this->quotes->current($sourceQuotePublicId);
        if ($source->currency !== 'IRR' || $source->finalPriceIrr <= 0) {
            throw new RuntimeException('USDT amount quote requires a positive IRR source quote.');
        }
        $destination = $this->destinations->current($this->destinationWalletCode);
        $rate = $this->rates->resolve();
        $rawRate = UsdtDecimal::rate($rate->rateIrr);
        $finalRate = UsdtDecimal::applyMargin($rawRate, $this->marginBps);
        $exactUsdt = UsdtDecimal::roundUpIrrToUsdt($source->finalPriceIrr, $finalRate, $this->roundingPrecision);
        $utc = new DateTimeZone('UTC');
        $now = $this->clock->now()->setTimezone($utc);
        $rateFetchedAt = $rate->fetchedAt->setTimezone($utc);
        $expiresAt = $this->earliest(
            $source->expiresAt->setTimezone($utc),
            $now->modify('+'.$this->validitySeconds.' seconds'),
            $rateFetchedAt->modify('+'.$this->rateMaxAgeSeconds.' seconds'),
        );
        if ($expiresAt <= $now) {
            throw new RuntimeException('USDT amount quote would already be expired.');
        }

        $snapshot = json_encode([
            'destination_address' => $destination->address,
            'destination_configuration_hash' => $destination->configurationSnapshotHash,
            'destination_wallet_code' => $destination->walletCode,
            'destination_wallet_version' => $destination->version,
            'exact_usdt' => $exactUsdt,
            'final_rate_irr' => $finalRate,
            'formula_version' => 'usdt-quote-v1',
            'margin_bps' => $this->marginBps,
            'network' => UsdtDestinationWalletService::NETWORK,
            'order_amount_irr' => $source->finalPriceIrr,
            'provider_response_hash' => $rate->responseHash,
            'rate_source' => $rate->source,
            'raw_rate_irr' => $rawRate,
            'rounding_precision' => $this->roundingPrecision,
            'rate_max_age_seconds' => $this->rateMaxAgeSeconds,
            'quote_validity_seconds' => $this->validitySeconds,
            'rate_fetched_at' => $rateFetchedAt->format('Y-m-d H:i:s.u'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'source_quote_public_id' => $source->quotePublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', $snapshot);
        $row = [
            'public_id' => (string) Str::ulid(),
            'quote_key' => $quoteKey,
            'request_payload_hash' => $requestHash,
            'source_quote_id' => $source->quoteId,
            'source_quote_public_id' => $source->quotePublicId,
            'user_id' => $source->userId,
            'destination_wallet_version_id' => $destination->versionId,
            'destination_wallet_code' => $destination->walletCode,
            'destination_wallet_version' => $destination->version,
            'destination_address' => $destination->address,
            'network' => UsdtDestinationWalletService::NETWORK,
            'destination_configuration_hash' => $destination->configurationSnapshotHash,
            'rate_source' => $rate->source,
            'raw_rate_irr' => $rawRate,
            'margin_bps' => $this->marginBps,
            'final_rate_irr' => $finalRate,
            'order_amount_irr' => $source->finalPriceIrr,
            'exact_usdt' => $exactUsdt,
            'rounding_precision' => $this->roundingPrecision,
            'rate_max_age_seconds' => $this->rateMaxAgeSeconds,
            'quote_validity_seconds' => $this->validitySeconds,
            'rate_fetched_at' => $rateFetchedAt->format('Y-m-d H:i:s.u'),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
            'provider_response_hash' => $rate->responseHash,
            'configuration_snapshot' => $snapshot,
            'configuration_snapshot_hash' => $snapshotHash,
            'created_at' => $now->format('Y-m-d H:i:s.u'),
        ];

        try {
            $id = (int) $connection->table('usdt_amount_quotes')->insertGetId($row);
        } catch (QueryException $exception) {
            /** @var UsdtAmountQuoteRow|null $raced */
            $raced = $connection->table('usdt_amount_quotes')->where('quote_key', $quoteKey)->first();
            if ($raced !== null) {
                return $this->replayOrConflict($raced, $requestHash);
            }
            throw $exception;
        }
        /** @var UsdtAmountQuoteRow|null $stored */
        $stored = $connection->table('usdt_amount_quotes')->where('id', $id)->first();
        if ($stored === null) {
            throw new RuntimeException('USDT amount quote could not be loaded.');
        }

        return $this->receipt($stored, false);
    }

    /** @param UsdtAmountQuoteRow $row */
    private function replayOrConflict(object $row, string $requestHash): UsdtAmountQuoteReceipt
    {
        if ((string) $row->request_payload_hash !== $requestHash) {
            throw new RuntimeException('USDT amount quote key conflict.');
        }

        return $this->receipt($row, true);
    }

    /** @param UsdtAmountQuoteRow $row */
    private function receipt(object $row, bool $replayed): UsdtAmountQuoteReceipt
    {
        return new UsdtAmountQuoteReceipt(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->quote_key,
            (string) $row->source_quote_public_id,
            (int) $row->user_id,
            (string) $row->network,
            (string) $row->destination_address,
            (int) $row->destination_wallet_version,
            (string) $row->rate_source,
            (string) $row->raw_rate_irr,
            (int) $row->margin_bps,
            (string) $row->final_rate_irr,
            (int) $row->order_amount_irr,
            (string) $row->exact_usdt,
            (int) $row->rounding_precision,
            new DateTimeImmutable((string) $row->rate_fetched_at, new DateTimeZone('UTC')),
            new DateTimeImmutable((string) $row->expires_at, new DateTimeZone('UTC')),
            (string) $row->provider_response_hash,
            (string) $row->configuration_snapshot_hash,
            $replayed,
        );
    }

    private function assertKey(string $quoteKey): void
    {
        if (strlen($quoteKey) < 8 || strlen($quoteKey) > 128 || preg_match('/\A[a-zA-Z0-9._:-]+\z/', $quoteKey) !== 1) {
            throw new InvalidArgumentException('USDT amount quote key is invalid.');
        }
    }

    private function earliest(DateTimeImmutable ...$values): DateTimeImmutable
    {
        $earliest = $values[0];
        foreach ($values as $value) {
            if ($value < $earliest) {
                $earliest = $value;
            }
        }

        return $earliest;
    }
}
