<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use App\Modules\Operations\Application\OperationsCenterFact;
use DateTimeImmutable;

interface TelegramOperationsSnapshotSource
{
    /** @return list<OperationsCenterFact> */
    public function facts(DateTimeImmutable $now): array;
}
