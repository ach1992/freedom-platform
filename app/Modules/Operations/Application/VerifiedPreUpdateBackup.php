<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

final readonly class VerifiedPreUpdateBackup
{
    public function __construct(
        public string $backupId,
        public string $completedAt,
    ) {}
}
