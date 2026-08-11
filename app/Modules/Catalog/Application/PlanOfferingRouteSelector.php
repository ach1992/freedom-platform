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
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class PlanOfferingRouteSelector
{
    public function __construct(
        private DatabaseManager $database,
        private RouteOperationalVerifier $verifier,
        private TargetCapacityAllocator $capacity,
        private Clock $clock,
    ) {}

    /** @requirement CAT-002 CAT-004 CAT-008 SEC-002 DAT-003 QUA-001 */
    public function select(RouteSelectionRequest $request, RouteSelectionContext $context): RouteSelectionReceipt
    {
        if ($request->expiresAt <= $this->clock->now()) {
            throw new DomainException('Route capacity hold expiry must be in the future.');
        }
        $payloadHash = CatalogPayloadHash::make($request->payload());
        $existing = $this->existing($context->commandKey, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $request,
                $context,
                $payloadHash,
            ): RouteSelectionReceipt {
                $replay = $this->existing($context->commandKey, $payloadHash, $connection, true);
                if ($replay !== null) {
                    return $replay;
                }

                $actor = $this->authoritativeActor($connection, $request->userId, $request->actor);
                $offering = $this->lockedOffering($connection, $request->offeringId);
                $this->assertAudience($offering->audience, $request->actor);
                $this->assertEligibility($connection, $offering, $actor);
                $protocolProfileId = $this->resolveProtocol($connection, $offering, $request->requestedProtocolProfileId);
                $policy = $this->lockedPolicy($connection, $request->offeringId);
                $routes = $this->lockedRoutes($connection, $policy->id);
                $candidates = $this->orderedCandidates(
                    $routes,
                    $offering->server_selection_mode,
                    $request->requestedRouteId,
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

                        $capacityContext = new CapacityOperationContext(
                            'route-capacity:'.substr(hash('sha256', $context->commandKey.':'.$route->id), 0, 64),
                            $context->correlationId,
                            'route_selection',
                            'plan_offering',
                            'route_hold',
                        );
                        $reservation = $this->capacity->reserve(
                            $route->panel_service_target_id,
                            $request->units,
                            $request->expiresAt,
                            $capacityContext,
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
                        'command_key' => $context->commandKey,
                        'payload_hash' => $payloadHash,
                        'plan_offering_id' => $request->offeringId,
                        'plan_offering_route_policy_id' => $policy->id,
                        'plan_offering_route_id' => $route->id,
                        'capacity_reservation_id' => $reservation->reservationId,
                        'user_id' => $request->userId,
                        'actor_type' => $request->actor->value,
                        'selection_mode' => $offering->server_selection_mode,
                        'requested_route_id' => $request->requestedRouteId,
                        'panel_protocol_profile_id' => $protocolProfileId,
                        'units' => $request->units,
                        'fallback_used' => $fallbackUsed,
                        'disclosure_fa_snapshot' => $fallbackUsed ? $route->disclosure_fa : null,
                        'selected_sales_server_id' => $route->sales_server_id,
                        'selected_service_target_id' => $route->panel_service_target_id,
                        'capacity_hard_limit' => $reservation->availability->hardLimit,
                        'capacity_held_units' => $reservation->availability->heldUnits,
                        'capacity_committed_units' => $reservation->availability->committedUnits,
                        'capacity_available_units' => $reservation->availability->availableUnits,
                        'capacity_version' => $reservation->availability->version,
                        'capacity_reservation_version' => $reservation->reservationVersion,
                        'correlation_id' => $context->correlationId,
                        'source_code' => $context->sourceCode,
                        'reason_code' => $context->reasonCode,
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
                        $reservation->reservationId,
                        $reservation->reservationKey,
                        $request->units,
                        $fallbackUsed,
                        $fallbackUsed ? $route->disclosure_fa : null,
                        $reservation->availability->availableUnits,
                        $reservation->availability->version,
                    );
                }

                throw new RouteCandidateUnavailable('No configured operational route has available capacity.');
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existing($context->commandKey, $payloadHash);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** @return object{tier_code: ?string, tag_ids: list<int>} */
    private function authoritativeActor(Connection $connection, int $userId, RouteSelectionActor $actor): object
    {
        /** @var object{account_status: string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->lockForUpdate()->first(['account_status']);
        if ($user === null || $user->account_status !== 'active') {
            throw new DomainException('Route selection actor is unavailable.');
        }
        if ($actor === RouteSelectionActor::Agent
            && ! $connection->table('agent_profiles')->where('user_id', $userId)->where('status', 'active')->exists()
        ) {
            throw new DomainException('Route selection requires an active agent profile.');
        }

        /** @var object{tier_code: ?string}|null $profile */
        $profile = $connection->table('customer_profiles as profile')
            ->leftJoin('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
            ->where('profile.user_id', $userId)
            ->first(['tier.code as tier_code']);
        if ($profile === null) {
            throw new DomainException('Route selection requires a customer profile.');
        }
        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('customer_tag_assignments')
            ->where('user_id', $userId)
            ->whereNull('removed_at')
            ->pluck('tag_id')
            ->all();

        return (object) [
            'tier_code' => $profile->tier_code,
            'tag_ids' => array_map(static fn (int|string $id): int => (int) $id, $tagRows),
        ];
    }

    /** @return object{id: int, audience: string, server_selection_mode: string, protocol_selection_mode: string, tag_match_mode: string} */
    private function lockedOffering(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, audience: string, server_selection_mode: string, protocol_selection_mode: string, tag_match_mode: string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'audience', 'server_selection_mode', 'protocol_selection_mode', 'tag_match_mode']);
        if ($row === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }

        return (object) [
            'id' => (int) $row->id,
            'audience' => $row->audience,
            'server_selection_mode' => $row->server_selection_mode,
            'protocol_selection_mode' => $row->protocol_selection_mode,
            'tag_match_mode' => $row->tag_match_mode,
        ];
    }

    private function assertAudience(string $audience, RouteSelectionActor $actor): void
    {
        $allowed = $audience === 'both'
            || ($audience === 'customers' && $actor === RouteSelectionActor::Customer)
            || ($audience === 'agents' && $actor === RouteSelectionActor::Agent);
        if (! $allowed) {
            throw new DomainException('Offering audience does not allow this route selection actor.');
        }
    }

    /**
     * @param  object{id: int, audience: string, server_selection_mode: string, protocol_selection_mode: string, tag_match_mode: string}  $offering
     * @param  object{tier_code: ?string, tag_ids: list<int>}  $actor
     */
    private function assertEligibility(Connection $connection, object $offering, object $actor): void
    {
        /** @var list<int|string> $tierRows */
        $tierRows = $connection->table('plan_offering_tiers')
            ->where('plan_offering_id', $offering->id)
            ->pluck('tier_code')
            ->all();
        $tiers = array_map(static fn (int|string $code): string => (string) $code, $tierRows);
        if ($tiers !== [] && ($actor->tier_code === null || ! in_array($actor->tier_code, $tiers, true))) {
            throw new DomainException('Route selection actor tier is not eligible.');
        }

        /** @var list<int|string> $tagRows */
        $tagRows = $connection->table('plan_offering_tags')
            ->where('plan_offering_id', $offering->id)
            ->pluck('customer_tag_id')
            ->all();
        $requiredTags = array_map(static fn (int|string $id): int => (int) $id, $tagRows);
        if ($requiredTags === []) {
            return;
        }
        $matching = array_intersect($requiredTags, $actor->tag_ids);
        $eligible = $offering->tag_match_mode === 'any'
            ? $matching !== []
            : count($matching) === count($requiredTags);
        if (! $eligible) {
            throw new DomainException('Route selection actor tags are not eligible.');
        }
    }

    /**
     * @param  object{id: int, audience: string, server_selection_mode: string, protocol_selection_mode: string, tag_match_mode: string}  $offering
     */
    private function resolveProtocol(Connection $connection, object $offering, ?int $requestedProtocolProfileId): int
    {
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

    /** @return object{id: int, version: int} */
    private function lockedPolicy(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, version: int|string}|null $row */
        $row = $connection->table('plan_offering_route_policies')
            ->where('plan_offering_id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'version']);
        if ($row === null) {
            throw new RouteCandidateUnavailable('Offering route policy is unavailable.');
        }

        return (object) ['id' => (int) $row->id, 'version' => (int) $row->version];
    }

    /** @return list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, priority: int, customer_selectable: bool, disclosure_fa: ?string}> */
    private function lockedRoutes(Connection $connection, int $policyId): array
    {
        /** @var list<object{id: int|string, sales_server_id: int|string, panel_service_target_id: int|string, route_type: string, priority: int|string, customer_selectable: bool|int, disclosure_fa: ?string}> $rows */
        $rows = $connection->table('plan_offering_routes')
            ->where('plan_offering_route_policy_id', $policyId)
            ->orderBy('priority')
            ->lockForUpdate()
            ->get(['id', 'sales_server_id', 'panel_service_target_id', 'route_type', 'priority', 'customer_selectable', 'disclosure_fa'])
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
                'priority' => (int) $row->priority,
                'customer_selectable' => (bool) $row->customer_selectable,
                'disclosure_fa' => $row->disclosure_fa,
            ],
            $rows,
        );
    }

    /**
     * @param  list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, priority: int, customer_selectable: bool, disclosure_fa: ?string}>  $routes
     * @return list<object{id: int, sales_server_id: int, panel_service_target_id: int, route_type: string, priority: int, customer_selectable: bool, disclosure_fa: ?string}>
     */
    private function orderedCandidates(array $routes, string $modeValue, ?int $requestedRouteId): array
    {
        $mode = PlanOfferingServerSelectionMode::tryFrom($modeValue)
            ?? throw new RuntimeException('Stored offering server selection mode is invalid.');
        if ($mode === PlanOfferingServerSelectionMode::System) {
            if ($requestedRouteId !== null) {
                throw new DomainException('System-selected offering does not accept a requested route.');
            }

            return $routes;
        }

        if ($requestedRouteId === null) {
            if ($mode === PlanOfferingServerSelectionMode::Customer) {
                throw new DomainException('Customer-selected offering requires a requested route.');
            }

            return $routes;
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

        $fallbacks = array_values(array_filter(
            $routes,
            static fn (object $route): bool => $route->id !== $requestedRouteId
                && $route->route_type === PlanOfferingRouteType::Fallback->value,
        ));

        return [$selected, ...$fallbacks];
    }

    private function existing(
        string $commandKey,
        string $payloadHash,
        ?Connection $connection = null,
        bool $lock = false,
    ): ?RouteSelectionReceipt {
        $database = $connection ?? $this->database->connection();
        $query = $database->table('plan_offering_route_selections as selection')
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
            throw new RuntimeException('Route selection command key conflict.');
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
