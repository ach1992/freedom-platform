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
use App\Modules\Panels\Application\PanelAdapterRegistry;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\PanelCredentialPolicy;
use App\Modules\Panels\Application\PanelServiceCanonicalizer;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use App\Modules\Panels\Infrastructure\FakePanelAdapter;
use App\Modules\Panels\Infrastructure\FakePanelAdapterFactory;
use App\Modules\Panels\Infrastructure\MarzbanAdapterFactory;
use App\Modules\Panels\Infrastructure\PasarGuardAdapterFactory;
use App\Modules\Panels\Infrastructure\UnavailableMarzbanGatewayFactory;
use App\Modules\Panels\Infrastructure\UnavailablePasarGuardGatewayFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-001 SEC-002 QUA-001 */
final class PanelAdapterContractTest extends TestCase
{
    public function test_existing_matching_remote_service_is_adopted_without_create_call(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $snapshot = $this->matchingSnapshot($canonicalizer, $request);
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::once())
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturn($snapshot);
        $adapter->expects(self::never())->method('createService');

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::Success, $result->outcome);
        self::assertSame('remote_service_adopted', $result->providerCode);
        self::assertSame($snapshot, $result->service);
    }

    public function test_existing_remote_without_create_equivalence_proof_requires_manual_review_and_blocks_create(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $snapshot = new RemoteServiceSnapshot(
            'remote-without-equivalence',
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            hash('sha256', 'provider-observable-only'),
        );
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::once())
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturn($snapshot);
        $adapter->expects(self::never())->method('createService');

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('remote_create_equivalence_unavailable', $result->providerCode);
        self::assertSame($snapshot, $result->service);
    }

    public function test_uncertain_create_is_discovered_and_adopted_after_exactly_one_create_call(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $snapshot = $this->matchingSnapshot($canonicalizer, $request);
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->expects(self::exactly(2))
            ->method('capabilities')
            ->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::exactly(2))
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturnOnConsecutiveCalls(null, $snapshot);
        $adapter->expects(self::once())
            ->method('createService')
            ->with($request)
            ->willReturn(new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'provider_timeout_after_create',
                'Remote create result is uncertain.',
            ));

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::Success, $result->outcome);
        self::assertSame('remote_service_adopted', $result->providerCode);
        self::assertSame($snapshot, $result->service);
    }

    public function test_uncertain_create_without_discovered_identity_requires_manual_review_and_no_second_create(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->expects(self::exactly(2))
            ->method('capabilities')
            ->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::exactly(2))
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturn(null);
        $adapter->expects(self::once())
            ->method('createService')
            ->with($request)
            ->willReturn(new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'provider_timeout_after_create',
                'Remote create result is uncertain.',
            ));

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('remote_create_unresolved', $result->providerCode);
        self::assertNull($result->service);
    }

    public function test_success_without_authoritative_snapshot_is_treated_as_uncertain(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::once())
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturn(null);
        $adapter->expects(self::once())
            ->method('createService')
            ->with($request)
            ->willReturn(new PanelOperationResult(
                PanelOperationOutcome::Success,
                null,
                'provider_created_without_snapshot',
                'Provider reported success without a service snapshot.',
            ));

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('remote_create_missing_snapshot', $result->providerCode);
        self::assertNull($result->service);
    }

    public function test_success_with_mismatched_snapshot_is_a_conflict(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $differentHash = hash('sha256', 'different-create-attributes');
        $mismatch = new RemoteServiceSnapshot(
            'remote-mismatch-0001',
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            hash('sha256', 'provider-observable-state'),
            $differentHash,
        );
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::once())
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturn(null);
        $adapter->expects(self::once())
            ->method('createService')
            ->with($request)
            ->willReturn(new PanelOperationResult(
                PanelOperationOutcome::Success,
                $mismatch,
                'provider_service_created',
                'Provider reported a created service.',
            ));

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('remote_attributes_mismatch', $result->providerCode);
        self::assertSame($mismatch, $result->service);
    }

    public function test_uncertain_create_followed_by_mismatch_is_a_conflict_without_second_create(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $request = $this->request();
        $mismatch = new RemoteServiceSnapshot(
            'remote-mismatch-0002',
            $request->username,
            PanelServiceStatus::Active,
            1,
            0,
            null,
            hash('sha256', 'provider-observable-state-2'),
            hash('sha256', 'different-discovered-attributes'),
        );
        $adapter = $this->createMock(PanelAdapter::class);
        $adapter->expects(self::exactly(2))
            ->method('capabilities')
            ->willReturn($this->authoritativeCapabilities());
        $adapter->method('createEquivalenceHash')->with($request)->willReturn($canonicalizer->hashCreateRequest($request));
        $adapter->expects(self::exactly(2))
            ->method('findByDeterministicUsername')
            ->with($request->username)
            ->willReturnOnConsecutiveCalls(null, $mismatch);
        $adapter->expects(self::once())
            ->method('createService')
            ->with($request)
            ->willReturn(new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'provider_timeout_after_create',
                'Remote create result is uncertain.',
            ));

        $result = $this->coordinator()->createOrAdopt($adapter, $request);

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('remote_attributes_mismatch', $result->providerCode);
        self::assertSame($mismatch, $result->service);
    }

    public function test_conflicting_idempotency_key_reuse_cannot_overwrite_remote_service(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $firstRequest = $this->request();
        $first = $this->coordinator()->createOrAdopt($adapter, $firstRequest);
        self::assertSame(PanelOperationOutcome::Success, $first->outcome);

        $conflictingRequest = new PanelCreateServiceRequest(
            'operation-00000002',
            $firstRequest->idempotencyKey,
            'fp_user_002',
            $firstRequest->targetReference,
            $firstRequest->dataLimitBytes,
            $firstRequest->expiresAt,
            $firstRequest->validatedAttributes,
        );
        $conflict = $this->coordinator()->createOrAdopt($adapter, $conflictingRequest);

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $conflict->outcome);
        self::assertSame('fake_idempotency_conflict', $conflict->providerCode);
        self::assertSame(1, $adapter->serviceCount());
        self::assertNotNull($adapter->findByDeterministicUsername($firstRequest->username));
        self::assertNull($adapter->findByDeterministicUsername($conflictingRequest->username));
    }

    public function test_uncertain_fake_create_is_adopted_without_duplicate_remote_service(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $adapter->makeNextCreateUncertain();

        $result = $this->coordinator()->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::Success, $result->outcome);
        self::assertSame('remote_service_adopted', $result->providerCode);
        self::assertSame(1, $adapter->serviceCount());
    }

    public function test_mismatched_remote_identity_is_a_conflict_and_is_not_recreated(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $differentHash = hash('sha256', 'different');
        $adapter->seed(new RemoteServiceSnapshot(
            'remote-1',
            'fp_user_001',
            PanelServiceStatus::Active,
            1,
            0,
            null,
            hash('sha256', 'provider-observable-fake'),
            $differentHash,
        ));

        $result = $this->coordinator()->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('remote_attributes_mismatch', $result->providerCode);
        self::assertSame(1, $adapter->serviceCount());
    }

    public function test_missing_authoritative_lookup_requires_manual_review_and_blocks_create(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $adapter->makeAuthoritativeLookupUnavailable();

        $result = $this->coordinator()->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('authoritative_username_lookup_unavailable', $result->providerCode);
        self::assertSame(0, $adapter->serviceCount());
    }

    public function test_fake_adapter_implements_the_declared_operation_contract(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $request = $this->request();
        $connection = $adapter->testConnection();
        self::assertSame(PanelOperationOutcome::Success, $connection->outcome);

        $capabilities = $adapter->capabilities();
        self::assertSame('fake', $capabilities->panelType);
        self::assertSame('1.0.0', $capabilities->panelVersion);
        foreach ([
            'authoritative_username_lookup',
            'create_service',
            'fetch_status',
            'update_expiry',
            'set_data_allowance',
            'add_data_allowance',
            'reset_usage',
            'suspend',
            'activate',
            'delete',
            'rotate_subscription_link',
            'delivery_artifacts',
            'synchronize',
            'list_compatible_targets',
        ] as $operation) {
            self::assertTrue($capabilities->supports($operation), $operation.' must be supported.');
        }

        $created = $this->coordinator()->createOrAdopt($adapter, $request);
        self::assertSame(PanelOperationOutcome::Success, $created->outcome);
        self::assertNotNull($created->service);
        $remoteId = $created->service->remoteId;

        self::assertSame(PanelOperationOutcome::Success, $adapter->fetchStatus($remoteId)->outcome);

        $expiresAt = new DateTimeImmutable('@1800003600');
        $expiry = $adapter->updateExpiry('panel:update-expiry:0001', $remoteId, $expiresAt);
        self::assertEquals($expiresAt, $expiry->service?->expiresAt);

        $allowance = $adapter->updateDataAllowance(
            'panel:add-data:0001',
            $remoteId,
            536_870_912,
            DataAllowanceMode::Add,
        );
        self::assertSame(1_610_612_736, $allowance->service?->dataLimitBytes);
        self::assertSame(0, $adapter->resetUsage('panel:reset-usage:0001', $remoteId)->service?->usedBytes);
        self::assertSame(
            PanelServiceStatus::Suspended,
            $adapter->suspend('panel:suspend:0001', $remoteId)->service?->status,
        );
        self::assertSame(
            PanelServiceStatus::Active,
            $adapter->activate('panel:activate:0001', $remoteId)->service?->status,
        );
        self::assertSame(
            PanelOperationOutcome::Success,
            $adapter->rotateSubscriptionLink('panel:rotate:0001', $remoteId)->outcome,
        );

        $delivery = $adapter->getDeliveryArtifacts($remoteId);
        self::assertSame('[SENSITIVE_DELIVERY_ARTIFACTS]', (string) $delivery);
        self::assertCount(1, $delivery->revealForAuthorizedDelivery());
        self::assertSame(PanelOperationOutcome::Success, $adapter->synchronize($remoteId)->outcome);
        self::assertSame('fake-default', $adapter->listCompatibleTargets()[0]['id']);
        self::assertSame(
            PanelOperationOutcome::Success,
            $adapter->delete('panel:delete:0001', $remoteId)->outcome,
        );
        self::assertSame(0, $adapter->serviceCount());
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $adapter->fetchStatus($remoteId)->outcome);
    }

    public function test_registry_builds_connection_bound_adapter_with_system_ca(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $registry = new PanelAdapterRegistry(
            [new FakePanelAdapterFactory($canonicalizer)],
            new PanelCredentialPolicy,
        );
        $adapter = $registry->make(PanelProviderType::Fake, new PanelAdapterSession(
            PanelEndpoint::fromInput('https://panel.example.com'),
            PanelCredentials::fromInput(['username' => 'test', 'password' => 'secret']),
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
        ));

        self::assertInstanceOf(FakePanelAdapter::class, $adapter);
    }

    public function test_unconfigured_marzban_shell_fails_closed_before_create_and_delivery(): void
    {
        $registry = new PanelAdapterRegistry(
            [new MarzbanAdapterFactory(new UnavailableMarzbanGatewayFactory)],
            new PanelCredentialPolicy,
        );
        $this->assertUnavailableProviderShell(
            $registry,
            PanelProviderType::Marzban,
            ['username' => 'test', 'password' => 'secret'],
        );
    }

    public function test_unconfigured_pasarguard_shell_fails_closed_before_create_and_delivery(): void
    {
        $registry = new PanelAdapterRegistry(
            [new PasarGuardAdapterFactory(new UnavailablePasarGuardGatewayFactory)],
            new PanelCredentialPolicy,
        );
        $this->assertUnavailableProviderShell(
            $registry,
            PanelProviderType::PasarGuard,
            ['api_token' => 'secret-token'],
        );
    }

    public function test_registry_rejects_duplicate_provider_factory_registration(): void
    {
        $factory = new FakePanelAdapterFactory(new PanelServiceCanonicalizer);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate panel adapter factory registration.');

        new PanelAdapterRegistry([$factory, $factory], new PanelCredentialPolicy);
    }

    public function test_system_ca_policy_rejects_custom_ca_and_certificate_pin_material(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('System CA policy cannot include custom CA or certificate pin data.');

        new TlsConfiguration(TlsPolicy::SystemCa, 'private', 'panel-ca.pem', null);
    }

    /** @param array<string, string> $credentials */
    private function assertUnavailableProviderShell(
        PanelAdapterRegistry $registry,
        PanelProviderType $provider,
        array $credentials,
    ): void {
        $adapter = $registry->make($provider, new PanelAdapterSession(
            PanelEndpoint::fromInput('https://panel.example.com'),
            PanelCredentials::fromInput($credentials),
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
        ));

        $connection = $adapter->testConnection();
        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $connection->outcome);
        self::assertSame('panel_gateway_unconfigured', $connection->providerCode);

        $result = $this->coordinator()->createOrAdopt($adapter, $this->request());
        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('authoritative_username_lookup_unavailable', $result->providerCode);

        try {
            $adapter->getDeliveryArtifacts('remote-service-0001');
            self::fail('Expected unavailable panel delivery to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Panel gateway is not configured.', $exception->getMessage());
        }
    }

    private function coordinator(): PanelCreateCoordinator
    {
        return new PanelCreateCoordinator(new RemoteIdentityResolver);
    }

    private function authoritativeCapabilities(): PanelCapabilities
    {
        return new PanelCapabilities(
            'test',
            '1.0.0',
            ['authoritative_username_lookup', 'create_service'],
            ['vless-ws-tls'],
        );
    }

    private function matchingSnapshot(
        PanelServiceCanonicalizer $canonicalizer,
        PanelCreateServiceRequest $request,
    ): RemoteServiceSnapshot {
        $equivalenceHash = $canonicalizer->hashCreateRequest($request);

        return new RemoteServiceSnapshot(
            'remote-00000001',
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            hash('sha256', 'provider-observable-matching-state'),
            $equivalenceHash,
        );
    }

    private function request(): PanelCreateServiceRequest
    {
        return new PanelCreateServiceRequest(
            'operation-00000001',
            'panel:create:order-item:00000001',
            'fp_user_001',
            'fake-default',
            1_073_741_824,
            new DateTimeImmutable('@1800000000'),
            ['device_limit' => 1, 'profile_code' => 'vless-ws-tls'],
        );
    }
}
