<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface RestoreSchedulerMutationLock
{
    public function acquire(int $timeoutSeconds): void;

    public function release(): void;

    public function held(): bool;
}
