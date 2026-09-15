<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\PhpRuntimePreflight;
use PHPUnit\Framework\TestCase;

final class PhpRuntimePreflightTest extends TestCase
{
    /** @requirement INS-001 QUA-011 */
    public function test_it_accepts_a_supported_runtime_with_utc_and_required_extensions(): void
    {
        $preflight = new PhpRuntimePreflight(
            static fn (string $binary, string $script): array => [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'php_version' => '8.4.16',
                    'sapi' => 'cli',
                    'loaded_extensions' => ['json', 'pdo', 'redis'],
                    'ini_file' => '/etc/php/8.4/cli/php.ini',
                    'timezone' => 'UTC',
                    'disabled_functions' => ['exec'],
                    'limits' => [
                        'memory_limit' => '512M',
                        'max_execution_time' => '0',
                        'post_max_size' => '8M',
                        'upload_max_filesize' => '2M',
                    ],
                    'opcache_enabled' => true,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $result = $preflight->inspect('cli', PHP_BINARY, ['json', 'pdo', 'redis']);

        self::assertTrue($result['reachable']);
        self::assertTrue($result['passed']);
        self::assertSame('8.4.16', $result['php_version']);
        self::assertSame([], $result['missing_extensions']);
        self::assertSame(['exec'], $result['disabled_functions']);
        self::assertTrue($result['opcache_enabled']);
        self::assertNull($result['error']);
    }

    public function test_it_reports_policy_failures_without_exposing_process_output(): void
    {
        $preflight = new PhpRuntimePreflight(
            static fn (string $binary, string $script): array => [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'php_version' => '8.3.9',
                    'sapi' => 'cli',
                    'loaded_extensions' => ['json'],
                    'ini_file' => null,
                    'timezone' => 'Asia/Tehran',
                    'disabled_functions' => [],
                    'limits' => [],
                    'opcache_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $result = $preflight->inspect('cli', PHP_BINARY, ['json', 'pdo_mysql']);

        self::assertTrue($result['reachable']);
        self::assertFalse($result['passed']);
        self::assertFalse($result['version_supported']);
        self::assertFalse($result['timezone_utc']);
        self::assertNull($result['ini_file']);
        self::assertSame(['pdo_mysql'], $result['missing_extensions']);
        self::assertNull($result['error']);
    }

    /** @requirement INS-001 QUA-011 */
    public function test_it_accepts_gd_when_all_supported_image_decoder_capabilities_are_available(): void
    {
        $preflight = new PhpRuntimePreflight(
            static fn (string $binary, string $script): array => [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'php_version' => '8.4.16',
                    'sapi' => 'cli',
                    'loaded_extensions' => ['gd'],
                    'ini_file' => '/etc/php/8.4/cli/php.ini',
                    'timezone' => 'UTC',
                    'image_decoder_capabilities' => [
                        'jpeg' => true,
                        'png' => true,
                        'webp' => true,
                    ],
                    'disabled_functions' => [],
                    'limits' => [],
                    'opcache_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $result = $preflight->inspect('cli', PHP_BINARY, ['gd']);

        self::assertTrue($result['reachable']);
        self::assertTrue($result['passed']);
        self::assertTrue($result['image_decoders_supported']);
        self::assertSame([], $result['missing_extensions']);
        self::assertNull($result['error']);
    }

    /** @requirement INS-001 QUA-011 */
    public function test_it_requires_all_supported_image_decoder_capabilities_when_gd_is_required(): void
    {
        $preflight = new PhpRuntimePreflight(
            static fn (string $binary, string $script): array => [
                'exit_code' => 0,
                'stdout' => json_encode([
                    'php_version' => '8.4.16',
                    'sapi' => 'cli',
                    'loaded_extensions' => ['gd'],
                    'ini_file' => '/etc/php/8.4/cli/php.ini',
                    'timezone' => 'UTC',
                    'image_decoder_capabilities' => [
                        'jpeg' => true,
                        'png' => true,
                        'webp' => false,
                    ],
                    'disabled_functions' => [],
                    'limits' => [],
                    'opcache_enabled' => false,
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $result = $preflight->inspect('cli', PHP_BINARY, ['gd']);

        self::assertTrue($result['reachable']);
        self::assertFalse($result['passed']);
        self::assertSame([], $result['missing_extensions']);
        self::assertSame([
            'jpeg' => true,
            'png' => true,
            'webp' => false,
        ], $result['image_decoder_capabilities']);
        self::assertFalse($result['image_decoders_supported']);
        self::assertNull($result['error']);
    }

    public function test_it_rejects_a_non_absolute_or_non_executable_binary_before_running_a_process(): void
    {
        $runs = 0;
        $preflight = new PhpRuntimePreflight(
            static function (string $binary, string $script) use (&$runs): array {
                $runs++;

                return ['exit_code' => 0, 'stdout' => '{}'];
            },
        );

        $result = $preflight->inspect('lsphp', 'php', ['json']);

        self::assertFalse($result['reachable']);
        self::assertFalse($result['passed']);
        self::assertSame('invalid_binary_path', $result['error']);
        self::assertSame(0, $runs);
    }

    public function test_it_rejects_malformed_probe_output_safely(): void
    {
        $preflight = new PhpRuntimePreflight(
            static fn (string $binary, string $script): array => [
                'exit_code' => 0,
                'stdout' => 'not-json',
            ],
        );

        $result = $preflight->inspect('cli', PHP_BINARY, ['json']);

        self::assertFalse($result['reachable']);
        self::assertFalse($result['passed']);
        self::assertSame('invalid_probe_output', $result['error']);
        self::assertSame(['json'], $result['missing_extensions']);
    }
}
