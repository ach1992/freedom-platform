<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerProductionEnvironmentPolicy;
use RuntimeException;
use Tests\TestCase;

final class InstallerProductionEnvironmentPolicyTest extends TestCase
{
    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_accepts_an_explicit_safe_production_environment(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('production');

        $policy->assertSafe($this->environment());

        $this->addToAssertionCount(1);
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_it_preserves_an_explicit_non_production_installation_path(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('testing');

        $policy->assertSafe($this->environment([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://localhost:8000',
            'SESSION_ENCRYPT' => 'false',
            'SESSION_SECURE_COOKIE' => 'false',
        ]));

        $this->addToAssertionCount(1);
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_production_boot_rejects_unsafe_security_critical_values(): void
    {
        $cases = [
            'environment downgrade' => ['APP_ENV' => 'local'],
            'debug enabled' => ['APP_DEBUG' => 'true'],
            'plain http application url' => ['APP_URL' => 'http://example.test'],
            'invalid application url' => ['APP_URL' => 'not-a-url'],
            'session encryption disabled' => ['SESSION_ENCRYPT' => 'false'],
            'secure cookie disabled' => ['SESSION_SECURE_COOKIE' => 'false'],
        ];

        foreach ($cases as $case => $overrides) {
            try {
                (new InstallerProductionEnvironmentPolicy('production'))
                    ->assertSafe($this->environment($overrides));

                $this->fail('Unsafe production environment case was accepted: '.$case);
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment is not safe to finalize.',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_phpdotenv_quoted_whitespace_is_not_normalized_into_a_safe_production_value(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('production');

        foreach ([
            ['APP_DEBUG' => ' false '],
            ['APP_ENV' => ' production '],
        ] as $overrides) {
            try {
                $policy->assertSafe($this->environment($overrides));
                $this->fail('Whitespace-bearing production values must retain phpdotenv semantics and be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment is not safe to finalize.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_boolean_policy_matches_laravel_environment_value_semantics(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('production');

        $policy->assertSafe($this->environment([
            'APP_DEBUG' => '0',
            'SESSION_ENCRYPT' => '(true)',
            'SESSION_SECURE_COOKIE' => 'TRUE',
        ]));
        $this->addToAssertionCount(1);

        foreach ([
            ['SESSION_ENCRYPT' => '1'],
            ['SESSION_SECURE_COOKIE' => '1'],
        ] as $overrides) {
            try {
                $policy->assertSafe($this->environment($overrides));
                $this->fail('String truthy values must not be accepted as Laravel boolean true values.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment is not safe to finalize.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_production_requires_literal_security_critical_values(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('production');
        $cases = [
            ['key' => 'APP_ENV', 'helper' => 'TARGET_ENV', 'value' => 'production'],
            ['key' => 'APP_DEBUG', 'helper' => 'TARGET_DEBUG', 'value' => 'false'],
            ['key' => 'APP_URL', 'helper' => 'INSTALLER_ORIGIN', 'value' => 'https://safe.example'],
            ['key' => 'SESSION_ENCRYPT', 'helper' => 'TARGET_SESSION_ENCRYPT', 'value' => 'true'],
            ['key' => 'SESSION_SECURE_COOKIE', 'helper' => 'TARGET_SECURE_COOKIE', 'value' => 'true'],
        ];

        foreach ($cases as $case) {
            $contents = $case['helper'].'="'.$case['value']."\"\n".$this->environment([
                $case['key'] => '${'.$case['helper'].'}',
            ]);

            try {
                $policy->assertSafe($contents);
                $this->fail('Production-sensitive interpolation must be rejected: '.$case['key']);
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment must use literal security-critical values.',
                    $exception->getMessage(),
                    $case['key'],
                );
            }
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_interpolated_production_target_is_rejected_even_when_runtime_booted_non_production(): void
    {
        $contents = "TARGET_ENV=\"production\"\n".$this->environment([
            'APP_ENV' => '${TARGET_ENV}',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The installer production environment must use literal security-critical values.',
        );

        (new InstallerProductionEnvironmentPolicy('testing'))->assertSafe($contents);
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_production_target_is_enforced_even_when_runtime_booted_non_production(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The installer production environment is not safe to finalize.');

        (new InstallerProductionEnvironmentPolicy('testing'))
            ->assertSafe($this->environment(['APP_DEBUG' => 'true']));
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_non_production_runtime_rejects_duplicate_app_env_before_classifying_target(): void
    {
        $contents = $this->environment(['APP_ENV' => 'local'])."APP_ENV=\"production\"\n";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The installer production environment must define each security-critical setting exactly once.',
        );

        (new InstallerProductionEnvironmentPolicy('testing'))->assertSafe($contents);
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_production_requires_each_security_critical_setting_exactly_once(): void
    {
        $policy = new InstallerProductionEnvironmentPolicy('production');

        foreach ([
            str_replace("APP_URL=\"https://example.test\"\n", '', $this->environment()),
            $this->environment()."APP_DEBUG=\"false\"\n",
        ] as $contents) {
            try {
                $policy->assertSafe($contents);
                $this->fail('Missing or duplicate production security setting must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'The installer production environment must define each security-critical setting exactly once.',
                    $exception->getMessage(),
                );
            }
        }
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function test_invalid_dotenv_syntax_is_rejected_without_echoing_contents(): void
    {
        $marker = 'test-only-invalid-dotenv-marker';

        try {
            (new InstallerProductionEnvironmentPolicy('production'))
                ->assertSafe("APP_ENV=production\nSAFE_MARKER={$marker}\n=broken\n");
            $this->fail('Invalid dotenv syntax must be rejected before persistence.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The installer environment file is invalid.', $exception->getMessage());
            $this->assertStringNotContainsString($marker, $exception->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function environment(array $overrides = []): string
    {
        $values = array_replace([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://example.test',
            'SESSION_ENCRYPT' => 'true',
            'SESSION_SECURE_COOKIE' => 'true',
        ], $overrides);

        $lines = [];

        foreach ($values as $key => $value) {
            $lines[] = $key.'="'.$value.'"';
        }

        return implode("\n", $lines)."\n";
    }
}
