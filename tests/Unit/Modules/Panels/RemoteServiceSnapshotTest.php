<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
final class RemoteServiceSnapshotTest extends TestCase
{
    public function test_snapshot_hashes_are_normalized_without_changing_remote_identity(): void
    {
        $hash = strtoupper(hash('sha256', 'remote-service'));
        $equivalenceHash = strtoupper(hash('sha256', 'create-equivalence'));
        $snapshot = new RemoteServiceSnapshot(
            'remote-service-001',
            'fp_user_001',
            PanelServiceStatus::Active,
            null,
            0,
            null,
            $hash,
            $equivalenceHash,
        );

        self::assertSame('remote-service-001', $snapshot->remoteId);
        self::assertSame('fp_user_001', $snapshot->username);
        self::assertSame(strtolower($hash), $snapshot->canonicalHash);
        self::assertSame(strtolower($equivalenceHash), $snapshot->createEquivalenceHash);
    }

    public function test_create_equivalence_hash_is_optional_but_invalid_values_are_rejected(): void
    {
        $snapshot = new RemoteServiceSnapshot(
            'remote-service-001',
            'fp_user_001',
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'remote-service'),
        );
        self::assertNull($snapshot->createEquivalenceHash);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote create-equivalence hash is invalid.');

        new RemoteServiceSnapshot(
            'remote-service-002',
            'fp_user_002',
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'remote-service-2'),
            'invalid',
        );
    }

    public function test_blank_remote_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote service identifier is invalid.');

        new RemoteServiceSnapshot(
            '',
            'fp_user_001',
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'remote-service'),
        );
    }

    public function test_negative_remote_counters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote used bytes cannot be negative.');

        new RemoteServiceSnapshot(
            'remote-service-001',
            'fp_user_001',
            PanelServiceStatus::Active,
            null,
            -1,
            null,
            hash('sha256', 'remote-service'),
        );
    }
}
