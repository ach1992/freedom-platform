<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\PanelServiceCanonicalizer;
use App\Modules\Panels\Infrastructure\FakePanelAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-002 PRV-003 SEC-002 QUA-001 */
final class FakePanelMutationIdempotencyTest extends TestCase
{
    public function test_mutation_replays_do_not_duplicate_effects_and_conflicts_fail_closed(): void
    {
        $adapter = new FakePanelAdapter(new PanelServiceCanonicalizer);
        $created = $adapter->createService($this->request());
        self::assertSame(PanelOperationOutcome::Success, $created->outcome);
        self::assertNotNull($created->service);
        $remoteId = $created->service->remoteId;

        $first = $adapter->updateDataAllowance(
            'panel:add-data:idempotent-0001',
            $remoteId,
            536_870_912,
            DataAllowanceMode::Add,
        );
        $replayed = $adapter->updateDataAllowance(
            'panel:add-data:idempotent-0001',
            $remoteId,
            536_870_912,
            DataAllowanceMode::Add,
        );

        self::assertSame(PanelOperationOutcome::Success, $first->outcome);
        self::assertSame(1_610_612_736, $first->service?->dataLimitBytes);
        self::assertSame($first, $replayed);
        self::assertSame(
            1_610_612_736,
            $adapter->fetchStatus($remoteId)->service?->dataLimitBytes,
        );

        $conflict = $adapter->updateDataAllowance(
            'panel:add-data:idempotent-0001',
            $remoteId,
            1,
            DataAllowanceMode::Add,
        );
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $conflict->outcome);
        self::assertSame('fake_idempotency_conflict', $conflict->providerCode);
        self::assertSame(
            1_610_612_736,
            $adapter->fetchStatus($remoteId)->service?->dataLimitBytes,
        );

        $crossOperationConflict = $adapter->suspend('panel:add-data:idempotent-0001', $remoteId);
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $crossOperationConflict->outcome);
        self::assertSame('fake_idempotency_conflict', $crossOperationConflict->providerCode);
        self::assertSame(PanelServiceStatus::Active, $adapter->fetchStatus($remoteId)->service?->status);

        $deleted = $adapter->delete('panel:delete:idempotent-0001', $remoteId);
        $deleteReplay = $adapter->delete('panel:delete:idempotent-0001', $remoteId);
        self::assertSame(PanelOperationOutcome::Success, $deleted->outcome);
        self::assertSame($deleted, $deleteReplay);
        self::assertSame(0, $adapter->serviceCount());
    }

    private function request(): PanelCreateServiceRequest
    {
        return new PanelCreateServiceRequest(
            'operation-idempotency-0001',
            'panel:create:idempotency-0001',
            'fp_idempotency_user_001',
            'fake-default',
            1_073_741_824,
            new DateTimeImmutable('@1800000000'),
            ['device_limit' => 1, 'profile_code' => 'vless-ws-tls'],
        );
    }
}
