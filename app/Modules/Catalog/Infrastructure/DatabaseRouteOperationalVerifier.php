<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use Illuminate\Database\Connection;

final class DatabaseRouteOperationalVerifier implements RouteOperationalVerifier
{
    public function assertOperational(
        Connection $connection,
        int $offeringId,
        int $salesServerId,
        int $serviceTargetId,
        int $protocolProfileId,
    ): void {
        /** @var object{state: string, visibility: string}|null $offering */
        $offering = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->first(['state', 'visibility']);
        if ($offering === null || $offering->state !== 'active' || $offering->visibility !== 'visible') {
            throw new RouteCandidateUnavailable('Offering is not operational.');
        }

        /** @var object{state: string, visibility: string}|null $server */
        $server = $connection->table('sales_servers')
            ->where('id', $salesServerId)
            ->first(['state', 'visibility']);
        if ($server === null || $server->state !== 'active' || $server->visibility !== 'listed') {
            throw new RouteCandidateUnavailable('Sales server is not operational.');
        }

        /** @var object{panel_connection_id: int|string, state: string, capability_status: string, verified_connection_version: int|string|null}|null $target */
        $target = $connection->table('panel_service_targets')
            ->where('id', $serviceTargetId)
            ->first(['panel_connection_id', 'state', 'capability_status', 'verified_connection_version']);
        if ($target === null || $target->state !== 'active' || $target->capability_status !== 'verified') {
            throw new RouteCandidateUnavailable('Service target is not operational.');
        }

        /** @var object{state: string, last_test_status: ?string, version: int|string}|null $panelConnection */
        $panelConnection = $connection->table('panel_connections')
            ->where('id', (int) $target->panel_connection_id)
            ->first(['state', 'last_test_status', 'version']);
        if ($panelConnection === null
            || $panelConnection->state !== 'active'
            || $panelConnection->last_test_status !== 'success'
            || (int) $target->verified_connection_version !== (int) $panelConnection->version
        ) {
            throw new RouteCandidateUnavailable('Panel connection evidence is not operational.');
        }

        $profileMatches = $connection->table('panel_protocol_profiles as profile')
            ->join(
                'panel_target_protocol_profiles as assignment',
                'assignment.panel_protocol_profile_id',
                '=',
                'profile.id',
            )
            ->where('profile.id', $protocolProfileId)
            ->where('profile.state', 'active')
            ->where('assignment.panel_service_target_id', $serviceTargetId)
            ->exists();
        if (! $profileMatches) {
            throw new RouteCandidateUnavailable('Protocol profile is not operational on the route.');
        }

        /** @var list<int|string> $required */
        $required = $connection->table('plan_offering_required_capabilities')
            ->where('plan_offering_id', $offeringId)
            ->pluck('capability_code')
            ->all();
        /** @var list<int|string> $operations */
        $operations = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', $offeringId)
            ->whereNotNull('required_capability_code')
            ->pluck('required_capability_code')
            ->all();
        $capabilities = array_values(array_unique(array_map(
            static fn (int|string $code): string => (string) $code,
            array_merge($required, $operations),
        )));
        if ($capabilities !== []
            && $connection->table('panel_target_capabilities')
                ->where('panel_service_target_id', $serviceTargetId)
                ->whereIn('capability_code', $capabilities)
                ->where('verification_status', 'verified')
                ->count() !== count($capabilities)
        ) {
            throw new RouteCandidateUnavailable('Route capabilities are not verified.');
        }
    }
}
