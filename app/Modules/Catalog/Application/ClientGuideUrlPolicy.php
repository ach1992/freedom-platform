<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final class ClientGuideUrlPolicy
{
    public const MAXIMUM_BYTES = 512;

    public static function assertAllowed(string $url): void
    {
        if ($url === ''
            || strlen($url) > self::MAXIMUM_BYTES
            || trim($url) !== $url
            || preg_match('/[^\x21-\x7E]/', $url) === 1
            || str_contains($url, '\\')) {
            throw new InvalidArgumentException('Client-guide URL is invalid.');
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || $host === ''
            || strtolower($host) !== $host
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || ! str_contains($host, '.')) {
            throw new InvalidArgumentException('Client-guide URL is invalid.');
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path) || ($path !== '' && ! str_starts_with($path, '/'))) {
            throw new InvalidArgumentException('Client-guide URL is invalid.');
        }
    }
}
