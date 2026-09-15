<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-002 QUA-001 */
final class PanelCapabilitiesTest extends TestCase
{
    public function test_capabilities_are_stored_in_canonical_order(): void
    {
        $capabilities = new PanelCapabilities(
            'fake',
            '1.0.0',
            ['suspend', 'authoritative_username_lookup', 'create_service'],
            ['vless-ws-tls', 'trojan-tcp-tls'],
        );

        self::assertSame(
            ['authoritative_username_lookup', 'create_service', 'suspend'],
            $capabilities->operations,
        );
        self::assertSame(['trojan-tcp-tls', 'vless-ws-tls'], $capabilities->protocolProfiles);
        self::assertTrue($capabilities->supports('authoritative_username_lookup'));
    }

    public function test_duplicate_operations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel capability operation is duplicated.');

        new PanelCapabilities('fake', '1.0.0', ['create_service', 'create_service'], []);
    }

    public function test_blank_panel_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel capability version is invalid.');

        new PanelCapabilities('fake', '', [], []);
    }
}
