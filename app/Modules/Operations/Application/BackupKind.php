<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

enum BackupKind: string
{
    case FrequentDatabase = 'frequent_database';
    case DailyFull = 'daily_full';
    case PreUpdate = 'pre_update';

    public function includesPrivateFiles(): bool
    {
        return $this !== self::FrequentDatabase;
    }

    public function requiresPriorityLock(): bool
    {
        return $this !== self::FrequentDatabase;
    }
}
