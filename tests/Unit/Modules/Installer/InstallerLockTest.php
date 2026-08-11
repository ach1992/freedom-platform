<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Installer;

use App\Modules\Installer\Application\InstallerLock;
use Tests\TestCase;

final class InstallerLockTest extends TestCase
{
    public function test_lock_is_created_atomically_and_detected(): void
    {
        $path = storage_path('framework/testing/installer-lock.json');
        @unlink($path);

        $lock = new InstallerLock($path);

        $this->assertFalse($lock->exists());

        $lock->activate();

        $this->assertTrue($lock->exists());

        @unlink($path);
    }
}
