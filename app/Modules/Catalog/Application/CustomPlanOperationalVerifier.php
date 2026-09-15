<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Database\Connection;

interface CustomPlanOperationalVerifier
{
    /** @requirement CAT-004 CAT-005 CAT-008 SEC-002 */
    public function assertOperational(Connection $connection, int $offeringId): void;
}
