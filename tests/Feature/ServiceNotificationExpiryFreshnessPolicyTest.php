<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Provisioning\Application\ServiceNotificationExpiryFreshnessPolicy;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use Tests\TestCase;

/** @requirement SVC-013 SVC-014 DAT-003 QUA-004 */
final class ServiceNotificationExpiryFreshnessPolicyTest extends TestCase
{
    public function test_configured_freshness_policy_accepts_default_and_supported_boundaries(): void
    {
        config()->set('service_notifications.expiry_snapshot_max_age_seconds', '1800');
        self::assertSame(1800, ServiceNotificationExpiryFreshnessPolicy::configuredMaxAgeSeconds());

        config()->set('service_notifications.expiry_snapshot_max_age_seconds', 60);
        self::assertSame(60, ServiceNotificationExpiryFreshnessPolicy::configuredMaxAgeSeconds());

        config()->set('service_notifications.expiry_snapshot_max_age_seconds', 86400);
        self::assertSame(86400, ServiceNotificationExpiryFreshnessPolicy::configuredMaxAgeSeconds());
    }

    public function test_configured_freshness_policy_fails_closed_outside_supported_range(): void
    {
        foreach ([59, 86401] as $invalid) {
            config()->set('service_notifications.expiry_snapshot_max_age_seconds', $invalid);

            try {
                ServiceNotificationExpiryFreshnessPolicy::configuredMaxAgeSeconds();
                self::fail('Unsupported Service expiry freshness configuration must fail closed.');
            } catch (DomainException $exception) {
                self::assertSame(
                    'service_notifications.expiry_snapshot_max_age_seconds is outside its supported range.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_snapshot_freshness_is_inclusive_and_rejects_future_or_stale_observations(): void
    {
        $now = new DateTimeImmutable('2030-01-01 00:30:00.000000', new DateTimeZone('UTC'));

        self::assertTrue(ServiceNotificationExpiryFreshnessPolicy::isFresh(
            '2030-01-01 00:00:00.000000',
            $now,
            1800,
        ));
        self::assertFalse(ServiceNotificationExpiryFreshnessPolicy::isFresh(
            '2029-12-31 23:59:59.999999',
            $now,
            1800,
        ));
        self::assertFalse(ServiceNotificationExpiryFreshnessPolicy::isFresh(
            '2030-01-01 00:30:00.000001',
            $now,
            1800,
        ));
    }

    public function test_stored_freshness_authority_rejects_missing_or_invalid_values(): void
    {
        foreach ([null, 59, 86401] as $invalid) {
            try {
                ServiceNotificationExpiryFreshnessPolicy::storedMaxAgeSeconds($invalid);
                self::fail('Invalid stored Service expiry freshness authority must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Stored Service expiry notification freshness authority is invalid.',
                    $exception->getMessage(),
                );
            }
        }
    }
}
