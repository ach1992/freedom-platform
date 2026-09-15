<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use InvalidArgumentException;

final readonly class PanelMappedMutationRequest
{
    public string $method;

    public string $path;

    /** @var array<string, mixed>|null */
    public ?array $payload;

    /** @param array<string, mixed>|null $payload */
    public function __construct(string $method, string $path, ?array $payload = null)
    {
        $normalizedMethod = strtoupper($method);
        if (! in_array($normalizedMethod, ['DELETE', 'POST', 'PUT'], true)) {
            throw new InvalidArgumentException('Panel mutation HTTP method is invalid.');
        }
        if (! str_starts_with($path, '/api/')
            || $path !== trim($path)
            || mb_strlen($path) > 1024
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
        ) {
            throw new InvalidArgumentException('Panel mutation path is invalid.');
        }

        $this->method = $normalizedMethod;
        $this->path = $path;
        $this->payload = $payload;
    }
}
