<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Catalog\Domain\RouteSelectionActor;
use App\Modules\Panels\Application\CapacityOperationContext;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class TrialRouteSelector
{
    public function __construct(
        private RouteOperationalVerifier $verifier,
        private TargetCapacityAllocator $capacity,
        private Clock $clock,
    ) {}

    /** @requirement CAT-006 CAT-008 SEC-002 DAT-003 QUA-001 */
    public function select(
        Connection $connection,
        TrialReservationRequest $request,
        bool $fallbackAllowed,
        TrialContext $context,
    ): RouteSelectionReceipt {
        $commandKey = 'trial-route:'.hash('sha256', $context->commandKey);
        $payloadHash = CatalogPayloadHash::make([
            ...$request->payload(),
            'fallback_allowed' => $fallbackAllowed,
        ]);
        $existing = $this->existing($connection, $commandKey, $payloadHash, true);
        if ($existing !== null) {
            return $existing;
        }

        $offering = $this->lockedOffering($connection, $request->offeringId);
        $protocolProfileId = $this->resolveProtocol(
            $connection,
            $offering,
            $request->requestedProtocolProfileId,
        );
        $policy = $this->lockedPolicy($connection, $request->offeringId);
        $routes = $this->lockedRoutes($connection, $policy->id);
        $candidates = $this->orderedCandidates(
            $routes,
            $offering->server_selection_mode,
            $request->requestedRouteId,
            $fallbackAllowed,
        );

        foreach ($candidates as $position => $route) {
            try {
                $this->verifier->assertOperational(
                    $connection,
                    $request->offeringId,
                    $route->sales_server_id,
                    $route->panel_service_target_id,
                    $protocolProfileId,
                );
                $capacityReservation = $this->capacity->reserve(
                    $route->panel_service_target_id,
                    1,
                    $request->expiresAt,
                    new CapacityOperationContext(
                        'trial-route-capacity:'.substr(hash('sha256', $commandKey.':'.$route->id), 0, 64),
                        $context->correlationId,
                        'trial',
                        'trial_route',
                        'trial_route_hold',
                    ),
                );
            } catch (RouteCandidateUnavailable) {
                continue;
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'Insufficient target capacity.') {
                    throw $exception;
                }

                continue;
            }

            $fallbackUsed = $position > 0;
            $selectionId = (int) $connection->table('plan_offering_route_selections')->insertGetId([
                'command_key' => $commandKey,
                'payload_hash' => $payloadHash,
                'plan_offering_id' => $request->offeringId,
                'plan_offering_route_policy_id' => $policy->id,
                'plan_offering_route_id' => $route->id,
                'capacity_reservation_id' => $capacityReservation->reservationId,
                'user_id' => $request->userId,
                'actor_type' => RouteSelectionActor::Customer->value,
                'selection_mode' => $offering->server_selection_mode,
                'requested_route_id' => $request->requestedRouteId,
                'panel_protocol_profile_id' => $protocolProfileId,
                'units' => 1,
                'fallback_used' => $fallbackUsed,
                'disclosure_fa_snapshot' => $fallbackUsed ? $route->disclosure_fa : null,
                'selected_sales_server_id' => $route->sales_server_id,
                'selected_service_target_id' => $route->panel_service_target_id,
                'capacity_hard_limit' => $capacityReservation->availability->hardLimit,
                'capacity_held_units' => $capacityReservation->availability->heldUnits,
                'capacity_committed_units' => $capacityReservation->availability->committedUnits,
                'capacity_available_units' => $capacityReservation->availability->availableUnits,
                'capacity_version' => $capacityReservation->availability->version,
                'capacity_reservation_version' => $capacityReservation->reservationVersion,
                'correlation_id' => $context->correlationId,
                'source_code' => 'trial',
                'reason_code' => 'trial_route_hold',
                'created_at' => $this->timestamp(),
            ]);

            return new RouteSelectionReceipt(
                $selectionId,
                $request->offeringId,
                $policy->id,
                $route->id,
                $route->sales_server_id,
                $route->panel_service_target_id,
                $protocolProfileId,
                $capacityReservation->reservationId,
                $capacityReservation->reservationKey,
                1,
                $fallbackUsed,
                $fallbackUsed ? $route->disclosure_fa : null,
                $capacityReservation->availability->availableUnits,
                $capacityReservation->availability->version,
            );
        }

        throw new RouteCandidateUnavailable('No configured trial route has available capacity.');
    }

    /** @return object{id: int, server_selection_mode: string, protocol_selection_mode: string} */
    private function lockedOffering(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, server_selection_mode: string, protocol_selection_mode: string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'server_selection_mode', 'protocol_selection_mode']);
        if ($row === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }

        return (object) [
            'id' => (int) $row->id,
            'server_selection_mode' => $row->server_selection_mode,
            'protocol_selection_mode' => $row->protocol_selection_mode,
        ];
    }

    /** @param object{id: int, protocol_selection_mode: string} $offering */
    private function resolveProtocol(
        Connection $connection,
        object $offering,
        ?int $requestedProtocolProfileId,
    ): int {
        $query = $connection->table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $offering->id);
        if ($offering->protocol_selection_mode === 'customer_selects') {
            if ($requestedProtocolProfileId === null) {
                throw new DomainException('Customer protocol selection requires a requested profile.');
            }
            $profileId = $query
                ->where('panel_protocol_profile_id', $requestedProtocolProfileId)
                ->where('customer_selectable', true)
                ->value('panel_protocol_profile_id');
            if ($profileId === null) {
                throw new DomainException('Requested protocol profile is not selectable.');
            }

            return (int) $profileId;
        }
        if ($requestedProtocolProfileId !== null) {
            throw new DomainException('Requested protocol profile is not allowed for this offering.');
        }
        $profileId = $query->where('is_default', true)->value('panel_protocol_profile_id');
        if ($profileId === null) {
            throw new RuntimeException('Offering default protocol profile is unavailable.');
        }

        return (int) $profileId;
    }

    /** @return object{id: int} */
    private function lockedPolicy(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string}|null $row */
        $row = $connection->table('plan_offering_route_policies')
            ->where('plan_offering_id', $offeringId)
            ->lockForUpdate()
            ->first(['id']);
        if ($row === null) {
            throw new RouteCandidateUnavailable('Offering route policy is unavailable.');
        }

        return (object) ['id' => (int) $row->id];
    }

    /** @return list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, customer_selectable: bool, disclosure_fa: ?string}> */
    private function lockedRoutes(Connection $connection, int $policyId): array
    {
        /** @var list<object{id: int|string, sales_server_id: int|string, panel_service_target_id: int|string, route_type: string, customer_selectable: bool|int, disclosure_fa: ?string}> $rows */
        $rows = $connection->table('plan_offering_routes')
            ->where('plan_offering_route_policy_id', $policyId)
            ->orderBy('priority')
            ->lockForUpdate()
            ->get([
                'id', 'sales_server_id', 'panel_service_target_id',
                'route_type', 'customer_selectable', 'disclosure_fa',
            ])
            ->all();
        if ($rows === []) {
            throw new RouteCandidateUnavailable('Offering route policy has no routes.');
        }

        return array_map(
            static fn (object $row): object => (object) [
                'id' => (int) $row->id,
                'sales_server_id' => (int) $row->sales_server_id,
                'panel_service_target_id' => (int) $row->panel_service_target_id,
                'route_type' => $row->route_type,
                'customer_selectable' => (bool) $row->customer_selectable,
                'disclosure_fa' => $row->disclosure_fa,
            ],
            $rows,
        );
    }

    /**
     * @param  list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, customer_selectable: bool, disclosure_fa: ?string}>  $routes
     * @return list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, customer_selectable: bool, disclosure_fa: ?string}>
     */
    private function orderedCandidates(
        array $routes,
        string $modeValue,
        ?int $requestedRouteId,
        bool $fallbackAllowed,
    ): array {
        $mode = PlanOfferingServerSelectionMode::tryFrom($modeValue)
            ?? throw new RuntimeException('Stored offering server selection mode is invalid.');
        if ($mode === PlanOfferingServerSelectionMode::System) {
            if ($requestedRouteId !== null) {
                throw new DomainException('System-selected offering does not accept a requested route.');
            }

            return $fallbackAllowed ? $routes : [$routes[0]];
        }

        if ($requestedRouteId === null) {
            if ($mode === PlanOfferingServerSelectionMode::Customer) {
                throw new DomainException('Customer-selected offering requires a requested route.');
            }

            return $fallbackAllowed ? $routes : [$routes[0]];
        }

        $selected = null;
        foreach ($routes as $route) {
            if ($route->id === $requestedRouteId) {
                $selected = $route;
                break;
            }
        }
        if ($selected === null || ! $selected->customer_selectable) {
            throw new DomainException('Requested route is not customer selectable.');
        }
        if (! $fallbackAllowed) {
            return [$selected];
        }

        $fallbacks = array_values(array_filter(
            $routes,
            static fn (object $route): bool => $route->id !== $requestedRouteId
                && $route->route_type === PlanOfferingRouteType::Fallback->value,
        ));

        return [$selected, ...$fallbacks];
    }

    private function existing(
        Connection $connection,
        string $commandKey,
        string $payloadHash,
        bool $lock,
    ): ?RouteSelectionReceipt {
        $query = $connection->table('plan_offering_route_selections as selection')
            ->join('panel_capacity_reservations as reservation', 'reservation.id', '=', 'selection.capacity_reservation_id')
            ->where('selection.command_key', $commandKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id: int|string, payload_hash: string, plan_offering_id: int|string, plan_offering_route_policy_id: int|string, plan_offering_route_id: int|string, selected_sales_server_id: int|string, selected_service_target_id: int|string, panel_protocol_profile_id: int|string, capacity_reservation_id: int|string, reservation_key: string, units: int|string, fallback_used: bool|int, disclosure_fa_snapshot: ?string, capacity_available_units: int|string, capacity_version: int|string}|null $row */
        $row = $query->first([
            'selection.id', 'selection.payload_hash', 'selection.plan_offering_id',
            'selection.plan_offering_route_policy_id', 'selection.plan_offering_route_id',
            'selection.selected_sales_server_id', 'selection.selected_service_target_id',
            'selection.panel_protocol_profile_id', 'selection.capacity_reservation_id',
            'reservation.reservation_key', 'selection.units', 'selection.fallback_used',
            'selection.disclosure_fa_snapshot', 'selection.capacity_available_units', 'selection.capacity_version',
        ]);
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Trial route command key conflict.');
        }

        return new RouteSelectionReceipt(
            (int) $row->id,
            (int) $row->plan_offering_id,
            (int) $row->plan_offering_route_policy_id,
            (int) $row->plan_offering_route_id,
            (int) $row->selected_sales_server_id,
            (int) $row->selected_service_target_id,
            (int) $row->panel_protocol_profile_id,
            (int) $row->capacity_reservation_id,
            $row->reservation_key,
            (int) $row->units,
            (bool) $row->fallback_used,
            $row->disclosure_fa_snapshot,
            (int) $row->capacity_available_units,
            (int) $row->capacity_version,
            true,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
