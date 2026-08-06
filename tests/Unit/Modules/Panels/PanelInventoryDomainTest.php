<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Domain\PanelCapabilityCode;
use App\Modules\Panels\Domain\PanelResourceCode;
use App\Modules\Panels\Domain\PanelResourceState;
use App\Modules\Panels\Domain\ProtocolProfileDefinition;
use App\Modules\Panels\Domain\SalesServerVisibility;
use App\Modules\Panels\Domain\ServiceTargetConfiguration;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-002 CAT-004 PRV-001 SEC-001 QUA-001 */
final class PanelInventoryDomainTest extends TestCase
{
    public function test_codes_and_typed_profile_definition_are_normalized(): void
    {
        self::assertSame('tehran-core', PanelResourceCode::fromInput(' Tehran-Core ')->value);
        self::assertSame('create_service', PanelCapabilityCode::fromInput(' Create_Service ')->value);

        $profile = new ProtocolProfileDefinition(
            'vless',
            'ws',
            'tls',
            'edge.example.com',
            'sni.example.com',
            '/vpn',
            443,
            'xtls-rprx-vision',
        );

        self::assertSame('vless', $profile->protocolFamily);
        self::assertSame(443, $profile->port);
        self::assertSame('/vpn', $profile->path);
    }

    public function test_service_target_configuration_is_canonical_and_typed(): void
    {
        $configuration = new ServiceTargetConfiguration(
            'inbound-42',
            'edge.example.com',
            null,
            '/service',
            8443,
            null,
            'grpc',
        );

        self::assertSame(
            '{"remote_identifier":"inbound-42","host":"edge.example.com","sni":null,"path":"/service","port":8443,"flow":null,"transport":"grpc"}',
            $configuration->canonicalJson,
        );
    }

    public function test_resource_state_and_server_visibility_are_fail_closed(): void
    {
        PanelResourceState::Disabled->assertCanTransitionTo(PanelResourceState::Active);
        SalesServerVisibility::Listed->assertCompatibleWith(PanelResourceState::Active);

        try {
            SalesServerVisibility::Listed->assertCompatibleWith(PanelResourceState::Disabled);
            self::fail('Expected listed disabled server rejection.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        PanelResourceState::Archived->assertCanTransitionTo(PanelResourceState::Active);
    }

    public function test_invalid_profile_path_and_port_are_rejected(): void
    {
        foreach ([
            ['path' => '../unsafe', 'port' => 443],
            ['path' => '/safe', 'port' => 70000],
        ] as $case) {
            try {
                new ProtocolProfileDefinition('vless', 'ws', 'tls', null, null, $case['path'], $case['port'], null);
                self::fail('Expected invalid protocol profile definition.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
