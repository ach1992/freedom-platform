<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use Closure;
use JsonException;
use Symfony\Component\Process\Process;
use Throwable;

final class PhpRuntimePreflight
{
    private const MINIMUM_VERSION = '8.4.0';

    /** @var list<string> */
    public const COMMON_REQUIRED_EXTENSIONS = [
        'bcmath',
        'curl',
        'dom',
        'fileinfo',
        'intl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'redis',
        'sodium',
        'tokenizer',
        'xml',
    ];

    /** @var null|Closure(string, string): array{exit_code: int, stdout: string} */
    private ?Closure $runner;

    /** @param  null|Closure(string, string): array{exit_code: int, stdout: string}  $runner */
    public function __construct(?Closure $runner = null)
    {
        $this->runner = $runner;
    }

    /**
     * @requirement INS-001 QUA-011
     *
     * @param  list<string>  $requiredExtensions
     * @return array{
     *     name: string,
     *     binary: string,
     *     reachable: bool,
     *     passed: bool,
     *     php_version: ?string,
     *     version_supported: bool,
     *     sapi: ?string,
     *     missing_extensions: list<string>,
     *     ini_file: ?string,
     *     timezone: ?string,
     *     timezone_utc: bool,
     *     disabled_functions: list<string>,
     *     limits: array{memory_limit: ?string, max_execution_time: ?string, post_max_size: ?string, upload_max_filesize: ?string},
     *     opcache_enabled: ?bool,
     *     error: ?string
     * }
     */
    public function inspect(
        string $name,
        string $binary,
        array $requiredExtensions = self::COMMON_REQUIRED_EXTENSIONS,
    ): array {
        $requiredExtensions = $this->stringList($requiredExtensions);

        if ($requiredExtensions === []) {
            return $this->failure($name, $binary, [], 'missing_extension_policy');
        }

        if ($binary === '' || str_contains($binary, "\0") || ! str_starts_with($binary, '/')) {
            return $this->failure($name, $binary, $requiredExtensions, 'invalid_binary_path');
        }

        if (! is_file($binary) || ! is_executable($binary)) {
            return $this->failure($name, $binary, $requiredExtensions, 'binary_not_executable');
        }

        try {
            $result = $this->run($binary);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failure($name, $binary, $requiredExtensions, 'probe_execution_failed');
        }

        if ($result['exit_code'] !== 0) {
            return $this->failure($name, $binary, $requiredExtensions, 'probe_exit_nonzero');
        }

        try {
            $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->failure($name, $binary, $requiredExtensions, 'invalid_probe_output');
        }

        if (! is_array($payload)) {
            return $this->failure($name, $binary, $requiredExtensions, 'invalid_probe_output');
        }

        $version = is_string($payload['php_version'] ?? null) ? $payload['php_version'] : null;
        $sapi = is_string($payload['sapi'] ?? null) ? $payload['sapi'] : null;
        $iniFile = is_string($payload['ini_file'] ?? null) && $payload['ini_file'] !== '' ? $payload['ini_file'] : null;
        $timezone = is_string($payload['timezone'] ?? null) ? $payload['timezone'] : null;
        $extensions = $this->stringList($payload['loaded_extensions'] ?? null);
        $disabledFunctions = $this->stringList($payload['disabled_functions'] ?? null);
        $missingExtensions = array_values(array_diff($requiredExtensions, $extensions));
        sort($missingExtensions);

        $limitsPayload = is_array($payload['limits'] ?? null) ? $payload['limits'] : [];
        $limits = [
            'memory_limit' => $this->nullableString($limitsPayload['memory_limit'] ?? null),
            'max_execution_time' => $this->nullableString($limitsPayload['max_execution_time'] ?? null),
            'post_max_size' => $this->nullableString($limitsPayload['post_max_size'] ?? null),
            'upload_max_filesize' => $this->nullableString($limitsPayload['upload_max_filesize'] ?? null),
        ];
        $opcacheEnabled = is_bool($payload['opcache_enabled'] ?? null) ? $payload['opcache_enabled'] : null;
        $versionSupported = $version !== null && version_compare($version, self::MINIMUM_VERSION, '>=');
        $timezoneUtc = $timezone === 'UTC';
        $passed = $versionSupported && $missingExtensions === [] && $iniFile !== null && $timezoneUtc;

        return [
            'name' => $name,
            'binary' => $binary,
            'reachable' => true,
            'passed' => $passed,
            'php_version' => $version,
            'version_supported' => $versionSupported,
            'sapi' => $sapi,
            'missing_extensions' => $missingExtensions,
            'ini_file' => $iniFile,
            'timezone' => $timezone,
            'timezone_utc' => $timezoneUtc,
            'disabled_functions' => $disabledFunctions,
            'limits' => $limits,
            'opcache_enabled' => $opcacheEnabled,
            'error' => null,
        ];
    }

    /** @return array{exit_code: int, stdout: string} */
    private function run(string $binary): array
    {
        if ($this->runner !== null) {
            return ($this->runner)($binary, $this->probeScript());
        }

        $process = new Process([$binary, '-r', $this->probeScript()], null, null, null, 10.0);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        $strings = array_values(array_unique($strings));
        sort($strings);

        return $strings;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $requiredExtensions
     * @return array{
     *     name: string,
     *     binary: string,
     *     reachable: false,
     *     passed: false,
     *     php_version: null,
     *     version_supported: false,
     *     sapi: null,
     *     missing_extensions: list<string>,
     *     ini_file: null,
     *     timezone: null,
     *     timezone_utc: false,
     *     disabled_functions: list<string>,
     *     limits: array{memory_limit: null, max_execution_time: null, post_max_size: null, upload_max_filesize: null},
     *     opcache_enabled: null,
     *     error: string
     * }
     */
    private function failure(string $name, string $binary, array $requiredExtensions, string $error): array
    {
        return [
            'name' => $name,
            'binary' => $binary,
            'reachable' => false,
            'passed' => false,
            'php_version' => null,
            'version_supported' => false,
            'sapi' => null,
            'missing_extensions' => $requiredExtensions,
            'ini_file' => null,
            'timezone' => null,
            'timezone_utc' => false,
            'disabled_functions' => [],
            'limits' => [
                'memory_limit' => null,
                'max_execution_time' => null,
                'post_max_size' => null,
                'upload_max_filesize' => null,
            ],
            'opcache_enabled' => null,
            'error' => $error,
        ];
    }

    private function probeScript(): string
    {
        return <<<'PHP'
$disabledFunctions = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
$payload = [
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'loaded_extensions' => array_values(get_loaded_extensions()),
    'ini_file' => php_ini_loaded_file() ?: null,
    'timezone' => date_default_timezone_get(),
    'disabled_functions' => $disabledFunctions,
    'limits' => [
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
    ],
    'opcache_enabled' => filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL),
];
echo json_encode($payload, JSON_THROW_ON_ERROR);
PHP;
    }
}
