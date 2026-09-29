<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Application\Contracts\RestorePayloadFilesystem;
use App\Modules\Operations\Infrastructure\FilesystemRestorePayloadRestorer;
use App\Modules\Operations\Infrastructure\NativeRestorePayloadFilesystem;
use RuntimeException;
use Tests\TestCase;

final class FilesystemRestorePayloadRestorerTest extends TestCase
{
    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_restore_stages_and_replaces_config_and_private_payload_without_leaving_swap_state(): void
    {
        $base = $this->directory('success');
        $targetConfig = $base.'/.env';
        $targetPrivate = $base.'/private';
        $entries = $this->entries($base);

        file_put_contents($targetConfig, 'old-config');
        mkdir($targetPrivate, 0700);
        file_put_contents($targetPrivate.'/old.txt', 'old-private');

        try {
            $restorer = $this->restorer(
                ['environment' => $targetConfig],
                ['application' => $targetPrivate],
            );

            $restorer->preflight($entries);
            $restorer->restore($entries, '20260929T040000Z-1111111111111111');

            self::assertSame('new-config', file_get_contents($targetConfig));
            self::assertFileDoesNotExist($targetPrivate.'/old.txt');
            self::assertSame('new-private', file_get_contents($targetPrivate.'/nested/new.txt'));
            self::assertSame(0600, fileperms($targetConfig) & 0777);
            self::assertSame(0700, fileperms($targetPrivate) & 0777);
            self::assertSame([], glob($base.'/.restore-*') ?: []);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_preflight_rejects_unknown_targets_and_symlink_destination(): void
    {
        $base = $this->directory('unsafe');
        $targetConfig = $base.'/.env';
        $targetPrivate = $base.'/private';
        mkdir($targetPrivate, 0700);
        file_put_contents($targetConfig, 'old-config');
        $entries = $this->entries($base);

        try {
            $restorer = $this->restorer(
                ['environment' => $targetConfig],
                ['application' => $targetPrivate],
            );

            $unknown = $entries;
            $unknown['config/unowned'] = $entries['config/environment'];
            try {
                $restorer->preflight($unknown);
                self::fail('Unknown restore target must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'The restore bundle contains an unknown configuration target.',
                    $exception->getMessage(),
                );
            }

            unlink($targetConfig);
            symlink($entries['config/environment'], $targetConfig);

            try {
                $restorer->restore($entries, '20260929T040001Z-2222222222222222');
                self::fail('Symlink restore destination must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('Restore critical environment authority is unavailable.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 OPS-003 SEC-001 QUA-001 */
    public function test_preflight_rejects_database_redis_or_maintenance_authority_drift(): void
    {
        $cases = [
            'database' => [
                "DB_HOST=db-a.internal\nREDIS_DB=0\nAPP_MAINTENANCE_DRIVER=file\n",
                "DB_HOST=db-b.internal\nREDIS_DB=0\nAPP_MAINTENANCE_DRIVER=file\n",
            ],
            'redis' => [
                "DB_HOST=db-a.internal\nREDIS_DB=0\nAPP_MAINTENANCE_DRIVER=file\n",
                "DB_HOST=db-a.internal\nREDIS_DB=9\nAPP_MAINTENANCE_DRIVER=file\n",
            ],
            'maintenance' => [
                "DB_HOST=db-a.internal\nREDIS_DB=0\nAPP_MAINTENANCE_DRIVER=file\n",
                "DB_HOST=db-a.internal\nREDIS_DB=0\nAPP_MAINTENANCE_DRIVER=cache\n",
            ],
        ];

        foreach ($cases as $case => [$currentEnvironment, $candidateEnvironment]) {
            $base = $this->directory('authority-'.$case);
            $targetConfig = $base.'/.env';
            $entries = $this->entries($base);
            unset($entries['private/application/nested/new.txt']);
            file_put_contents($targetConfig, $currentEnvironment);
            file_put_contents($entries['config/environment'], $candidateEnvironment);

            try {
                $restorer = $this->restorer(['environment' => $targetConfig], []);

                try {
                    $restorer->preflight($entries);
                    self::fail('Critical restore authority drift must fail before mutation.');
                } catch (RuntimeException $exception) {
                    self::assertSame(
                        'Restore critical runtime authority configuration does not match the current deployment.',
                        $exception->getMessage(),
                    );
                }
            } finally {
                $this->removeTree($base);
            }
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_staging_failure_removes_plaintext_swap_files_before_rethrow(): void
    {
        $base = $this->directory('staging-failure');
        $targetConfig = $base.'/.env';
        $entries = $this->entries($base);

        unset($entries['private/application/nested/new.txt']);
        $secondary = $base.'/extracted/config/secondary';
        file_put_contents($secondary, 'secondary-config');
        $entries['config/secondary'] = $secondary;
        file_put_contents($targetConfig, 'old-config');

        try {
            $restorer = $this->restorer(
                [
                    'environment' => $targetConfig,
                    'secondary' => $base.'/missing-parent/.secondary',
                ],
                [],
            );

            try {
                $restorer->restore($entries, '20260929T040002Z-3333333333333333');
                self::fail('A later staging failure must abort before payload swap.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'A restore configuration target parent is unavailable.',
                    $exception->getMessage(),
                );
            }

            self::assertSame('old-config', file_get_contents($targetConfig));
            self::assertSame([], glob($base.'/.restore-*') ?: []);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_target_to_recovery_failure_does_not_cross_activation_boundary(): void
    {
        $base = $this->directory('recovery-entry-failure');
        $targetConfig = $base.'/.env';
        $entries = $this->entries($base);
        unset($entries['private/application/nested/new.txt']);
        file_put_contents($targetConfig, 'old-config');

        try {
            $filesystem = new FaultInjectingRestorePayloadFilesystem([1]);
            $restorer = $this->restorer(['environment' => $targetConfig], [], $filesystem);

            try {
                $restorer->restore($entries, '20260929T040003Z-4444444444444444');
                self::fail('Original target recovery staging failure must abort activation.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'A restore target could not enter protected recovery state.',
                    $exception->getMessage(),
                );
            }

            self::assertSame('old-config', file_get_contents($targetConfig));
            self::assertSame([], glob($base.'/.restore-*') ?: []);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_stage_activation_failure_restores_current_operation_and_cleans_plaintext_swap_state(): void
    {
        $base = $this->directory('activation-failure');
        $targetConfig = $base.'/.env';
        $entries = $this->entries($base);
        unset($entries['private/application/nested/new.txt']);
        file_put_contents($targetConfig, 'old-config');

        try {
            $filesystem = new FaultInjectingRestorePayloadFilesystem([2]);
            $restorer = $this->restorer(['environment' => $targetConfig], [], $filesystem);

            try {
                $restorer->restore($entries, '20260929T040004Z-5555555555555555');
                self::fail('Staged target activation failure must roll the original target back.');
            } catch (RuntimeException $exception) {
                self::assertSame('A staged restore target could not be activated.', $exception->getMessage());
            }

            self::assertSame('old-config', file_get_contents($targetConfig));
            self::assertSame([], glob($base.'/.restore-*') ?: []);
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_failed_immediate_rollback_retains_original_only_inside_protected_recovery_boundary(): void
    {
        $base = $this->directory('rollback-failure');
        $targetConfig = $base.'/.env';
        $entries = $this->entries($base);
        unset($entries['private/application/nested/new.txt']);
        file_put_contents($targetConfig, 'old-config');

        try {
            $filesystem = new FaultInjectingRestorePayloadFilesystem([2, 3]);
            $restorer = $this->restorer(['environment' => $targetConfig], [], $filesystem);

            try {
                $restorer->restore($entries, '20260929T040005Z-6666666666666666');
                self::fail('Incomplete rollback must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertSame('Restore payload rollback or cleanup was incomplete.', $exception->getMessage());
            }

            self::assertFileDoesNotExist($targetConfig);
            self::assertSame([], glob($base.'/.restore-*.next') ?: []);
            self::assertSame([], glob($base.'/.restore-*.previous') ?: []);

            $recoveryDirectories = glob($base.'/.restore-recovery-*') ?: [];
            self::assertCount(1, $recoveryDirectories);
            self::assertSame(0700, fileperms($recoveryDirectories[0]) & 0777);
            $recoveryEntries = array_values(array_diff(scandir($recoveryDirectories[0]) ?: [], ['.', '..']));
            self::assertCount(1, $recoveryEntries);
            self::assertSame(
                'old-config',
                file_get_contents($recoveryDirectories[0].'/'.$recoveryEntries[0]),
            );
        } finally {
            $this->removeTree($base);
        }
    }

    /** @requirement BAK-002 SEC-001 QUA-001 */
    public function test_later_activation_failure_rolls_back_every_committed_target(): void
    {
        $base = $this->directory('multi-rollback');
        $firstTarget = $base.'/.env';
        $secondTarget = $base.'/.secondary';
        $entries = $this->entries($base);
        unset($entries['private/application/nested/new.txt']);
        $secondSource = $base.'/extracted/config/secondary';
        file_put_contents($secondSource, 'new-secondary');
        $entries['config/secondary'] = $secondSource;
        file_put_contents($firstTarget, 'old-first');
        file_put_contents($secondTarget, 'old-second');

        try {
            $filesystem = new FaultInjectingRestorePayloadFilesystem([4]);
            $restorer = $this->restorer(
                ['environment' => $firstTarget, 'secondary' => $secondTarget],
                [],
                $filesystem,
            );

            try {
                $restorer->restore($entries, '20260929T040006Z-7777777777777777');
                self::fail('A later target activation failure must roll all prior targets back.');
            } catch (RuntimeException $exception) {
                self::assertSame('A staged restore target could not be activated.', $exception->getMessage());
            }

            self::assertSame('old-first', file_get_contents($firstTarget));
            self::assertSame('old-second', file_get_contents($secondTarget));
            self::assertSame([], glob($base.'/.restore-*') ?: []);
        } finally {
            $this->removeTree($base);
        }
    }

    /**
     * @param  array<string, string>  $configFiles
     * @param  array<string, string>  $privateDirectories
     */
    private function restorer(
        array $configFiles,
        array $privateDirectories,
        ?RestorePayloadFilesystem $filesystem = null,
    ): FilesystemRestorePayloadRestorer {
        return new FilesystemRestorePayloadRestorer(
            $configFiles,
            $privateDirectories,
            $filesystem ?? new NativeRestorePayloadFilesystem,
        );
    }

    /**
     * @return array<string, string>
     */
    private function entries(string $base): array
    {
        $extracted = $base.'/extracted';
        mkdir($extracted.'/database', 0700, true);
        mkdir($extracted.'/config', 0700, true);
        mkdir($extracted.'/private/application/nested', 0700, true);

        file_put_contents($extracted.'/database/database.sql', 'database');
        file_put_contents($extracted.'/config/environment', 'new-config');
        file_put_contents($extracted.'/private/application/nested/new.txt', 'new-private');

        return [
            'database/database.sql' => $extracted.'/database/database.sql',
            'config/environment' => $extracted.'/config/environment',
            'private/application/nested/new.txt' => $extracted.'/private/application/nested/new.txt',
        ];
    }

    private function directory(string $case): string
    {
        $path = storage_path('framework/testing/restore-payload-'.$case.'-'.bin2hex(random_bytes(4)));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path.'/'.$name);
            }
        }

        @rmdir($path);
    }
}

final class FaultInjectingRestorePayloadFilesystem implements RestorePayloadFilesystem
{
    private int $moveCalls = 0;

    private int $removeCalls = 0;

    /**
     * @param  list<int>  $failedMoveCalls
     * @param  list<int>  $failedRemoveCalls
     */
    public function __construct(
        private readonly array $failedMoveCalls = [],
        private readonly array $failedRemoveCalls = [],
        private readonly NativeRestorePayloadFilesystem $native = new NativeRestorePayloadFilesystem,
    ) {}

    public function move(string $source, string $destination): bool
    {
        $this->moveCalls++;

        if (in_array($this->moveCalls, $this->failedMoveCalls, true)) {
            return false;
        }

        return $this->native->move($source, $destination);
    }

    public function remove(string $path): void
    {
        $this->removeCalls++;

        if (in_array($this->removeCalls, $this->failedRemoveCalls, true)) {
            throw new RuntimeException('test-only restore payload removal failure');
        }

        $this->native->remove($path);
    }
}
