<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;

/** @requirement SVC-013 SVC-014 DAT-003 QUA-004 */
final class ServiceNotificationExpiryFreshnessPolicy
{
    public const DEFAULT_MAX_AGE_SECONDS = 1800;

    public const MIN_MAX_AGE_SECONDS = 60;

    public const MAX_MAX_AGE_SECONDS = 86400;

    public static function configuredMaxAgeSeconds(): int
    {
        $validated = filter_var(
            config('service_notifications.expiry_snapshot_max_age_seconds', self::DEFAULT_MAX_AGE_SECONDS),
            FILTER_VALIDATE_INT,
            ['options' => [
                'min_range' => self::MIN_MAX_AGE_SECONDS,
                'max_range' => self::MAX_MAX_AGE_SECONDS,
            ]],
        );
        if ($validated === false) {
            throw new DomainException('service_notifications.expiry_snapshot_max_age_seconds is outside its supported range.');
        }

        return (int) $validated;
    }

    public static function storedMaxAgeSeconds(int|string|null $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => self::MIN_MAX_AGE_SECONDS,
            'max_range' => self::MAX_MAX_AGE_SECONDS,
        ]]);
        if ($validated === false) {
            throw new RuntimeException('Stored Service expiry notification freshness authority is invalid.');
        }

        return (int) $validated;
    }

    public static function isFresh(string $observedAt, DateTimeImmutable $now, int $maxAgeSeconds): bool
    {
        $maxAgeSeconds = self::storedMaxAgeSeconds($maxAgeSeconds);
        $utc = new DateTimeZone('UTC');
        $observed = new DateTimeImmutable($observedAt, $utc);
        $current = $now->setTimezone($utc);

        return $observed <= $current
            && $observed >= $current->modify('-'.$maxAgeSeconds.' seconds');
    }
}
