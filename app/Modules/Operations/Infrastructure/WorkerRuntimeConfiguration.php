<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use Closure;
use RuntimeException;

final readonly class WorkerRuntimeConfiguration
{
    /** @requirement OPS-003 RUN-003 RUN-004 QUA-011 */
    public function __construct(
        public bool $enabled,
        public string $workerId,
        public string $queueGroup,
        public ?string $releaseVersion,
        public ?string $bootId,
        public int $intervalSeconds,
    ) {}

    /**
     * Resolve per-process Supervisor values after Laravel config has been cached.
     *
     * @param  array{enabled: mixed, worker_id: mixed, queue_group: mixed, release_version: mixed, boot_id: mixed, interval_seconds: mixed}  $fallback
     * @param  (Closure(string): (string|false))|null  $environment
     */
    public static function resolve(array $fallback, ?Closure $environment = null): self
    {
        $environment ??= static fn (string $name): string|false => getenv($name);

        $enabled = self::boolean(
            $environment('WORKER_HEARTBEAT_ENABLED'),
            (bool) $fallback['enabled'],
        );
        $workerId = self::string(
            $environment('WORKER_NAME'),
            $fallback['worker_id'],
        );
        $queueGroup = self::string(
            $environment('WORKER_QUEUE_GROUP'),
            $fallback['queue_group'],
        );
        $releaseVersion = self::nullableString(
            $environment('WORKER_RELEASE_ID'),
            $fallback['release_version'],
        );
        $bootId = self::nullableString(
            $environment('WORKER_BOOT_ID'),
            $fallback['boot_id'],
        );
        $intervalSeconds = self::positiveInteger(
            $environment('WORKER_HEARTBEAT_INTERVAL_SECONDS'),
            $fallback['interval_seconds'],
            'Worker heartbeat interval',
        );

        if ($enabled && $workerId === '') {
            throw new RuntimeException('An enabled queue worker heartbeat requires a unique worker ID.');
        }

        if ($enabled && $queueGroup === '') {
            throw new RuntimeException('An enabled queue worker heartbeat requires a queue group.');
        }

        if ($enabled
            && ($releaseVersion === null
                || preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\\z/', $releaseVersion) !== 1
                || str_contains($releaseVersion, '..'))
        ) {
            throw new RuntimeException('An enabled queue worker heartbeat requires the exact physical release ID.');
        }

        if ($enabled && ($bootId === null || preg_match('/\\A[0-9a-f]{32}\\z/', $bootId) !== 1)) {
            throw new RuntimeException('An enabled queue worker heartbeat requires a valid boot ID.');
        }

        return new self(
            enabled: $enabled,
            workerId: $workerId,
            queueGroup: $queueGroup,
            releaseVersion: $releaseVersion,
            bootId: $bootId,
            intervalSeconds: $intervalSeconds,
        );
    }

    private static function boolean(string|false $environmentValue, bool $fallback): bool
    {
        if ($environmentValue === false) {
            return $fallback;
        }

        $value = filter_var($environmentValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new RuntimeException('Worker heartbeat enabled must be a boolean value.');
        }

        return $value;
    }

    private static function string(string|false $environmentValue, mixed $fallback): string
    {
        $value = $environmentValue === false && is_string($fallback)
            ? $fallback
            : $environmentValue;

        return is_string($value) ? trim($value) : '';
    }

    private static function nullableString(string|false $environmentValue, mixed $fallback): ?string
    {
        $value = self::string($environmentValue, $fallback);

        return $value === '' ? null : $value;
    }

    private static function positiveInteger(
        string|false $environmentValue,
        mixed $fallback,
        string $label,
    ): int {
        $value = $environmentValue === false ? $fallback : $environmentValue;

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new RuntimeException($label.' must be a positive integer.');
        }

        return (int) $value;
    }
}
