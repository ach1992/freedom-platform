<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Infrastructure;

use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderCapabilities;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderRequest;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bounded Generic REST gift-card adapter.
 * Mappings are direct object keys only. No JSONPath, templates, script, SQL, shell, or dynamic code is evaluated.
 * Private image/Telegram references never leave the trusted application boundary; this generic adapter is code-input only.
 */
final class GenericRestGiftCardVerificationProvider implements GiftCardVerificationProvider
{
    /** @var array<string,string> */
    private array $operationPaths;

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
     * @param  array<string,string>  $operationPaths  keys: validate,reserve,redeem,release,status; values: absolute paths
     * @param  array<string,string>  $fieldMap  canonical keys: event_id,transaction_id,outcome,status,face_value,currency,brand,region,occurred_at
     * @param  array<string,string>  $outcomeMap  provider value => success|pending|rejected|uncertain|unavailable
     * @param  array<string,string>  $statusMap  provider value => canonical bounded status token
     * @param  list<string>  $allowedHosts
     * @param  null|callable(string):list<string>  $resolver
     */
    public function __construct(
        private readonly string $providerCode,
        private readonly string $baseUrl,
        array $operationPaths,
        array $fieldMap,
        array $outcomeMap,
        array $statusMap,
        array $allowedHosts,
        private readonly string $dateFormat = DATE_ATOM,
        private readonly string $providerTimezone = 'UTC',
        private readonly string $authType = 'none',
        private readonly ?string $credential = null,
        private readonly string $apiKeyHeader = 'X-API-Key',
        private readonly int $timeoutSeconds = 8,
        private readonly int $maxBodyBytes = 262144,
        ?callable $resolver = null,
    ) {
        $this->assertToken($providerCode, 'Generic gift-card provider code', 2, 64);
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30 || $maxBodyBytes < 1024 || $maxBodyBytes > 2_097_152) {
            throw new DomainException('Generic gift-card HTTP limits are invalid.');
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
            throw new DomainException('Generic gift-card base URL must be a credential-free HTTPS origin/path.');
        }
        $this->host = strtolower((string) $url['host']);
        $this->port = isset($url['port']) ? (int) $url['port'] : 443;
        if ($this->port < 1 || $this->port > 65535) {
            throw new DomainException('Generic gift-card HTTPS port is invalid.');
        }

        $supportedOperations = ['validate', 'reserve', 'redeem', 'release', 'status'];
        if (! isset($operationPaths['validate'])) {
            throw new DomainException('Generic gift-card provider requires a validate operation.');
        }
        foreach ($operationPaths as $operation => $path) {
            if (! in_array($operation, $supportedOperations, true)
                || $path === '' || $path[0] !== '/'
                || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')) {
                throw new DomainException('Generic gift-card operation path is invalid.');
            }
        }
        $this->operationPaths = $operationPaths;

        $requiredFields = ['outcome', 'status', 'occurred_at'];
        foreach ($requiredFields as $canonical) {
            if (! isset($fieldMap[$canonical])) {
                throw new DomainException('Generic gift-card field mapping is incomplete.');
            }
        }
        $supportedFields = ['event_id', 'transaction_id', 'outcome', 'status', 'face_value', 'currency', 'brand', 'region', 'occurred_at'];
        foreach ($fieldMap as $canonical => $providerField) {
            if (! in_array($canonical, $supportedFields, true)) {
                throw new DomainException('Generic gift-card field mapping contains an unsupported canonical field.');
            }
            $this->assertDirectKey($providerField, 'Generic gift-card provider field');
        }
        $this->fieldMap = $fieldMap;

        if ($outcomeMap === [] || $statusMap === []) {
            throw new DomainException('Generic gift-card outcome/status maps cannot be empty.');
        }
        foreach ($outcomeMap as $providerValue => $canonical) {
            if ($providerValue === '' || strlen($providerValue) > 64
                || ! in_array($canonical, ['success', 'pending', 'rejected', 'uncertain', 'unavailable'], true)) {
                throw new DomainException('Generic gift-card outcome mapping is invalid.');
            }
        }
        foreach ($statusMap as $providerValue => $canonical) {
            if ($providerValue === '' || strlen($providerValue) > 64
                || preg_match('/\A[A-Za-z0-9:_.-]{1,64}\z/', $canonical) !== 1) {
                throw new DomainException('Generic gift-card status mapping is invalid.');
            }
        }
        $this->outcomeMap = $outcomeMap;
        $this->statusMap = $statusMap;

        $this->allowedHosts = array_values(array_unique(array_map(static fn (string $host): string => strtolower(trim($host)), $allowedHosts)));
        if ($this->allowedHosts === [] || ! in_array($this->host, $this->allowedHosts, true)) {
            throw new DomainException('Generic gift-card host is not allowlisted.');
        }
        foreach ($this->allowedHosts as $allowedHost) {
            if ($allowedHost === '' || preg_match('/\A[a-z0-9.-]+\z/', $allowedHost) !== 1) {
                throw new DomainException('Generic gift-card host allowlist is invalid.');
            }
        }

        if (! in_array($authType, ['none', 'bearer', 'api_key'], true)) {
            throw new DomainException('Generic gift-card authentication type is unsupported.');
        }
        if ($authType !== 'none' && ($credential === null || $credential === '' || strlen($credential) > 4096)) {
            throw new DomainException('Generic gift-card authentication credential is missing or invalid.');
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

    public function capabilities(): GiftCardProviderCapabilities
    {
        return new GiftCardProviderCapabilities(
            isset($this->operationPaths['validate']),
            isset($this->operationPaths['reserve']),
            isset($this->operationPaths['redeem']),
            isset($this->operationPaths['release']),
            isset($this->operationPaths['status']),
        );
    }

    public function validate(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->call('validate', $request);
    }

    public function reserve(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->call('reserve', $request);
    }

    public function redeem(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->call('redeem', $request);
    }

    public function release(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->call('release', $request);
    }

    public function status(GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        return $this->call('status', $request);
    }

    private function call(string $operation, GiftCardProviderRequest $request): GiftCardProviderEvidence
    {
        $path = $this->operationPaths[$operation] ?? null;
        if ($path === null) {
            throw new DomainException('Generic gift-card provider operation is unsupported.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $request->operationKey) !== 1) {
            throw new DomainException('Generic gift-card operation key is invalid.');
        }
        if ($request->code === null) {
            throw new DomainException('Generic REST gift-card verification requires code input; private image references are never exported.');
        }

        $addresses = ($this->resolver)($this->host);
        if (! is_array($addresses) || $addresses === []) {
            throw new RuntimeException('Generic gift-card host did not resolve to a usable address.');
        }
        $publicAddresses = [];
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                throw new RuntimeException('Generic gift-card host resolved to a private/reserved address.');
            }
            $publicAddresses[] = $address;
        }
        $pinnedAddress = $publicAddresses[0];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Idempotency-Key' => $request->operationKey,
        ];
        if ($this->authType === 'bearer') {
            $headers['Authorization'] = 'Bearer '.$this->credential;
        } elseif ($this->authType === 'api_key') {
            $headers[$this->apiKeyHeader] = (string) $this->credential;
        }

        $payload = [
            'operation_key' => $request->operationKey,
            'submission_id' => $request->submissionPublicId,
            'type_code' => $request->typeCode,
            'brand' => $request->brand,
            'region' => $request->region,
            'face_currency' => $request->faceCurrency,
            'face_value' => $request->faceValue,
            'code' => $request->code,
        ];
        $response = Http::withHeaders($headers)
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$this->host.':'.$this->port.':'.$pinnedAddress]],
                'on_headers' => function ($response): void {
                    $contentType = strtolower($response->getHeaderLine('Content-Type'));
                    if ($contentType === '' || (! str_contains($contentType, 'application/json') && ! str_contains($contentType, '+json'))) {
                        throw new RuntimeException('Generic gift-card provider returned a non-JSON content type.');
                    }
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxBodyBytes) {
                        throw new RuntimeException('Generic gift-card provider response exceeds the configured size limit.');
                    }
                },
            ])
            ->post(rtrim($this->baseUrl, '/').$path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Generic gift-card provider request failed with HTTP '.$response->status().'.');
        }
        $body = $response->body();
        if (strlen($body) > $this->maxBodyBytes) {
            throw new RuntimeException('Generic gift-card provider response exceeds the configured size limit.');
        }
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Generic gift-card provider response is malformed JSON.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Generic gift-card provider response must be a JSON object.');
        }

        $evidenceHash = hash('sha256', json_encode($this->canonicalize($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $providerOutcome = $this->requiredScalarString($decoded, 'outcome', 1, 64);
        $outcome = $this->outcomeMap[$providerOutcome] ?? null;
        if ($outcome === null) {
            throw new RuntimeException('Generic gift-card provider outcome is unmapped.');
        }
        $providerStatus = $this->requiredScalarString($decoded, 'status', 1, 64);
        $status = $this->statusMap[$providerStatus] ?? null;
        if ($status === null) {
            throw new RuntimeException('Generic gift-card provider status is unmapped.');
        }
        $transactionId = $this->optionalScalarString($decoded, 'transaction_id', 1, 191);
        $eventId = $this->optionalScalarString($decoded, 'event_id', 1, 191)
            ?? hash('sha256', $operation."\0".$request->operationKey."\0".$evidenceHash);
        $faceValue = $this->optionalPositiveInteger($decoded, 'face_value');
        $currency = $this->optionalScalarString($decoded, 'currency', 3, 3);
        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
                throw new RuntimeException('Generic gift-card currency is invalid.');
            }
        }
        $brand = $this->optionalScalarString($decoded, 'brand', 1, 64);
        $region = $this->optionalScalarString($decoded, 'region', 1, 64);
        $occurredAt = $this->date($this->requiredScalarString($decoded, 'occurred_at', 1, 128));

        if ($operation === 'redeem' && $outcome === 'success' && ($transactionId === null || $faceValue === null || $currency === null || $brand === null)) {
            throw new RuntimeException('Generic gift-card redeemed response lacks authoritative financial identity.');
        }
        if (in_array($operation, ['validate', 'reserve'], true) && $outcome === 'success' && ($faceValue === null || $currency === null || $brand === null)) {
            throw new RuntimeException('Generic gift-card successful verification response lacks card identity.');
        }

        return new GiftCardProviderEvidence(
            $operation,
            $outcome,
            $status,
            $eventId,
            $transactionId,
            $faceValue,
            $currency,
            $brand,
            $region,
            $occurredAt,
            $evidenceHash,
            ['operation' => $operation],
        );
    }

    /** @param array<string,mixed> $row */
    private function requiredScalarString(array $row, string $canonical, int $min, int $max): string
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || (! is_string($row[$field]) && ! is_int($row[$field]))) {
            throw new RuntimeException('Generic gift-card required field '.$canonical.' is missing or invalid.');
        }
        $value = trim((string) $row[$field]);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic gift-card required field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function optionalScalarString(array $row, string $canonical, int $min, int $max): ?string
    {
        $field = $this->fieldMap[$canonical] ?? null;
        if ($field === null || ! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            return null;
        }
        if (! is_string($row[$field]) && ! is_int($row[$field])) {
            throw new RuntimeException('Generic gift-card optional field '.$canonical.' is invalid.');
        }
        $value = trim((string) $row[$field]);
        if (strlen($value) < $min || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('Generic gift-card optional field '.$canonical.' is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function optionalPositiveInteger(array $row, string $canonical): ?int
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
            $maximum = (string) PHP_INT_MAX;
            if (strlen($normalized) > strlen($maximum)
                || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
                throw new RuntimeException('Generic gift-card face value overflows integer range.');
            }
            $integer = (int) $normalized;
        } else {
            throw new RuntimeException('Generic gift-card face value must be an integer, never a float.');
        }
        if ($integer < 1) {
            throw new RuntimeException('Generic gift-card face value must be positive.');
        }

        return $integer;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($this->dateFormat, $value, new DateTimeZone($this->providerTimezone));
        if ($date === false) {
            throw new RuntimeException('Generic gift-card provider timestamp is invalid.');
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
            || in_array(strtolower($value), ['host', 'content-length', 'connection', 'transfer-encoding', 'idempotency-key'], true)) {
            throw new DomainException('Generic gift-card API-key header name is invalid.');
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
