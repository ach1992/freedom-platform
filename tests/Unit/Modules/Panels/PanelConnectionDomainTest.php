<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Domain\PanelConnectionState;
use App\Modules\Panels\Domain\PanelCredentials;
use App\Modules\Panels\Domain\PanelEndpoint;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-001 QUA-001 */
final class PanelConnectionDomainTest extends TestCase
{
    public function test_https_endpoint_and_network_policy_are_fail_closed(): void
    {
        $public = PanelEndpoint::fromInput('https://panel.example.com/api');
        $public->assertAllowedBy(PanelNetworkPolicy::PublicOnly);
        self::assertSame('panel.example.com', $public->host);

        $private = PanelEndpoint::fromInput('https://10.20.30.40:8443');
        $private->assertAllowedBy(PanelNetworkPolicy::PrivateAllowed);

        $this->expectException(InvalidArgumentException::class);
        $private->assertAllowedBy(PanelNetworkPolicy::PublicOnly);
    }

    public function test_endpoint_rejects_unsafe_url_shapes(): void
    {
        foreach ([
            'http://panel.example.com',
            'https://user:pass@panel.example.com',
            'https://panel.example.com?token=value',
            'https://panel.example.com/../admin',
            'https://localhost',
        ] as $value) {
            try {
                $endpoint = PanelEndpoint::fromInput($value);
                $endpoint->assertAllowedBy(PanelNetworkPolicy::PublicOnly);
                self::fail('Expected panel endpoint rejection.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_tls_configuration_requires_exact_policy_material(): void
    {
        $system = new TlsConfiguration(TlsPolicy::SystemCa, null, null, null);
        self::assertSame(TlsPolicy::SystemCa, $system->policy);

        $custom = new TlsConfiguration(TlsPolicy::CustomCa, 'private', 'panel-cas/core.pem', null);
        self::assertSame('panel-cas/core.pem', $custom->customCaPath);

        $pin = new TlsConfiguration(TlsPolicy::CertificatePin, null, null, str_repeat('a', 64));
        self::assertSame(str_repeat('a', 64), $pin->certificatePinSha256);

        $this->expectException(InvalidArgumentException::class);
        new TlsConfiguration(TlsPolicy::SystemCa, 'private', 'ca.pem', null);
    }

    public function test_credentials_are_canonical_and_reject_invalid_values(): void
    {
        $credentials = PanelCredentials::fromInput([
            'username' => 'service-user',
            'token' => 'sensitive-token',
        ]);

        self::assertSame(['token', 'username'], array_keys($credentials->values));
        self::assertSame(
            '{"token":"sensitive-token","username":"service-user"}',
            $credentials->canonicalJson,
        );

        $this->expectException(InvalidArgumentException::class);
        PanelCredentials::fromInput(['token' => '']);
    }

    public function test_connection_state_machine_is_explicit_and_archived_is_terminal(): void
    {
        PanelConnectionState::Disabled->assertCanTransitionTo(PanelConnectionState::Active);
        PanelConnectionState::Active->assertCanTransitionTo(PanelConnectionState::Maintenance);
        PanelConnectionState::Maintenance->assertCanTransitionTo(PanelConnectionState::Disabled);
        PanelConnectionState::Disabled->assertCanTransitionTo(PanelConnectionState::Archived);

        $this->expectException(DomainException::class);
        PanelConnectionState::Archived->assertCanTransitionTo(PanelConnectionState::Active);
    }
}
