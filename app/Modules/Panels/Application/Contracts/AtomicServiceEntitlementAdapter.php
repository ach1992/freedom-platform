<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

use DateTimeImmutable;

interface AtomicServiceEntitlementAdapter
{
    public function updateServiceEntitlements(
        string $idempotencyKey,
        string $remoteId,
        DateTimeImmutable $expiresAt,
        int $dataLimitBytes,
    ): PanelOperationResult;
}
