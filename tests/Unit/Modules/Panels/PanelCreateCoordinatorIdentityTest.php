<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
final class PanelCreateCoordinatorIdentityTest extends TestCase
{
    public function test_existing_remote_without_equivalence_proof_requires_review_without_create(): void
    {
        $request = new PanelCreateServiceRequest(
            'operation-identity-0001',
            'panel:create:identity-0001',
            'fp_identity_001',
            'fake-default',
            null,
            null,
            [],
        );
        $adapter = new AmbiguousIdentityPanelAdapter($request->username);

        $result = (new PanelCreateCoordinator(new RemoteIdentityResolver))->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertNotNull($result->service);
        self::assertSame('remote_create_equivalence_unavailable', $result->providerCode);
        self::assertSame(0, $adapter->createCalls);
    }
}

final class AmbiguousIdentityPanelAdapter implements PanelAdapter
{
    public int $createCalls = 0;

    public function __construct(private readonly string $username) {}

    public function testConnection(): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities(
            'fake',
            '1.0.0',
            ['authoritative_username_lookup', 'create_service'],
            ['fake-default'],
        );
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        return null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        if (! hash_equals($this->username, $username)) {
            return null;
        }

        return new RemoteServiceSnapshot(
            'ambiguous-remote-001',
            $username,
            PanelServiceStatus::Active,
            null,
            0,
            null,
            hash('sha256', 'ambiguous-state'),
            null,
        );
    }

    public function createEquivalenceHash(PanelCreateServiceRequest $request): string
    {
        return hash('sha256', 'expected-create-intent');
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        $this->createCalls++;

        throw new LogicException('Create must not be called for an ambiguous existing remote identity.');
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function updateExpiry(
        string $idempotencyKey,
        string $remoteId,
        DateTimeImmutable $expiresAt,
    ): PanelOperationResult {
        throw new LogicException('Not used.');
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        throw new LogicException('Not used.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new LogicException('Not used.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used.');
    }

    public function listCompatibleTargets(): array
    {
        return [];
    }
}
