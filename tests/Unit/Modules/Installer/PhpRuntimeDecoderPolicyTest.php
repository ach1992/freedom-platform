<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\PhpRuntimePreflight;
use Tests\TestCase;

final class PhpRuntimeDecoderPolicyTest extends TestCase
{
    /** @requirement INS-001 QUA-011 */
    public function test_gd_decoder_is_required_by_runtime_installer_and_ci_policy(): void
    {
        self::assertContains('gd', PhpRuntimePreflight::COMMON_REQUIRED_EXTENSIONS);
        self::assertContains('gd', config('installer.php_runtimes.required_extensions.common'));

        $bootstrap = file_get_contents(base_path('scripts/ci/bootstrap-self-hosted-toolchain.sh'));
        self::assertIsString($bootstrap);
        self::assertMatchesRegularExpression('/required_extensions=\([\s\S]*\n    gd\n[\s\S]*\)/', $bootstrap);
    }
}
