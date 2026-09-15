<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class RuntimeDeploymentInvariants
{
    public const MINIMUM_MARIADB_VERSION = '10.11.9';

    public function mariaDbServerCompatible(string $driver, string $version, string $serverUid): bool
    {
        if (! in_array($driver, ['mysql', 'mariadb'], true)
            || trim($serverUid) === ''
            || preg_match('/\A(\d+\.\d+\.\d+)-MariaDB(?:-|\z)/i', trim($version), $matches) !== 1
        ) {
            return false;
        }

        return version_compare($matches[1], self::MINIMUM_MARIADB_VERSION, '>=');
    }

    public function databaseSessionCompatible(
        string $configuredCharset,
        string $configuredCollation,
        string $sessionCharset,
        string $sessionCollation,
        string $databaseCollation,
        string $sqlMode,
    ): bool {
        $modes = array_values(array_filter(array_map(
            static fn (string $mode): string => strtoupper(trim($mode)),
            explode(',', $sqlMode),
        )));

        return strtolower(trim($configuredCharset)) === 'utf8mb4'
            && strtolower(trim($sessionCharset)) === 'utf8mb4'
            && $configuredCollation !== ''
            && hash_equals(strtolower($configuredCollation), strtolower(trim($sessionCollation)))
            && hash_equals(strtolower($configuredCollation), strtolower(trim($databaseCollation)))
            && (in_array('STRICT_TRANS_TABLES', $modes, true) || in_array('STRICT_ALL_TABLES', $modes, true))
            && in_array('NO_ENGINE_SUBSTITUTION', $modes, true);
    }

    public function redisConnectionAuthenticated(mixed $configuration): bool
    {
        if (! is_array($configuration)) {
            return false;
        }

        $password = $configuration['password'] ?? null;
        if (is_string($password) && $password !== '') {
            return true;
        }

        $url = $configuration['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        $urlPassword = is_array($parts) ? ($parts['pass'] ?? null) : null;

        return is_string($urlPassword) && rawurldecode($urlPassword) !== '';
    }

    public function redisQueueAfterCommitCompatible(mixed $configuration): bool
    {
        return is_array($configuration)
            && ($configuration['driver'] ?? null) === 'redis'
            && ($configuration['after_commit'] ?? null) === true;
    }

    public function redisQueueRetryCompatible(mixed $configuration, string $supervisorTemplate): bool
    {
        if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
            return false;
        }

        $retryAfter = $configuration['retry_after'] ?? null;
        if (! is_int($retryAfter) || $retryAfter < 1) {
            return false;
        }

        if (preg_match_all('/--timeout(?:=|\s+)(\d+)/', $supervisorTemplate, $matches) < 1) {
            return false;
        }

        $workerTimeouts = array_map('intval', $matches[1]);
        if ($workerTimeouts === [] || min($workerTimeouts) < 1) {
            return false;
        }

        return $retryAfter > max($workerTimeouts);
    }
}
