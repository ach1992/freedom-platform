<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Domain\PanelCredentials;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-002 QUA-001 */
final class PanelCredentialsRedactionTest extends TestCase
{
    public function test_credentials_remain_explicitly_accessible_but_never_appear_in_debug_output(): void
    {
        $secret = 'panel-secret-password';
        $credentials = PanelCredentials::fromInput([
            'password' => $secret,
            'username' => 'panel-admin',
        ]);

        self::assertSame($secret, $credentials->values['password']);
        self::assertSame('[PANEL_CREDENTIALS]', (string) $credentials);

        ob_start();
        var_dump($credentials);
        $debugOutput = ob_get_clean();
        if (! is_string($debugOutput)) {
            self::fail('Expected captured credential debug output.');
        }

        self::assertStringContainsString('redacted', $debugOutput);
        self::assertStringNotContainsString($secret, $debugOutput);
        self::assertStringNotContainsString($credentials->canonicalJson, $debugOutput);
    }
}
