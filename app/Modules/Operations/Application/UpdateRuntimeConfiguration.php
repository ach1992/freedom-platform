<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use RuntimeException;

final readonly class UpdateRuntimeConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $deploymentRoot,
        public string $packageRoot,
        public string $phpBinary,
        public string $composerBinary,
        public string $runUser,
        public int $processTimeoutSeconds,
        public int $releaseRetention,
    ) {}

    /** @param array<string, mixed> $configuration */
    public static function fromArray(array $configuration): self
    {
        $deploymentRoot = self::absolutePath($configuration['deployment_root'] ?? null, 'Update deployment root');
        $packageRoot = self::absolutePath($configuration['package_root'] ?? null, 'Update package root');
        $phpBinary = self::absolutePath($configuration['php_binary'] ?? null, 'Update PHP binary');
        $composerBinary = self::absolutePath($configuration['composer_binary'] ?? null, 'Update Composer binary');

        $runUser = $configuration['run_user'] ?? null;
        if (! is_string($runUser)
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/', $runUser) !== 1
        ) {
            throw new RuntimeException('Update runtime user configuration is invalid.');
        }

        return new self(
            enabled: ($configuration['enabled'] ?? false) === true,
            deploymentRoot: rtrim($deploymentRoot, DIRECTORY_SEPARATOR),
            packageRoot: rtrim($packageRoot, DIRECTORY_SEPARATOR),
            phpBinary: $phpBinary,
            composerBinary: $composerBinary,
            runUser: $runUser,
            processTimeoutSeconds: self::boundedInteger(
                $configuration['process_timeout_seconds'] ?? null,
                30,
                3600,
                'Update process timeout',
            ),
            releaseRetention: self::boundedInteger(
                $configuration['release_retention'] ?? null,
                2,
                20,
                'Update release retention',
            ),
        );
    }

    private static function absolutePath(mixed $value, string $label): string
    {
        if (! is_string($value)
            || $value === ''
            || ! str_starts_with($value, DIRECTORY_SEPARATOR)
            || str_contains($value, "\0")
        ) {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return $value;
    }

    private static function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $minimum, 'max_range' => $maximum]],
        );

        if ($validated === false) {
            throw new RuntimeException($label.' configuration is invalid.');
        }

        return (int) $validated;
    }
}
