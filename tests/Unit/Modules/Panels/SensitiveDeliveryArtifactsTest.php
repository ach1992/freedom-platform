<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-002 QUA-001 */
final class SensitiveDeliveryArtifactsTest extends TestCase
{
    public function test_subscription_links_default_to_authorized_qr_sources(): void
    {
        $link = 'https://example.invalid/sub/sensitive-source';
        $artifacts = new SensitiveDeliveryArtifacts([$link]);

        self::assertSame([$link], $artifacts->revealForAuthorizedDelivery());
        self::assertSame([$link], $artifacts->revealQrSourcesForAuthorizedDelivery());
        self::assertSame('[SENSITIVE_DELIVERY_ARTIFACTS]', (string) $artifacts);
    }

    public function test_debug_output_never_contains_delivery_material(): void
    {
        $secret = 'https://example.invalid/sub/must-not-appear';
        $artifacts = new SensitiveDeliveryArtifacts([$secret], ['qr-source-must-not-appear']);

        ob_start();
        var_dump($artifacts);
        $debugOutput = ob_get_clean();
        if (! is_string($debugOutput)) {
            self::fail('Expected captured debug output.');
        }

        self::assertStringContainsString('redacted', $debugOutput);
        self::assertStringNotContainsString($secret, $debugOutput);
        self::assertStringNotContainsString('qr-source-must-not-appear', $debugOutput);
    }
}
