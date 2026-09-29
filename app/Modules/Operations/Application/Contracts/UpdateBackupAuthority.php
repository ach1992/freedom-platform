<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\BackupArtifact;

interface UpdateBackupAuthority
{
    public function createVerifiedPreUpdate(): BackupArtifact;
}
