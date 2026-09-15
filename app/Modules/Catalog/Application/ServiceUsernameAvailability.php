<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Database\Connection;

interface ServiceUsernameAvailability
{
    /** @requirement CAT-005 SEC-002 */
    public function assertAvailable(Connection $connection, string $normalizedUsername, ?int $calculationId = null): void;
}
