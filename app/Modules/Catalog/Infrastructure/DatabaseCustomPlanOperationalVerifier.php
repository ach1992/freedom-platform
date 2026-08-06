<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Application\CustomPlanOperationalVerifier;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use Illuminate\Database\Connection;

final readonly class DatabaseCustomPlanOperationalVerifier implements CustomPlanOperationalVerifier
{
    public function __construct(private RouteOperationalVerifier $routeVerifier) {}

    public function assertOperational(Connection $connection, int $offeringId): void
    {
        /** @var object{sales_server_id: int|string, panel_service_target_id: int|string}|null $offering */
        $offering = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->first(['sales_server_id', 'panel_service_target_id']);
        if ($offering === null) {
            throw new RouteCandidateUnavailable('Custom-plan Offering does not exist.');
        }

        $profileId = $connection->table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $offeringId)
            ->where('is_default', true)
            ->value('panel_protocol_profile_id');
        if (! is_int($profileId) && ! is_string($profileId)) {
            throw new RouteCandidateUnavailable('Custom-plan Offering has no default protocol profile.');
        }

        $this->routeVerifier->assertOperational(
            $connection,
            $offeringId,
            (int) $offering->sales_server_id,
            (int) $offering->panel_service_target_id,
            (int) $profileId,
        );
    }
}
