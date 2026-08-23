<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Provisioning\Application\ServiceSyncAnomalyDetector;
use App\Modules\Provisioning\Domain\ServiceSyncAnomalyType;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use Tests\TestCase;

/** @requirement SVC-010 SVC-013 QUA-001 */
final class ServiceSyncAnomalyDetectorTest extends TestCase
{
    public function test_identity_mismatch_is_critical_without_secondary_entitlement_noise(): void
    {
        $detector = $this->detector('2026-08-23T00:00:00+00:00');
        $findings = $detector->detect(
            $this->local(),
            ['disposition' => 'identity_mismatch', 'status' => 'active', 'data_limit_bytes' => 1000, 'expires_at' => new DateTimeImmutable('+10 days')],
            null,
        );

        self::assertCount(1, $findings);
        self::assertSame(ServiceSyncAnomalyType::RemoteIdentityMismatch, $findings[0]['type']);
        self::assertSame('critical', $findings[0]['severity']->value);
    }

    public function test_expired_baseline_still_active_remote_is_classified_and_entitlement_change_is_separate(): void
    {
        $detector = $this->detector('2026-08-23T00:00:00+00:00');
        $findings = $detector->detect(
            $this->local(),
            [
                'disposition' => 'present',
                'status' => 'active',
                'data_limit_bytes' => 2000,
                'expires_at' => new DateTimeImmutable('2026-09-23T00:00:00+00:00'),
            ],
            [
                'local_lifecycle_version' => 2,
                'local_remote_identity_generation' => 3,
                'local_mutation_generation' => 4,
                'remote_status' => 'active',
                'remote_data_limit_bytes' => 1000,
                'remote_expires_at' => '2026-08-22 00:00:00.000000',
            ],
        );

        self::assertSame(
            [ServiceSyncAnomalyType::ExpiredLocalActiveRemote, ServiceSyncAnomalyType::UnexpectedEntitlement],
            array_column($findings, 'type'),
        );
    }

    public function test_expired_current_remote_state_is_detected_on_first_sync(): void
    {
        $detector = $this->detector('2026-08-23T00:00:00+00:00');
        $findings = $detector->detect(
            $this->local(),
            [
                'disposition' => 'present',
                'status' => 'active',
                'data_limit_bytes' => 1000,
                'expires_at' => new DateTimeImmutable('2026-08-22T00:00:00+00:00'),
            ],
            null,
        );

        self::assertSame([ServiceSyncAnomalyType::ExpiredLocalActiveRemote], array_column($findings, 'type'));
    }

    public function test_new_local_mutation_generation_resets_entitlement_baseline(): void
    {
        $detector = $this->detector('2026-08-23T00:00:00+00:00');
        $findings = $detector->detect(
            $this->local(),
            [
                'disposition' => 'present',
                'status' => 'active',
                'data_limit_bytes' => 2000,
                'expires_at' => new DateTimeImmutable('2026-09-23T00:00:00+00:00'),
            ],
            [
                'local_lifecycle_version' => 2,
                'local_remote_identity_generation' => 3,
                'local_mutation_generation' => 3,
                'remote_status' => 'active',
                'remote_data_limit_bytes' => 1000,
                'remote_expires_at' => '2026-08-22 00:00:00.000000',
            ],
        );

        self::assertSame([], $findings);
    }

    private function detector(string $now): ServiceSyncAnomalyDetector
    {
        $clock = new class(new DateTimeImmutable($now)) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        config([
            'service_sync.severity.missing_remote' => 'critical',
            'service_sync.severity.expired_local_active_remote' => 'warning',
            'service_sync.severity.lifecycle_mismatch' => 'warning',
            'service_sync.severity.remote_identity_mismatch' => 'critical',
            'service_sync.severity.unexpected_entitlement' => 'warning',
        ]);

        return new ServiceSyncAnomalyDetector($clock);
    }

    /** @return array{lifecycle_state:string,lifecycle_version:int,remote_identity_generation:int,mutation_generation:int} */
    private function local(): array
    {
        return [
            'lifecycle_state' => 'active',
            'lifecycle_version' => 2,
            'remote_identity_generation' => 3,
            'mutation_generation' => 4,
        ];
    }
}
