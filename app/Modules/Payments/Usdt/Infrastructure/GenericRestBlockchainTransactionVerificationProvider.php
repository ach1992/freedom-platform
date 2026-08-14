<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Infrastructure;

use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationEvidence;
use App\Modules\Payments\Usdt\Application\Contracts\UsdtBlockchainVerificationRequest;
use App\Modules\Payments\Usdt\Application\UsdtTokenAmount;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bounded read-only blockchain lookup adapter.
 * Mappings are direct JSON object keys only; no JSONPath, templates, scripts, SQL, shell, or dynamic code are supported.
 */
final class GenericRestBlockchainTransactionVerificationProvider implements BlockchainTransactionVerificationProvider
{
    /** @var array<string,string> */
    private array $fieldMap;

    /** @var array<string,string> */
    private array $outcomeMap;

    /** @var array<string,string> */
    private array $statusMap;

    /** @var list<string> */
    private array $allowedHosts;

    private Closure $resolver;

    private string $host;

    private int $port;

    /**
     * @param  array<string,string>  $fieldMap  canonical keys: event_id,outcome,status,txid,network,chain_id,token_contract,destination_address,amount_base_units,token_decimals,confirmations,block_number,transaction_at,observed_at
     * @param  array<string,string>  $outcomeMap  provider value => success|pending|rejected|uncertain|unavailable
     * @param  array<string,string>  $statusMap  provider value => success|pending|failed|reverted|not_found|unknown
     * @param  list<string>  $allowedHosts
     * @param  null|callable(string):list<string>  $resolver
     */
    public function __construct(
        private readonly string $providerCode,
        private readonly string $baseUrl,
        private readonly string $lookupPath,
        private readonly string $txidParameter,
        array $fieldMap,
        array $outcomeMap,
        array $statusMap,
        array $allowedHosts,
        private readonly string $dateFormat = DATE_ATOM,
        private readonly string $providerTimezone = 'UTC',
        private readonly string $authType = 'none',
        private readonly ?string $credential = null,
        private readonly string $apiKeyHeader = 'X-API-Key',
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBodyBytes = 262144,
        ?callable $resolver = null,
    ) {
        $this->assertToken($providerCode, 'Generic chain provider code', 2, 64);
        $this->assertDirectKey($txidParameter, 'Generic chain TXID parameter');
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30 || $maxBodyBytes < 1024 || $maxBodyBytes > 2_097_152) {
            throw new DomainException('Generic chain HTTP limits are invalid.');
        }
        new DateTimeZone($providerTimezone);

        $url = parse_url($baseUrl);
        if (! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ! isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new DomainException('Generic chain base URL must be a credential-free HTTPS origin/path.');
        }
        if ($lookupPath === '' || $lookupPath[0] !== '/' || str_contains($lookupPath, '..') || str_contains($lookupPath, '?') || str_contains($lookupPath, '#')) {
            throw new DomainException('Generic chain lookup path is invalid.');
        }
        $this->host = strtolower((string) $url['host']);
        $this->port = isset($url['port']) ? (int) $url['port'] : 443;
        if ($this->port < 1 || $this->port > 65535) {
            throw new DomainException('Generic chain HTTPS port is invalid.');
        }

        $this->allowedHosts = array_values(array_unique(array_map(static fn (string $host): string => strtolower(trim($host)), $allowedHosts)));
        if ($this->allowedHosts === [] || ! in_array($this->host, $this->allowedHosts, true)) {
            throw new DomainException('Generic chain host is not allowlisted.');
        }
        foreach ($this->allowedHosts as $allowedHost) {
            if ($allowedHost === '' || preg_match('/\A[a-z0-9.-]+\z/', $allowedHost) !== 1) {
                throw new DomainException('Generic chain host allowlist is invalid.');
            }
        }

        foreach (['outcome', 'status', 'txid', 'observed_at'] as $canonical) {
            if (! isset($fieldMap[$canonical])) {
                throw new DomainException('Generic chain field mapping is incomplete.');
            }
        }
        $supported = ['event_id', 'outcome', 'status', 'txid', 'network', 'chain_id', 'token_contract', 'destination_address', 'amount_base_units', 'token_decimals', 'confirmations', 'block_number', 'transaction_at', 'observed_at'];
        foreach ($fieldMap as $canonical => $providerField) {
            if (! in_array($canonical, $supported, true)) {
                throw new DomainException('Generic chain field mapping contains an unsupported canonical field.');
            }
            $this->assertDirectKey($providerField, 'Generic chain provider field');
        }
        $this->fieldMap = $fieldMap;

        if ($outcomeMap === [] || $statusMap === []) {
            throw new DomainException('Generic chain outcome/status maps cannot be empty.');
        }
        foreach ($outcomeMap as $providerValue => $canonical) {
            if ($providerValue === '' || strlen($providerValue) > 64 || ! in_array($canonical, ['success', 'pending', 'rejected', 'uncertain', 'unavailable'], true)) {
                throw new DomainException('Generic chain outcome mapping is invalid.');
            }
        }
        foreach ($statusMap as $providerValue => $canonical) {
            if ($providerValue === '' || strlen($providerValue) > 64 || ! in_array($canonical, ['success', 'pending', 'failed', 'reverted', 'not_found', 'unknown'], true)) {
                throw new DomainException('Generic chain status mapping is invalid.');
            }
        }
        $this->outcomeMap = $outcomeMap;
        $this->statusMap = $statusMap;

        if (! in_array($authType, ['none', 'bearer', 'api_key'], true)) {
            throw new DomainException('Generic chain authentication type is unsupported.');
        }
        if ($authType !== 'none' && ($credential === null || $credential === '' || strlen($credential) > 4096)) {
            throw new DomainException('Generic chain authentication credential is missing or invalid.');
        }
        if ($authType === 'api_key') {
            $this->assertHeaderName($apiKeyHeader);
        }

        $this->resolver = $resolver === null ? Closure::fromCallable([$this, 'resolveHost']) : Closure::fromCallable($resolver);
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function lookup(UsdtBlockchainVerificationRequest $request): UsdtBlockchainVerificationEvidence
    {
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $request->txid) !== 1) {
            throw new DomainException('Generic chain lookup TXID is invalid.');
        }
        UsdtTokenAmount::normalizeBaseUnits($request->expectedAmountBaseUnits);

        $addresses = ($this->resolver)($this->host);
        if (! is_array($addresses) || $addresses === []) {
            throw new RuntimeException('Generic chain host did not resolve to a usable address.');
        }
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                throw new RuntimeException('Generic chain host resolved to a private/reserved address.');
            }
        }
        $pinnedAddress = $addresses[0];

        $headers = ['Accept' => 'application/json'];
        if ($this->authType === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$this->credential;
        } elseif ($this->authType === 'api_key') {
            $headers[$this->apiKeyHeader] = (string) $this->credential;
        }

        $response = Http::withHeaders($headers)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$this->host.':'.$this->port.':'.$pinnedAddress]],
                'on_headers' => function ($response): void {
                    $contentType = strtolower($response->getHeaderLine('Content-Type'));
                    if ($contentType === '' || (! str_contains($contentType, 'application/json') && ! str_contains($contentType, '+json'))) {
                        throw new RuntimeException('Generic chain provider returned a non-JSON content type.');
                    }
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxBodyBytes) {
                        throw new RuntimeException('Generic chain provider response exceeds the configured size limit.');
                    }
                },
            ])
            ->get(rtrim($this->baseUrl, '/').$this->lookupPath, [$this->txidParameter => $request->txid]);

        if (! $response->successful()) {
            throw new RuntimeException('Generic chain provider request failed with HTTP '.$response->status().'.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxBodyBytes) {
            throw new RuntimeException('Generic chain provider response exceeds the configured size limit.');
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Generic chain provider response is malformed JSON.', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Generic chain provider response must be a JSON object.');
        }

        $evidenceHash = hash('sha256', json_encode($this->canonicalize($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $providerOutcome = $this->requiredString($decoded, 'outcome', 1, 64);
        $providerStatus = $this->requiredString($decoded, 'status', 1, 64);
        $outcome = $this->outcomeMap[$providerOutcome] ?? null;
        $status = $this->statusMap[$providerStatus] ?? null;
        if ($outcome === null || $status === null) {
            throw new RuntimeException('Generic chain provider outcome/status is unmapped.');
        }
        $txid = strtolower($this->requiredString($decoded, 'txid', 66, 66));
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1 || ! hash_equals($request->txid, $txid)) {
            throw new RuntimeException('Generic chain provider returned a conflicting TXID.');
        }
        $network = $this->optionalString($decoded, 'network', 1, 16);
        $tokenContract = $this->optionalAddress($decoded, 'token_contract');
        $destination = $this->optionalAddress($decoded, 'destination_address');
        $chainId = $this->optionalInteger($decoded, 'chain_id', 1, 1_000_000);
        $amountBaseUnits = $this->optionalUnsignedDecimalString($decoded, 'amount_base_units');
        $tokenDecimals = $this->optionalInteger($decoded, 'token_decimals', 0, 36);
        $confirmations = $this->optionalInteger($decoded, 'confirmations', 0, 10_000_000);
        $blockNumber = $this->optionalInteger($decoded, 'block_number', 0, PHP_INT_MAX);
        $transactionAtRaw = $this->optionalString($decoded, 'transaction_at', 1, 128);
        $observedAt = $this->date($this->requiredString($decoded, 'observed_at', 1, 128));
        $transactionAt = $transactionAtRaw === null ? null : $this->date($transactionAtRaw);
        $eventId = $this->optionalString($decoded, 'event_id', 1, 191)
            ?? hash('sha256', $this->providerCode."\0".$txid."\0".$evidenceHash);

        if ($outcome === 'success' && $status === 'success'
            && ($network === null || $chainId === null || $tokenContract === null || $destination === null
                || $amountBaseUnits === null || $tokenDecimals === null || $confirmations === null || $transactionAt === null)) {
            throw new RuntimeException('Generic chain successful response lacks authoritative transaction identity.');
        }

        return new UsdtBlockchainVerificationEvidence(
            $outcome,
            $status,
            $eventId,
            $txid,
            $network,
            $chainId,
            $tokenContract,
            $destination,
            $amountBaseUnits,
            $tokenDecimals,
            $confirmations,
            $blockNumber,
            $transactionAt,
            $observedAt,
            $evidenceHash,
            ['provider' => $this->providerCode],
        );
    }

    /** @param array<string,mixed> $row */
    private function requiredString(array $row, string $canonical, int $min, int $max): string
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || (! is_string($row[$field]) && ! is_int($row[$field]))) {
            throw new RuntimeException('Generic chain required field '.$canonical.' is missing or invalid.');
        }
        $value = trim((string) $row[$field]);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic chain required field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function optionalString(array $row, string $canonical, int $min, int $max): ?string
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        if (! is_string($row[$field]) && ! is_int($row[$field])) {
            throw new RuntimeException('Generic chain optional field '.$canonical.' is invalid.');
        }
        $value = trim((string) $row[$field]);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic chain optional field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function optionalInteger(array $row, string $canonical, int $minimum, int $maximum): ?int
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        $value = $row[$field];
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $maxText = (string) $maximum;
            if (strlen($normalized) > strlen($maxText) || (strlen($normalized) === strlen($maxText) && strcmp($normalized, $maxText) > 0)) {
                throw new RuntimeException('Generic chain integer field '.$canonical.' exceeds the supported range.');
            }
            $integer = (int) $normalized;
        } else {
            throw new RuntimeException('Generic chain integer field '.$canonical.' must be an integer, never a float.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new RuntimeException('Generic chain integer field '.$canonical.' is out of range.');
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private function optionalUnsignedDecimalString(array $row, string $canonical): ?string
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        $value = $row[$field];
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException('Generic chain raw amount must be positive.');
            }

            return (string) $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException('Generic chain raw amount must be an integer string, never a float.');
        }
        try {
            return UsdtTokenAmount::normalizeBaseUnits($value);
        } catch (DomainException $exception) {
            throw new RuntimeException('Generic chain raw amount is invalid.', 0, $exception);
        }
    }

    /** @param array<string,mixed> $row */
    private function optionalAddress(array $row, string $canonical): ?string
    {
        $value = $this->optionalString($row, $canonical, 42, 42);
        if ($value === null) {
            return null;
        }
        $value = strtolower($value);
        if (preg_match('/\A0x[a-f0-9]{40}\z/', $value) !== 1) {
            throw new RuntimeException('Generic chain address field '.$canonical.' is invalid.');
        }

        return $value;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($this->dateFormat, $value, new DateTimeZone($this->providerTimezone));
        if ($date === false) {
            throw new RuntimeException('Generic chain provider timestamp is invalid.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        ksort($value, SORT_STRING);

        return $value;
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function assertDirectKey(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/', $value) !== 1) {
            throw new DomainException($label.' must be a direct JSON object key.');
        }
    }

    private function assertHeaderName(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $value) !== 1
            || in_array(strtolower($value), ['host', 'content-length', 'connection', 'transfer-encoding'], true)) {
            throw new DomainException('Generic chain API-key header name is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $min, int $max): void
    {
        $length = strlen($value);
        if ($length < $min || $length > $max || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
