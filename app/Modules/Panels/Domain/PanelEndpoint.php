<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class PanelEndpoint
{
    private function __construct(
        public string $value,
        public string $host,
        public bool $hostIsIp,
    ) {}

    /** @requirement PRV-001 SEC-001 */
    public static function fromInput(string $value): self
    {
        $normalized = rtrim(trim($value), '/');
        $parts = parse_url($normalized);

        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Panel endpoint must be an HTTPS URL without credentials, query, or fragment.');
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        $hostIsIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $hostIsDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (! $hostIsIp && ! $hostIsDomain) {
            throw new InvalidArgumentException('Panel endpoint host is invalid.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Panel endpoint port is invalid.');
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && (str_contains($path, '..') || preg_match('/[\x00-\x20\x7F]/', $path) === 1)) {
            throw new InvalidArgumentException('Panel endpoint path is invalid.');
        }

        return new self($normalized, $host, $hostIsIp);
    }

    public function assertAllowedBy(PanelNetworkPolicy $policy): void
    {
        if ($policy !== PanelNetworkPolicy::PublicOnly) {
            return;
        }

        if ($this->hostIsIp) {
            $public = filter_var(
                $this->host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            if ($public === false) {
                throw new InvalidArgumentException('Public-only panel endpoints cannot use private or reserved IP addresses.');
            }

            return;
        }

        if ($this->host === 'localhost'
            || str_ends_with($this->host, '.localhost')
            || str_ends_with($this->host, '.local')
            || str_ends_with($this->host, '.internal')
        ) {
            throw new InvalidArgumentException('Public-only panel endpoint host is not allowed.');
        }
    }
}
