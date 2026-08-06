<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use InvalidArgumentException;
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

    public function test_empty_delivery_artifacts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery artifacts require a subscription link or QR source.');

        new SensitiveDeliveryArtifacts([]);
    }

    public function test_control_characters_in_delivery_material_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Delivery artifact value is invalid.');

        new SensitiveDeliveryArtifacts(["https://example.invalid/sub/invalid\nsource"]);
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
