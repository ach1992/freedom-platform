<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;

interface OutboxOperationalSnapshotSource
{
    public function snapshot(DateTimeImmutable $now): OutboxOperationalSnapshot;
}
