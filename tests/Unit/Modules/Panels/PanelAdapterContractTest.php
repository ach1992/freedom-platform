<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
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
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-001 SEC-002 QUA-001 */
final class PanelAdapterContractTest extends TestCase
{
    public function test_uncertain_create_is_adopted_without_duplicate_remote_service(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $adapter->makeNextCreateUncertain();
        $result = (new PanelCreateCoordinator(new RemoteIdentityResolver($canonicalizer)))
            ->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::Success, $result->outcome);
        self::assertSame('remote_service_adopted', $result->providerCode);
        self::assertSame(1, $adapter->serviceCount());
    }

    public function test_mismatched_remote_identity_is_a_conflict_and_is_not_recreated(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $adapter->seed(new RemoteServiceSnapshot(
            'remote-1',
            'fp_user_001',
            PanelServiceStatus::Active,
            1,
            0,
            null,
            hash('sha256', 'different'),
        ));
        $result = (new PanelCreateCoordinator(new RemoteIdentityResolver($canonicalizer)))
            ->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('remote_attributes_mismatch', $result->providerCode);
        self::assertSame(1, $adapter->serviceCount());
    }

    public function test_missing_authoritative_lookup_requires_manual_review_and_blocks_create(): void
    {
        $canonicalizer = new PanelServiceCanonicalizer;
        $adapter = new FakePanelAdapter($canonicalizer);
        $adapter->makeAuthoritativeLookupUnavailable();
        $result = (new PanelCreateCoordinator(new RemoteIdentityResolver($canonicalizer)))
            ->createOrAdopt($adapter, $this->request());

        self::assertSame(PanelOperationOutcome::UncertainResult, $result->outcome);
        self::assertSame('authoritative_username_lookup_unavailable', $result->providerCode);
        self::assertSame(0, $adapter->serviceCount());
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
