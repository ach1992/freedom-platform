<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Operations;

use App\Modules\Operations\Infrastructure\FilesystemUpdateOperationLock;
use RuntimeException;
use Tests\TestCase;

final class FilesystemUpdateOperationLockTest extends TestCase
{
    public function test_lock_path_rejects_symlink_authority(): void
    {
        $root = storage_path('framework/testing/update-operation-lock-symlink-'.bin2hex(random_bytes(4)));
        mkdir($root.'/shared', 0700, true);
        $foreign = $root.'/foreign.lock';
        file_put_contents($foreign, '');
        symlink($foreign, $root.'/shared/update-operation.lock');

        try {
            $lock = new FilesystemUpdateOperationLock($root);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Update operation lock path is unsafe.');
            $lock->synchronized(static fn (): null => null);
        } finally {
            @unlink($root.'/shared/update-operation.lock');
            @unlink($foreign);
            @rmdir($root.'/shared');
            @rmdir($root);
        }
    }

    public function test_lock_serializes_controlled_update_operations_and_is_reusable_after_release(): void
    {
        $root = storage_path('framework/testing/update-operation-lock-'.bin2hex(random_bytes(4)));
        mkdir($root.'/shared', 0700, true);

        try {
            $first = new FilesystemUpdateOperationLock($root);
            $second = new FilesystemUpdateOperationLock($root);
            $entered = false;

            $first->synchronized(function () use ($second, &$entered): void {
                $entered = true;

                try {
                    $second->synchronized(static fn (): null => null);
                    self::fail('A concurrent controlled update operation must fail closed.');
                } catch (RuntimeException $exception) {
                    self::assertSame('Another controlled update operation is already active.', $exception->getMessage());
                }
            });

            self::assertTrue($entered);
            self::assertSame(0600, fileperms($root.'/shared/update-operation.lock') & 0777);
            self::assertSame('released', $second->synchronized(static fn (): string => 'released'));
        } finally {
            @unlink($root.'/shared/update-operation.lock');
            @rmdir($root.'/shared');
            @rmdir($root);
        }
    }
}
