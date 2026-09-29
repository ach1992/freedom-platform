<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemRestorePayloadRestorer;
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
            $restorer = new FilesystemRestorePayloadRestorer(
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
            $restorer = new FilesystemRestorePayloadRestorer(
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
                self::assertSame('A restore configuration target is unsafe.', $exception->getMessage());
            }
        } finally {
            $this->removeTree($base);
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
            $restorer = new FilesystemRestorePayloadRestorer(
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
