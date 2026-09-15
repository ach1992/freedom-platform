<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Application\TrialMembershipVerifier;
use DomainException;
use Illuminate\Database\Connection;

final class UnavailableTrialMembershipVerifier implements TrialMembershipVerifier
{
    public function assertSatisfied(Connection $connection, int $userId, int $offeringId, int $policyId): void
    {
        throw new DomainException('Trial membership verification is unavailable.');
    }
}
