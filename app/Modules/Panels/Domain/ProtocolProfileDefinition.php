<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class ProtocolProfileDefinition
{
    public string $protocolFamily;
    public ?string $transport;
    public ?string $securityLayer;
    public ?string $host;
    public ?string $sni;
    public ?string $path;
    public ?int $port;
    public ?string $flow;

    public function __construct(
        string $protocolFamily,
        ?string $transport,
        ?string $securityLayer,
        ?string $host,
        ?string $sni,
        ?string $path,
        ?int $port,
        ?string $flow,
    ) {
        $this->protocolFamily = self::identifier($protocolFamily, 'Protocol family', false);
        $this->transport = self::nullableIdentifier($transport, 'Transport');
        $this->securityLayer = self::nullableIdentifier($securityLayer, 'Security layer');
        $this->host = self::nullableHost($host, 'Host');
        $this->sni = self::nullableHost($sni, 'SNI');
        $this->path = self::nullablePath($path);
        $this->port = self::nullablePort($port);
        $this->flow = self::nullableIdentifier($flow, 'Flow');
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'protocol_family' => $this->protocolFamily,
            'transport' => $this->transport,
            'security_layer' => $this->securityLayer,
            'host' => $this->host,
            'sni' => $this->sni,
            'path' => $this->path,
            'port' => $this->port,
            'flow' => $this->flow,
        ];
    }

    private static function nullableIdentifier(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::identifier($value, $label, true);
    }

    private static function identifier(string $value, string $label, bool $separatorAllowed): string
    {
        $normalized = strtolower(trim($value));
        $pattern = $separatorAllowed
            ? '/\A[a-z0-9][a-z0-9_.-]{0,63}\z/'
            : '/\A[a-z][a-z0-9_.-]{0,63}\z/';

        if (preg_match($pattern, $normalized) !== 1) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $normalized;
    }

    private static function nullableHost(?string $value, string $label): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtolower(rtrim(trim($value), '.'));
        if (mb_strlen($normalized) > 253
            || (filter_var($normalized, FILTER_VALIDATE_IP) === false
                && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $normalized) !== 1)
        ) {
            throw new InvalidArgumentException("{$label} is invalid.");
        }

        return $normalized;
    }

    private static function nullablePath(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (! str_starts_with($normalized, '/')
            || mb_strlen($normalized) > 512
            || str_contains($normalized, '..')
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
        ) {
            throw new InvalidArgumentException('Protocol path is invalid.');
        }

        return $normalized;
    }

    private static function nullablePort(?int $value): ?int
    {
        if ($value !== null && ($value < 1 || $value > 65535)) {
            throw new InvalidArgumentException('Protocol port is invalid.');
        }

        return $value;
    }
}
