<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\PanelCreateCoordinator;
use App\Modules\Panels\Application\RemoteIdentityResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement PRV-001 PRV-002 PRV-003 SEC-002 QUA-001 */
final class PanelCreateCoordinatorSecurityTest extends TestCase
{
    public function test_unexpected_adapter_exception_never_exposes_provider_text(): void
    {
        $secret = 'provider-secret-token-should-never-persist';
        $adapter = $this->createStub(PanelAdapter::class);
        $adapter->method('capabilities')->willReturn(new PanelCapabilities(
            'fake',
            '1.0.0',
            ['authoritative_username_lookup', 'create_service'],
            ['fake-default'],
        ));
        $adapter->method('findByDeterministicUsername')->willReturn(null);
        $adapter->method('createEquivalenceHash')->willReturn(hash('sha256', 'coordinator-security-test'));
        $adapter->method('createService')->willThrowException(
            new RuntimeException('Provider failed with credential '.$secret),
        );
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
