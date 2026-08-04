<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use Illuminate\Support\Facades\Http;
use Throwable;

final class InstallerEnvironmentPreflight
{
    /**
     * @requirement INS-001 SEC-004 SEC-007 QUA-011
     *
     * @return array{outbound_https: bool, disk_space: bool, storage: bool, ownership: bool}
     */
    public function checks(): array
    {
        $paths = $this->stringList(config('installer.environment.paths', []));

        return [
            'outbound_https' => $this->outboundHttps(
                $this->stringList(config('installer.environment.outbound_urls', [])),
                $this->stringList(config('installer.environment.outbound_allowed_hosts', [])),
                max(1, (int) config('installer.environment.connect_timeout_seconds', 3)),
                max(1, (int) config('installer.environment.timeout_seconds', 5)),
            ),
            'disk_space' => $this->hasDiskSpace(
                $paths,
                max(1, (int) config('installer.environment.minimum_free_bytes', 1_073_741_824)),
            ),
            'storage' => $this->pathsAreUsable($paths),
            'ownership' => $this->pathsHaveExpectedOwnership(
                $paths,
                $this->nullableString(config('installer.environment.expected_owner')),
                $this->nullableString(config('installer.environment.expected_group')),
            ),
        ];
    }

    /** @param list<string> $urls @param list<string> $allowedHosts */
    private function outboundHttps(array $urls, array $allowedHosts, int $connectTimeout, int $timeout): bool
    {
        if ($urls === [] || $allowedHosts === []) {
            return false;
        }

        $allowedHosts = array_map(strtolower(...), $allowedHosts);

        foreach ($urls as $url) {
            $parts = parse_url($url);
            $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
            $host = is_array($parts) ? ($parts['host'] ?? null) : null;

            if ($scheme !== 'https' || ! is_string($host) || ! in_array(strtolower($host), $allowedHosts, true)) {
                return false;
            }

            try {
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->withOptions(['allow_redirects' => false])
                    ->head($url);
            } catch (Throwable) {
                return false;
            }

            if ($response->serverError()) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $paths */
    private function hasDiskSpace(array $paths, int $minimumFreeBytes): bool
    {
        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            $freeBytes = @disk_free_space($path);

            if (! is_float($freeBytes) || $freeBytes < $minimumFreeBytes) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $paths */
    private function pathsAreUsable(array $paths): bool
    {
        if ($paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (! is_dir($path) || ! is_readable($path) || ! is_writable($path)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $paths */
    private function pathsHaveExpectedOwnership(array $paths, ?string $expectedOwner, ?string $expectedGroup): bool
    {
        if ($paths === []) {
            return false;
        }

        $expectedUid = $this->expectedUserId($expectedOwner);
        $expectedGid = $this->expectedGroupId($expectedGroup);

        if (($expectedOwner !== null && $expectedUid === null) || ($expectedGroup !== null && $expectedGid === null)) {
            return false;
        }

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                return false;
            }

            $owner = @fileowner($path);
            $group = @filegroup($path);

            if (($expectedUid !== null && $owner !== $expectedUid) || ($expectedGid !== null && $group !== $expectedGid)) {
                return false;
            }
        }

        return true;
    }

    private function expectedUserId(?string $owner): ?int
    {
        if ($owner === null) {
            return null;
        }

        if (! function_exists('posix_getpwnam')) {
            return null;
        }

        $account = posix_getpwnam($owner);

        return is_array($account) && isset($account['uid']) && is_int($account['uid']) ? $account['uid'] : null;
    }

    private function expectedGroupId(?string $group): ?int
    {
        if ($group === null) {
            return null;
        }

        if (! function_exists('posix_getgrnam')) {
            return null;
        }

        $account = posix_getgrnam($group);

        return is_array($account) && isset($account['gid']) && is_int($account['gid']) ? $account['gid'] : null;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
