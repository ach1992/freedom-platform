<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/** @requirement SVC-013 DAT-003 QUA-004 */
final class ServiceNotificationSyncFreshnessPolicy
{
    public static function configuredMaxAgeSeconds(): int
    {
        $value = filter_var(config('service_notifications.sync_snapshot_max_age_seconds', 1800), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 60, 'max_range' => 86400],
        ]);
        if ($value === false) {
            throw new DomainException('Service notification sync snapshot freshness is invalid.');
        }

        return (int) $value;
    }

    public static function storedMaxAgeSeconds(int|string|null $value): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 60, 'max_range' => 86400],
        ]);
        if ($validated === false) {
            throw new DomainException('Stored Service notification sync snapshot freshness is invalid.');
        }

        return (int) $validated;
    }

    public static function isFresh(string $observedAt, DateTimeImmutable $now, int $maxAgeSeconds): bool
    {
        $observed = new DateTimeImmutable($observedAt, new DateTimeZone('UTC'));
        $age = $now->getTimestamp() - $observed->getTimestamp();

        return $age >= 0 && $age <= $maxAgeSeconds;
    }
}
