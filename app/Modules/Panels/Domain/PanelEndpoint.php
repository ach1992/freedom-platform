<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class PanelEndpoint
{
    private const MAX_LENGTH = 2048;

    private function __construct(
        public string $value,
        public string $host,
        public bool $hostIsIp,
        public int $port,
    ) {}

    /** @requirement PRV-001 SEC-001 */
    public static function fromInput(string $value): self
    {
        if ($value === ''
            || $value !== trim($value)
            || strlen($value) > self::MAX_LENGTH
            || self::containsUnsafeCharacters($value)
        ) {
            throw new InvalidArgumentException('Panel endpoint URL is invalid.');
        }

        $parts = parse_url($value);
        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Panel endpoint must be an HTTPS URL without credentials, query, or fragment.');
        }

        $rawHost = (string) $parts['host'];
        if ($rawHost === '' || str_contains($rawHost, '%') || str_ends_with($rawHost, '.')) {
            throw new InvalidArgumentException('Panel endpoint host is invalid.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $hostIsIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $hostIsDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (! $hostIsIp && ! $hostIsDomain) {
            throw new InvalidArgumentException('Panel endpoint host is invalid.');
        }

        $port = $parts['port'] ?? 443;
        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Panel endpoint port is invalid.');
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path !== '') {
            if (! str_starts_with($path, '/')
                || str_contains($path, '%')
                || str_contains($path, '..')
                || self::containsUnsafeCharacters($path)
            ) {
                throw new InvalidArgumentException('Panel endpoint path is invalid.');
            }

            $path = rtrim($path, '/');
        }

        $authorityHost = $hostIsIp && str_contains($host, ':') ? '['.$host.']' : $host;
        $portSuffix = $port === 443 ? '' : ':'.$port;
        $normalized = 'https://'.$authorityHost.$portSuffix.$path;

        return new self($normalized, $host, $hostIsIp, $port);
    }

    public function assertAllowedBy(PanelNetworkPolicy $policy): void
    {
        if ($policy !== PanelNetworkPolicy::PublicOnly) {
            return;
        }

        if ($this->hostIsIp) {
            if (! PanelIpAddressPolicy::isPublic($this->host)) {
                throw new InvalidArgumentException('Public panel endpoint cannot target a non-public IP address.');
            }

            return;
        }

        if ($this->host === 'localhost'
            || str_ends_with($this->host, '.localhost')
            || str_ends_with($this->host, '.local')
            || str_ends_with($this->host, '.internal')
        ) {
            throw new InvalidArgumentException('Public panel endpoint cannot target a local hostname.');
        }
    }

    private static function containsUnsafeCharacters(string $value): bool
    {
        return str_contains($value, '\\') || preg_match('/[\x00-\x20\x7F]/', $value) === 1;
    }
}
