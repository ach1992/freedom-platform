<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
final class PanelCreateCoordinatorSecurityTest extends TestCase
{
    public function test_unexpected_adapter_exception_never_exposes_provider_text(): void
    {
        $secret = 'provider-secret-token-should-never-persist';
        $adapter = new class($secret) implements PanelAdapter
        {
            public function __construct(private readonly string $secret) {}

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
                return null;
            }

            public function createEquivalenceHash(PanelCreateServiceRequest $request): string
            {
                return hash('sha256', 'coordinator-security-test');
            }

            public function createService(PanelCreateServiceRequest $request): PanelOperationResult
            {
                throw new RuntimeException('Provider failed with credential '.$this->secret);
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
        };
        $request = new PanelCreateServiceRequest(
            'operation-security-0001',
            'panel:create:security-0001',
            'fp_security_001',
            'fake-default',
            null,
            null,
            [],
        );

        $result = (new PanelCreateCoordinator(new RemoteIdentityResolver))->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('remote_create_coordinator_exception', $result->providerCode);
        self::assertSame(
            'Remote create/adopt coordination ended unexpectedly; authoritative reconciliation is required.',
            $result->safeMessage,
        );
        self::assertStringNotContainsString($secret, (string) $result->safeMessage);
    }
}
