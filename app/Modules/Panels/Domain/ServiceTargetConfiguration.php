<?php

declare(strict_types=1);

namespace App\Modules\Panels\Domain;

use InvalidArgumentException;

final readonly class ServiceTargetConfiguration
{
    public string $remoteIdentifier;
    public ?string $host;
    public ?string $sni;
    public ?string $path;
    public ?int $port;
    public ?string $flow;
    public ?string $transport;
    public string $canonicalJson;

    public function __construct(
        string $remoteIdentifier,
        ?string $host,
        ?string $sni,
        ?string $path,
        ?int $port,
        ?string $flow,
        ?string $transport,
    ) {
        $identifier = trim($remoteIdentifier);
        if ($identifier === ''
            || mb_strlen($identifier) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1
        ) {
            throw new InvalidArgumentException('Remote target identifier is invalid.');
        }

        $definition = new ProtocolProfileDefinition(
            'target',
            $transport,
            null,
            $host,
            $sni,
            $path,
            $port,
            $flow,
        );

        $this->remoteIdentifier = $identifier;
        $this->host = $definition->host;
        $this->sni = $definition->sni;
        $this->path = $definition->path;
        $this->port = $definition->port;
        $this->flow = $definition->flow;
        $this->transport = $definition->transport;
        $this->canonicalJson = json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'remote_identifier' => $this->remoteIdentifier,
            'host' => $this->host,
            'sni' => $this->sni,
            'path' => $this->path,
            'port' => $this->port,
            'flow' => $this->flow,
            'transport' => $this->transport,
        ];
    }
}
