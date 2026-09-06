<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\PhpRuntimePreflight;
use Tests\TestCase;

final class PhpRuntimeDecoderPolicyTest extends TestCase
{
    /** @requirement INS-001 QUA-011 */
    public function test_gd_decoder_is_required_by_runtime_and_installer_policy(): void
    {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('*', $composer['require']['ext-gd'] ?? null);
        self::assertContains('gd', PhpRuntimePreflight::COMMON_REQUIRED_EXTENSIONS);
        self::assertContains('gd', config('installer.php_runtimes.required_extensions.common'));
    }
}
