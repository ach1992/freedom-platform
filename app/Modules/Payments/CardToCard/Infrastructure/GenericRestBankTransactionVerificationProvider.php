<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Infrastructure;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionObservation;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionPage;
use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionVerificationProvider;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

/**
 * Generic REST polling adapter with deliberately bounded configuration.
 * Field mappings are direct object keys or a tiny data-only JSON-path subset; templates, code, SQL, and shell evaluation are unsupported.
 */
final class GenericRestBankTransactionVerificationProvider implements BankTransactionVerificationProvider
{
    private const MAX_MAPPING_PATH_LENGTH = 256;

    private const MAX_MAPPING_PATH_DEPTH = 12;

    private const MAX_MAPPING_ARRAY_INDEX = 255;

    private const MAX_MAPPING_SCALAR_BYTES = 4096;

    /** @var array<string, string> */
    private array $fieldMap;

    /** @var array<string, list<int|string>|null> */
    private array $fieldPaths;

    /** @var array<string, string> */
    private array $statusMap;

    /** @var list<string> */
    private array $allowedHosts;

    private Closure $resolver;

    private string $host;

    private int $port;

    /**
     * @param  array<string, string>  $fieldMap  canonical keys: transaction_id, event_id, destination_card, amount, status, occurred_at, sender_card, sender_name, reference
     * @param  array<string, string>  $statusMap  provider value => pending|settled|reversed|failed
     * @param  list<string>  $allowedHosts
     * @param  null|callable(string):list<string>  $resolver
     */
    public function __construct(
        private readonly string $providerCode,
        private readonly string $baseUrl,
        private readonly string $pullPath,
        array $fieldMap,
        array $statusMap,
        array $allowedHosts,
        private readonly string $transactionsKey = 'transactions',
        private readonly string $nextCursorKey = 'next_cursor',
        private readonly string $cursorParameter = 'cursor',
        private readonly string $amountUnit = 'IRR',
        private readonly string $dateFormat = DATE_ATOM,
        private readonly string $providerTimezone = 'UTC',
        private readonly string $authType = 'none',
        private readonly ?string $credential = null,
        private readonly string $apiKeyHeader = 'X-API-Key',
        private readonly int $timeoutSeconds = 8,
        private readonly int $maxBodyBytes = 262144,
        ?callable $resolver = null,
    ) {
        $this->assertToken($providerCode, 'Generic bank provider code', 2, 64);
        $this->assertDirectKey($transactionsKey, 'Generic bank transactions key');
        $this->assertDirectKey($nextCursorKey, 'Generic bank next-cursor key');
        $this->assertDirectKey($cursorParameter, 'Generic bank cursor parameter');
        if (! in_array($amountUnit, ['IRR', 'TOMAN'], true)) {
            throw new DomainException('Generic bank amount unit must be IRR or TOMAN.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30 || $maxBodyBytes < 1024 || $maxBodyBytes > 2_097_152) {
            throw new DomainException('Generic bank HTTP limits are invalid.');
        }
        new DateTimeZone($providerTimezone);

        $url = parse_url($baseUrl);
        if (! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ! isset($url['host'])
            || isset($url['user'])
            || isset($url['pass'])
            || isset($url['query'])
            || isset($url['fragment'])) {
            throw new DomainException('Generic bank base URL must be a credential-free HTTPS origin/path.');
        }
        if ($pullPath === '' || $pullPath[0] !== '/' || str_contains($pullPath, '..') || str_contains($pullPath, '?') || str_contains($pullPath, '#')) {
            throw new DomainException('Generic bank pull path is invalid.');
        }

        $this->host = strtolower((string) $url['host']);
        $this->port = isset($url['port']) ? (int) $url['port'] : 443;
        if ($this->port < 1 || $this->port > 65535) {
            throw new DomainException('Generic bank HTTPS port is invalid.');
        }

        $this->allowedHosts = array_values(array_unique(array_map(static fn (string $host): string => strtolower(trim($host)), $allowedHosts)));
        if ($this->allowedHosts === [] || ! in_array($this->host, $this->allowedHosts, true)) {
            throw new DomainException('Generic bank host is not allowlisted.');
        }
        foreach ($this->allowedHosts as $allowedHost) {
            if ($allowedHost === '' || preg_match('/\A[a-z0-9.-]+\z/', $allowedHost) !== 1) {
                throw new DomainException('Generic bank host allowlist is invalid.');
            }
        }

        $required = ['transaction_id', 'destination_card', 'amount', 'status', 'occurred_at'];
        foreach ($required as $canonical) {
            if (! isset($fieldMap[$canonical])) {
                throw new DomainException('Generic bank field mapping is incomplete.');
            }
        }
        $supported = ['transaction_id', 'event_id', 'destination_card', 'amount', 'status', 'occurred_at', 'sender_card', 'sender_name', 'reference'];
        $fieldPaths = [];
        foreach ($fieldMap as $canonical => $providerField) {
            if (! in_array($canonical, $supported, true)) {
                throw new DomainException('Generic bank field mapping contains an unsupported canonical field.');
            }
            if (! is_string($providerField)) {
                throw new DomainException('Generic bank provider field mapping must be a string.');
            }
            $fieldPaths[$canonical] = $this->isDirectKey($providerField)
                ? null
                : $this->parseFieldPath($providerField);
        }
        $this->fieldMap = $fieldMap;
        $this->fieldPaths = $fieldPaths;

        if ($statusMap === []) {
            throw new DomainException('Generic bank status map cannot be empty.');
        }
        foreach ($statusMap as $providerStatus => $canonicalStatus) {
            if ($providerStatus === '' || strlen($providerStatus) > 64 || ! in_array($canonicalStatus, ['pending', 'settled', 'reversed', 'failed'], true)) {
                throw new DomainException('Generic bank status mapping is invalid.');
            }
        }
        $this->statusMap = $statusMap;

        if (! in_array($authType, ['none', 'bearer', 'api_key'], true)) {
            throw new DomainException('Generic bank authentication type is unsupported.');
        }
        if ($authType !== 'none' && ($credential === null || $credential === '' || strlen($credential) > 4096)) {
            throw new DomainException('Generic bank authentication credential is missing or invalid.');
        }
        if ($authType === 'api_key') {
            $this->assertHeaderName($apiKeyHeader);
        }

        $this->resolver = $resolver === null
            ? Closure::fromCallable([$this, 'resolveHost'])
            : Closure::fromCallable($resolver);
    }

    public function code(): string
    {
        return $this->providerCode;
    }

    public function fetch(?string $cursor): BankTransactionPage
    {
        if ($cursor !== null && (strlen($cursor) > 191 || preg_match('/[\x00-\x1F\x7F]/', $cursor) === 1)) {
            throw new DomainException('Generic bank cursor is invalid.');
        }

        $addresses = ($this->resolver)($this->host);
        if (! is_array($addresses) || $addresses === []) {
            throw new RuntimeException('Generic bank host did not resolve to a usable address.');
        }
        $publicAddresses = [];
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                throw new RuntimeException('Generic bank host resolved to a private/reserved address.');
            }
            $publicAddresses[] = $address;
        }
        $pinnedAddress = $publicAddresses[0];

        $url = rtrim($this->baseUrl, '/').$this->pullPath;
        $headers = ['Accept' => 'application/json'];
        if ($this->authType === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$this->credential;
        } elseif ($this->authType === 'api_key') {
            $headers[$this->apiKeyHeader] = (string) $this->credential;
        }

        $query = [];
        if ($cursor !== null) {
            $query[$this->cursorParameter] = $cursor;
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
                        throw new RuntimeException('Generic bank provider returned a non-JSON content type.');
                    }
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxBodyBytes) {
                        throw new RuntimeException('Generic bank provider response exceeds the configured size limit.');
                    }
                },
            ])
            ->get($url, $query);

        if (! $response->successful()) {
            throw new RuntimeException('Generic bank provider request failed with HTTP '.$response->status().'.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxBodyBytes) {
            throw new RuntimeException('Generic bank provider response exceeds the configured size limit.');
        }

        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Generic bank provider response is malformed JSON.', previous: $exception);
        }
        if (! $decoded instanceof stdClass
            || ! property_exists($decoded, $this->transactionsKey)
            || ! is_array($decoded->{$this->transactionsKey})) {
            throw new RuntimeException('Generic bank provider response does not contain a transaction list.');
        }
        $transactions = $decoded->{$this->transactionsKey};
        if (count($transactions) > 1000) {
            throw new RuntimeException('Generic bank provider returned too many transactions in one page.');
        }

        $observations = [];
        foreach ($transactions as $index => $item) {
            if (! $item instanceof stdClass) {
                throw new RuntimeException('Generic bank transaction entry is not an object at index '.$index.'.');
            }
            $observations[] = $this->normalizeTransaction(get_object_vars($item));
        }

        $nextCursor = property_exists($decoded, $this->nextCursorKey)
            ? $decoded->{$this->nextCursorKey}
            : null;
        if ($nextCursor !== null && (! is_string($nextCursor) || strlen($nextCursor) > 191 || preg_match('/[\x00-\x1F\x7F]/', $nextCursor) === 1)) {
            throw new RuntimeException('Generic bank provider next cursor is invalid.');
        }

        return new BankTransactionPage($observations, $nextCursor);
    }

    /** @param array<string, mixed> $row */
    private function normalizeTransaction(array $row): BankTransactionObservation
    {
        $transactionId = $this->requiredScalarString($row, 'transaction_id', 1, 191);
        $destination = preg_replace('/\D+/', '', $this->requiredScalarString($row, 'destination_card', 12, 32));
        if ($destination === null || strlen($destination) < 12 || strlen($destination) > 24) {
            throw new RuntimeException('Generic bank destination card is invalid.');
        }

        [, $amountValue] = $this->mappedValue($row, 'amount');
        $amount = $this->amountIrr($amountValue);
        $providerStatus = $this->requiredScalarString($row, 'status', 1, 64);
        $status = $this->statusMap[$providerStatus] ?? null;
        if ($status === null) {
            throw new RuntimeException('Generic bank transaction status is unmapped.');
        }
        $occurredAt = $this->date($this->requiredScalarString($row, 'occurred_at', 1, 128));
        $senderCard = $this->optionalScalarString($row, 'sender_card', 1, 32);
        if ($senderCard !== null) {
            $senderCard = preg_replace('/\D+/', '', $senderCard);
            if ($senderCard === null || strlen($senderCard) < 4 || strlen($senderCard) > 24) {
                throw new RuntimeException('Generic bank sender card is invalid.');
            }
        }
        $senderName = $this->optionalScalarString($row, 'sender_name', 1, 191);
        $reference = $this->optionalScalarString($row, 'reference', 1, 191);

        $canonical = $this->canonicalize($row);
        $evidenceHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $eventId = $this->optionalScalarString($row, 'event_id', 1, 191)
            ?? hash('sha256', $transactionId."\0".$status."\0".$evidenceHash);

        return new BankTransactionObservation(
            $transactionId,
            $eventId,
            $destination,
            $amount,
            $status,
            $occurredAt,
            $senderCard,
            $senderName,
            $reference,
            $evidenceHash,
        );
    }

    /** @param array<string, mixed> $row */
    private function requiredScalarString(array $row, string $canonical, int $min, int $max): string
    {
        [$found, $rawValue] = $this->mappedValue($row, $canonical);
        if (! $found || (! is_string($rawValue) && ! is_int($rawValue))) {
            throw new RuntimeException('Generic bank required field '.$canonical.' is missing or invalid.');
        }
        $value = trim((string) $rawValue);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic bank required field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function optionalScalarString(array $row, string $canonical, int $min, int $max): ?string
    {
        if (! isset($this->fieldMap[$canonical])) {
            return null;
        }
        [$found, $rawValue, $isPath] = $this->mappedValue($row, $canonical);
        if (! $found || (! $isPath && ($rawValue === null || $rawValue === ''))) {
            return null;
        }
        if (! is_string($rawValue) && ! is_int($rawValue)) {
            throw new RuntimeException('Generic bank optional field '.$canonical.' is invalid.');
        }
        $value = trim((string) $rawValue);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic bank optional field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{bool, mixed, bool}
     */
    private function mappedValue(array $row, string $canonical): array
    {
        $mapping = $this->fieldMap[$canonical] ?? null;
        if ($mapping === null) {
            return [false, null, false];
        }

        $segments = $this->fieldPaths[$canonical] ?? null;
        if ($segments === null) {
            return array_key_exists($mapping, $row)
                ? [true, $row[$mapping], false]
                : [false, null, false];
        }

        $value = $row;
        $atRoot = true;
        foreach ($segments as $segment) {
            if (is_string($segment)) {
                if ($atRoot) {
                    if (! is_array($value) || ! array_key_exists($segment, $value)) {
                        throw new RuntimeException('Generic bank mapped field '.$canonical.' path did not resolve.');
                    }
                    $value = $value[$segment];
                } else {
                    if (! $value instanceof stdClass || ! property_exists($value, $segment)) {
                        throw new RuntimeException('Generic bank mapped field '.$canonical.' path did not resolve.');
                    }
                    $value = $value->{$segment};
                }
            } else {
                if (! is_array($value) || ! array_is_list($value) || ! array_key_exists($segment, $value)) {
                    throw new RuntimeException('Generic bank mapped field '.$canonical.' path did not resolve.');
                }
                $value = $value[$segment];
            }
            $atRoot = false;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException('Generic bank mapped field '.$canonical.' terminal value is not a supported scalar.');
        }
        if (is_string($value) && strlen($value) > self::MAX_MAPPING_SCALAR_BYTES) {
            throw new RuntimeException('Generic bank mapped field '.$canonical.' terminal value exceeds the size limit.');
        }

        return [true, $value, true];
    }

    /** @return list<int|string> */
    private function parseFieldPath(string $path): array
    {
        $length = strlen($path);
        if ($length > self::MAX_MAPPING_PATH_LENGTH) {
            throw new DomainException('Generic bank provider field mapping path exceeds the maximum length.');
        }
        if ($length < 3 || ! str_starts_with($path, '$.')) {
            throw new DomainException('Generic bank provider field mapping path syntax is invalid.');
        }

        $segments = [];
        $offset = 1;
        while ($offset < $length) {
            if ($path[$offset] === '.') {
                $offset++;
                if (preg_match('/\A([A-Za-z_][A-Za-z0-9_-]{0,63})/', substr($path, $offset), $matches) !== 1) {
                    throw new DomainException('Generic bank provider field mapping path syntax is invalid.');
                }
                $key = $matches[1];
                $segments[] = $key;
                $offset += strlen($key);
            } elseif ($path[$offset] === '[') {
                $closing = strpos($path, ']', $offset + 1);
                if ($closing === false) {
                    throw new DomainException('Generic bank provider field mapping path syntax is invalid.');
                }
                $rawIndex = substr($path, $offset + 1, $closing - $offset - 1);
                if (preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/', $rawIndex) !== 1) {
                    throw new DomainException('Generic bank provider field mapping path syntax is invalid.');
                }
                $index = (int) $rawIndex;
                if ($index > self::MAX_MAPPING_ARRAY_INDEX) {
                    throw new DomainException('Generic bank provider field mapping array index is out of bounds.');
                }
                $segments[] = $index;
                $offset = $closing + 1;
            } else {
                throw new DomainException('Generic bank provider field mapping path syntax is invalid.');
            }

            if (count($segments) > self::MAX_MAPPING_PATH_DEPTH) {
                throw new DomainException('Generic bank provider field mapping path exceeds the maximum depth.');
            }
        }

        return $segments;
    }

    private function amountIrr(mixed $value): int
    {
        if (is_int($value)) {
            $amount = $value;
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $amount = (int) $value;
            if ((string) $amount !== ltrim($value, '0') && ! preg_match('/\A0+\z/', $value)) {
                throw new RuntimeException('Generic bank transaction amount overflows integer range.');
            }
        } else {
            throw new RuntimeException('Generic bank transaction amount must be an integer, never a float.');
        }
        if ($amount < 1) {
            throw new RuntimeException('Generic bank transaction amount must be positive.');
        }
        if ($this->amountUnit === 'TOMAN') {
            if ($amount > intdiv(PHP_INT_MAX, 10)) {
                throw new RuntimeException('Generic bank Toman amount overflows IRR range.');
            }
            $amount *= 10;
        }

        return $amount;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($this->dateFormat, $value, new DateTimeZone($this->providerTimezone));
        if ($date === false) {
            throw new RuntimeException('Generic bank transaction timestamp is invalid.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }
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
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function isDirectKey(string $value): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/', $value) === 1;
    }

    private function assertDirectKey(string $value, string $label): void
    {
        if (! $this->isDirectKey($value)) {
            throw new DomainException($label.' must be a direct JSON object key.');
        }
    }

    private function assertHeaderName(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $value) !== 1
            || in_array(strtolower($value), ['host', 'content-length', 'connection', 'transfer-encoding'], true)) {
            throw new DomainException('Generic bank API-key header name is invalid.');
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
