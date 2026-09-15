<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Database\Connection;

interface RouteOperationalVerifier
{
    /** @requirement CAT-004 CAT-008 SEC-002 */
    public function assertOperational(
        Connection $connection,
        int $offeringId,
        int $salesServerId,
        int $serviceTargetId,
        int $protocolProfileId,
    ): void;
}
