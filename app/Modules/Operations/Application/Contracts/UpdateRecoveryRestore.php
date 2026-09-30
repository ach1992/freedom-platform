<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\RestoreRunResult;

interface UpdateRecoveryRestore
{
    public function recoverUpdate(string $updateRunId, bool $apply = false): RestoreRunResult;
}
