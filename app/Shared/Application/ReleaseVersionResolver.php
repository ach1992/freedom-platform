<?php

declare(strict_types=1);

namespace App\Shared\Application;

use JsonException;
use RuntimeException;

final class ReleaseVersionResolver
{
    private const AUTHORITY = 'freedom_platform_release_v1';

    public static function resolve(string $manifestPath, mixed $fallback): string
    {
        $fallbackVersion = self::boundedVersion($fallback) ?? '0.0.0-dev';

        if (! file_exists($manifestPath) && ! is_link($manifestPath)) {
            return $fallbackVersion;
        }

        if (is_link($manifestPath) || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('The release version manifest authority is unsafe.');
        }

        $contents = file_get_contents($manifestPath);
        if (! is_string($contents)) {
            throw new RuntimeException('The release version manifest could not be read.');
        }

        try {
            $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The release version manifest is invalid.', 0, $exception);
        }

        if (! is_array($manifest)
            || array_is_list($manifest)
            || ($manifest['version'] ?? null) !== 1
            || ($manifest['authority'] ?? null) !== self::AUTHORITY
        ) {
            throw new RuntimeException('The release version manifest authority is invalid.');
        }

        $version = self::boundedVersion($manifest['application_version'] ?? null);
        if ($version === null) {
            throw new RuntimeException('The release application version is invalid.');
        }

        return $version;
    }

    private static function boundedVersion(mixed $value): ?string
    {
        if (! is_string($value)
            || $value === ''
            || strlen($value) > 64
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        return $value;
    }
}
