<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupArtifactCipher;
use App\Modules\Operations\Application\Contracts\BackupArtifactCipherFactory;

final readonly class SodiumBackupCipherFactory implements BackupArtifactCipherFactory
{
    public function create(string $key): BackupArtifactCipher
    {
        return new SodiumBackupCipher($key);
    }
}
