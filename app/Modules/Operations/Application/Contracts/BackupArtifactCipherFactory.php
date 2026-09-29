<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupArtifactCipherFactory
{
    public function create(string $key): BackupArtifactCipher;
}
