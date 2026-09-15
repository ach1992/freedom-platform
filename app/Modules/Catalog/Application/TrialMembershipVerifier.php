<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Database\Connection;

interface TrialMembershipVerifier
{
    public function assertSatisfied(Connection $connection, int $userId, int $offeringId, int $policyId): void;
}
