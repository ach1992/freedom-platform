<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Catalog\Application\PlanOfferingRouteSelector;
use App\Modules\Catalog\Application\RouteSelectionContext;
use App\Modules\Catalog\Application\RouteSelectionRequest;
use App\Modules\Catalog\Domain\RouteSelectionActor;
use App\Modules\Panels\Application\CapacityOperationContext;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Durable customer/agent preview for SVC-005 reconfiguration.
 *
 * The immutable purchase Order Item remains historical. The Service's current route selection is
 * the current configuration pointer once one exists; imported Services fall back to their original
 * offering until their first successful reconfiguration installs a route selection.
 *
 * @requirement SVC-005 CAT-002 CAT-004 CAT-008 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004
 */
final readonly class ServiceReconfigurationPreviewService
{
    private const AUTHORITY = 'service_reconfiguration_preview_v1';

    private const HOLD_MINUTES = 15;

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private PlanOfferingRouteSelector $routes,
        private TargetCapacityAllocator $capacity,
        private ServiceOperationalAuthorityGuard $operationalAuthority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
    ) {}

    public function previewForSelf(
        int $actorUserId,
        string $servicePublicId,
        string $targetOfferingCode,
        ?string $requestedSalesServerCode,
        ?string $requestedProtocolProfileCode,
        string $requestKey,
        string $correlationId,
    ): ServiceReconfigurationPreviewReceipt {
        $this->operationalAuthority->assertFinalized();
        if ($actorUserId < 1 || ! Str::isUlid($servicePublicId)) {
            throw new DomainException('Service reconfiguration actor or Service identity is invalid.');
        }
        $targetOfferingCode = $this->code($targetOfferingCode, 'Target offering code');
        $requestedSalesServerCode = $requestedSalesServerCode === null
            ? null
            : $this->code($requestedSalesServerCode, 'Requested sales server code');
        $requestedProtocolProfileCode = $requestedProtocolProfileCode === null
            ? null
            : $this->code($requestedProtocolProfileCode, 'Requested protocol profile code');
        $this->token($requestKey, 'Service reconfiguration request key', 8, 128);
        $this->token($correlationId, 'Service reconfiguration correlation ID', 16, 64);

        $requestHash = hash('sha256', $requestKey);
        $payloadHash = hash('sha256', json_encode([
            'actor_user_id' => $actorUserId,
            'service_public_id' => $servicePublicId,
            'target_offering_code' => $targetOfferingCode,
            'requested_sales_server_code' => $requestedSalesServerCode,
            'requested_protocol_profile_code' => $requestedProtocolProfileCode,
        ], JSON_THROW_ON_ERROR));
        $existing = $this->existing($requestHash, $payloadHash);
        if ($existing !== null) {
            return $existing;
        }

        $baseline = $this->baseline($actorUserId, $servicePublicId);
        $targetOffering = $this->targetOffering($targetOfferingCode);
        $requestedRouteId = $this->requestedRouteId((int) $targetOffering->id, $requestedSalesServerCode);
        $requestedProtocolId = $this->requestedProtocolId($requestedProtocolProfileCode);
        $actor = $baseline->account_type === 'agent' ? RouteSelectionActor::Agent : RouteSelectionActor::Customer;
        $expiresAt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->add(new DateInterval('PT'.self::HOLD_MINUTES.'M'));
        $route = $this->routes->select(
            new RouteSelectionRequest(
                (int) $targetOffering->id,
                $actorUserId,
                $actor,
                $requestedRouteId,
                $requestedProtocolId,
                1,
                $expiresAt,
            ),
            new RouteSelectionContext(
                'service-reconfigure-route:'.substr($requestHash, 0, 64),
                $correlationId,
                'service_reconfiguration',
                'preview_destination',
            ),
        );

        /** @var object{version:int|string,panel_connection_id:int|string}|null $targetRecord */
        $targetRecord = $this->database->connection()->table('panel_service_targets')
            ->where('id', $route->serviceTargetId)
            ->where('state', 'active')
            ->where('capability_status', 'verified')
            ->first(['version', 'panel_connection_id']);
        $profileVersion = $this->database->connection()->table('panel_protocol_profiles')
            ->where('id', $route->protocolProfileId)
            ->where('state', 'active')
            ->value('version');
        if ($targetRecord === null
            || (! is_int($profileVersion) && ! is_string($profileVersion))
            || (int) $targetRecord->version < 1 || (int) $profileVersion < 1
            || (int) $targetRecord->panel_connection_id !== (int) $baseline->source_panel_connection_id) {
            $this->releaseHeldRoute($route->capacityReservationKey, $correlationId, $requestHash);
            throw new DomainException('Selected Service reconfiguration inventory changed before preview persistence.');
        }
        $targetInventory = (object) [
            'target_version' => (int) $targetRecord->version,
            'profile_version' => (int) $profileVersion,
        ];

        $changesPlan = (int) $baseline->source_plan_offering_id !== (int) $targetOffering->id;
        $changesTarget = (int) $baseline->service_target_id !== $route->serviceTargetId;
        $changesProtocol = $baseline->source_protocol_profile_id === null
            || (int) $baseline->source_protocol_profile_id !== $route->protocolProfileId;
        if (! $changesPlan && ! $changesTarget && ! $changesProtocol) {
            $this->releaseHeldRoute($route->capacityReservationKey, $correlationId, $requestHash);
            throw new DomainException('Service reconfiguration destination matches the current Service configuration.');
        }

        [$fee, $discountEligible] = $this->operationPolicy(
            (int) $baseline->source_plan_offering_id,
            $changesPlan,
            $changesTarget,
            $changesProtocol,
        );
        $priceDifference = max(0, (int) $targetOffering->base_price_irr - (int) $baseline->source_base_price_irr);
        $totalPrice = $priceDifference + $fee;
        $discountEligible = $discountEligible && (bool) $targetOffering->discount_eligible;

        try {
            $receipt = $this->database->connection()->transaction(function (Connection $connection) use (
                $actorUserId,
                $baseline,
                $targetOffering,
                $route,
                $targetInventory,
                $changesPlan,
                $changesTarget,
                $changesProtocol,
                $priceDifference,
                $fee,
                $totalPrice,
                $discountEligible,
                $requestHash,
                $payloadHash,
                $correlationId,
                $expiresAt,
            ): ServiceReconfigurationPreviewReceipt {
                $replay = $this->existingOn($connection, $requestHash, $payloadHash, true);
                if ($replay !== null) {
                    return $replay;
                }
                $timestamp = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                $publicId = (string) Str::ulid();
                $this->setAuthority($connection, $requestHash, $payloadHash, $correlationId);
                try {
                    $connection->table('service_reconfiguration_previews')->insert([
                        'public_id' => $publicId,
                        'request_key_hash' => $requestHash,
                        'payload_hash' => $payloadHash,
                        'actor_user_id' => $actorUserId,
                        'service_subscription_id' => (int) $baseline->service_id,
                        'source_plan_offering_id' => (int) $baseline->source_plan_offering_id,
                        'source_route_selection_id' => $baseline->route_selection_id === null ? null : (int) $baseline->route_selection_id,
                        'source_service_target_id' => (int) $baseline->service_target_id,
                        'source_protocol_profile_id' => $baseline->source_protocol_profile_id === null ? null : (int) $baseline->source_protocol_profile_id,
                        'source_remote_identity_generation' => (int) $baseline->remote_identity_generation,
                        'source_lifecycle_version' => (int) $baseline->lifecycle_version,
                        'source_mutation_generation' => (int) $baseline->mutation_generation,
                        'target_plan_offering_id' => (int) $targetOffering->id,
                        'target_route_selection_id' => $route->selectionId,
                        'target_service_target_id' => $route->serviceTargetId,
                        'target_service_target_version' => (int) $targetInventory->target_version,
                        'target_protocol_profile_id' => $route->protocolProfileId,
                        'target_protocol_profile_version' => (int) $targetInventory->profile_version,
                        'target_capacity_reservation_id' => $route->capacityReservationId,
                        'target_capacity_reservation_key' => $route->capacityReservationKey,
                        'changes_plan' => $changesPlan,
                        'changes_target' => $changesTarget,
                        'changes_protocol' => $changesProtocol,
                        'price_difference_irr' => $priceDifference,
                        'operation_fee_irr' => $fee,
                        'total_price_irr' => $totalPrice,
                        'discount_eligible' => $discountEligible,
                        'state' => 'previewed',
                        'correlation_id' => $correlationId,
                        'expires_at' => $expiresAt->format('Y-m-d H:i:s.u'),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                } finally {
                    $this->clearAuthority($connection);
                }

                return $this->receiptByPublicId($connection, $publicId, false);
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->existing($requestHash, $payloadHash);
            if ($replay !== null) {
                return $replay;
            }
            $this->releaseHeldRoute($route->capacityReservationKey, $correlationId, $requestHash);
            throw $exception;
        } catch (Throwable $exception) {
            $this->releaseHeldRoute($route->capacityReservationKey, $correlationId, $requestHash);
            throw $exception;
        }

        return $receipt;
    }

    /** @return object{service_id:int|string,account_type:string,route_selection_id:int|string|null,service_target_id:int|string,source_panel_connection_id:int|string,source_plan_offering_id:int|string,source_protocol_profile_id:int|string|null,source_capacity_state:?string,source_base_price_irr:int|string,remote_identity_generation:int|string,lifecycle_version:int|string,mutation_generation:int|string} */
    private function baseline(int $actorUserId, string $servicePublicId): object
    {
        /** @var object{service_id:int|string,account_type:string,route_selection_id:int|string|null,service_target_id:int|string|null,source_panel_connection_id:int|string,source_plan_offering_id:int|string,source_protocol_profile_id:int|string|null,source_capacity_state:?string,source_base_price_irr:int|string,remote_identity_generation:int|string,lifecycle_version:int|string,mutation_generation:int|string,lifecycle_state:string,remote_service_id:?string,remote_deleted_at:?string,provisioned_at:?string}|null $row */
        $row = $this->database->connection()->table('service_subscriptions as service')
            ->join('users as user', 'user.id', '=', 'service.user_id')
            ->join('order_items as original_item', 'original_item.id', '=', 'service.order_item_id')
            ->join('panel_service_targets as source_target', 'source_target.id', '=', 'service.service_target_id')
            ->leftJoin('plan_offering_route_selections as current_selection', 'current_selection.id', '=', 'service.route_selection_id')
            ->leftJoin('panel_capacity_reservations as source_reservation', 'source_reservation.id', '=', 'current_selection.capacity_reservation_id')
            ->join('plan_offerings as source_offering', 'source_offering.id', '=', DB::raw('COALESCE(current_selection.plan_offering_id, original_item.plan_offering_id)'))
            ->where('service.public_id', $servicePublicId)
            ->where('service.user_id', $actorUserId)
            ->first([
                'service.id as service_id', 'user.account_type', 'service.route_selection_id', 'service.service_target_id',
                'source_target.panel_connection_id as source_panel_connection_id', 'source_reservation.state as source_capacity_state',
                DB::raw('COALESCE(current_selection.plan_offering_id, original_item.plan_offering_id) as source_plan_offering_id'),
                'current_selection.panel_protocol_profile_id as source_protocol_profile_id',
                'source_offering.base_price_irr as source_base_price_irr', 'service.remote_identity_generation',
                'service.lifecycle_version', 'service.mutation_generation', 'service.lifecycle_state',
                'service.remote_service_id', 'service.remote_deleted_at', 'service.provisioned_at',
            ]);
        if ($row === null
            || ! in_array($row->account_type, ['customer', 'agent'], true)
            || ! in_array($row->lifecycle_state, ['active', 'suspended'], true)
            || $row->remote_deleted_at !== null
            || $row->provisioned_at === null
            || $row->service_target_id === null
            || ($row->route_selection_id !== null && $row->source_capacity_state !== 'committed')
            || ! is_string($row->remote_service_id) || $row->remote_service_id === '') {
            throw new DomainException('Service is not eligible for reconfiguration.');
        }

        return $row;
    }

    /** @return object{id:int|string,base_price_irr:int|string,discount_eligible:int|bool|string} */
    private function targetOffering(string $code): object
    {
        /** @var object{id:int|string,base_price_irr:int|string,discount_eligible:int|bool|string}|null $row */
        $row = $this->database->connection()->table('plan_offerings')
            ->where('code', $code)
            ->where('state', 'active')
            ->where('visibility', 'visible')
            ->first(['id', 'base_price_irr', 'discount_eligible']);
        if ($row === null) {
            throw new DomainException('Target Plan Offering is unavailable.');
        }

        return $row;
    }

    private function requestedRouteId(int $offeringId, ?string $serverCode): ?int
    {
        if ($serverCode === null) {
            return null;
        }
        $routeId = $this->database->connection()->table('plan_offering_route_policies as policy')
            ->join('plan_offering_routes as route', 'route.plan_offering_route_policy_id', '=', 'policy.id')
            ->join('sales_servers as server', 'server.id', '=', 'route.sales_server_id')
            ->where('policy.plan_offering_id', $offeringId)
            ->where('server.code', $serverCode)
            ->where('server.state', 'active')
            ->where('server.visibility', 'visible')
            ->where('route.customer_selectable', true)
            ->value('route.id');
        if (! is_int($routeId) && ! is_string($routeId)) {
            throw new DomainException('Requested Service destination server is unavailable.');
        }

        return (int) $routeId;
    }

    private function requestedProtocolId(?string $profileCode): ?int
    {
        if ($profileCode === null) {
            return null;
        }
        $profileId = $this->database->connection()->table('panel_protocol_profiles')
            ->where('code', $profileCode)
            ->where('state', 'active')
            ->value('id');
        if (! is_int($profileId) && ! is_string($profileId)) {
            throw new DomainException('Requested Service protocol profile is unavailable.');
        }

        return (int) $profileId;
    }

    /** @return array{int,bool} */
    private function operationPolicy(int $sourceOfferingId, bool $changesPlan, bool $changesTarget, bool $changesProtocol): array
    {
        $codes = [];
        if ($changesPlan) {
            $codes[] = 'change_plan';
        }
        if ($changesTarget) {
            $codes[] = 'change_location';
        }
        if ($changesProtocol) {
            $codes[] = 'change_protocol';
        }
        $rows = $this->database->connection()->table('plan_offering_operations')
            ->where('plan_offering_id', $sourceOfferingId)
            ->whereIn('operation_code', $codes)
            ->where('customer_enabled', true)
            ->get(['operation_code', 'price_irr', 'discount_eligible']);
        if ($rows->count() !== count($codes)) {
            throw new DomainException('Current Plan Offering does not allow the requested Service reconfiguration.');
        }
        $fee = 0;
        $discountEligible = true;
        foreach ($rows as $row) {
            $fee += (int) $row->price_irr;
            $discountEligible = $discountEligible && (bool) $row->discount_eligible;
        }

        return [$fee, $discountEligible];
    }

    private function existing(string $requestHash, string $payloadHash): ?ServiceReconfigurationPreviewReceipt
    {
        return $this->existingOn($this->database->connection(), $requestHash, $payloadHash, false);
    }

    private function existingOn(Connection $connection, string $requestHash, string $payloadHash, bool $lock): ?ServiceReconfigurationPreviewReceipt
    {
        $query = $connection->table('service_reconfiguration_previews')->where('request_key_hash', $requestHash);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{public_id:string,payload_hash:string}|null $row */
        $row = $query->first(['public_id', 'payload_hash']);
        if ($row === null) {
            return null;
        }
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new DomainException('Service reconfiguration request key conflicts with accepted preview evidence.');
        }

        return $this->receiptByPublicId($connection, $row->public_id, true);
    }

    private function receiptByPublicId(Connection $connection, string $publicId, bool $replayed): ServiceReconfigurationPreviewReceipt
    {
        /** @var object{public_id:string,service_public_id:string,source_offering_code:string,target_offering_code:string,target_server_code:string,target_profile_code:string,changes_plan:int|bool,changes_target:int|bool,changes_protocol:int|bool,price_difference_irr:int|string,operation_fee_irr:int|string,total_price_irr:int|string,discount_eligible:int|bool,expires_at:string}|null $row */
        $row = $connection->table('service_reconfiguration_previews as preview')
            ->join('service_subscriptions as service', 'service.id', '=', 'preview.service_subscription_id')
            ->join('plan_offerings as source_offering', 'source_offering.id', '=', 'preview.source_plan_offering_id')
            ->join('plan_offerings as target_offering', 'target_offering.id', '=', 'preview.target_plan_offering_id')
            ->join('plan_offering_route_selections as target_selection', 'target_selection.id', '=', 'preview.target_route_selection_id')
            ->join('sales_servers as target_server', 'target_server.id', '=', 'target_selection.selected_sales_server_id')
            ->join('panel_protocol_profiles as target_profile', 'target_profile.id', '=', 'preview.target_protocol_profile_id')
            ->where('preview.public_id', $publicId)
            ->first([
                'preview.public_id', 'service.public_id as service_public_id', 'source_offering.code as source_offering_code',
                'target_offering.code as target_offering_code', 'target_server.code as target_server_code',
                'target_profile.code as target_profile_code', 'preview.changes_plan', 'preview.changes_target',
                'preview.changes_protocol', 'preview.price_difference_irr', 'preview.operation_fee_irr',
                'preview.total_price_irr', 'preview.discount_eligible', 'preview.expires_at',
            ]);
        if ($row === null) {
            throw new RuntimeException('Service reconfiguration preview disappeared.');
        }

        return new ServiceReconfigurationPreviewReceipt(
            $row->public_id,
            $row->service_public_id,
            $row->source_offering_code,
            $row->target_offering_code,
            $row->target_server_code,
            $row->target_profile_code,
            (bool) $row->changes_plan,
            (bool) $row->changes_target,
            (bool) $row->changes_protocol,
            (int) $row->price_difference_irr,
            (int) $row->operation_fee_irr,
            (int) $row->total_price_irr,
            (bool) $row->discount_eligible,
            $row->expires_at,
            $replayed,
        );
    }

    private function setAuthority(Connection $connection, string $requestHash, string $payloadHash, string $correlationId): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_reconfiguration_authority = ?', [self::AUTHORITY]);
            $connection->statement('SET @app_service_reconfiguration_request_hash = ?', [$requestHash]);
            $connection->statement('SET @app_service_reconfiguration_payload_hash = ?', [$payloadHash]);
            $connection->statement('SET @app_service_reconfiguration_correlation_id = ?', [$correlationId]);
        } catch (Throwable $exception) {
            $this->clearAuthority($connection);
            throw $exception;
        }
    }

    private function clearAuthority(Connection $connection): void
    {
        try {
            $connection->statement('SET @app_service_reconfiguration_authority = NULL');
            $connection->statement('SET @app_service_reconfiguration_request_hash = NULL');
            $connection->statement('SET @app_service_reconfiguration_payload_hash = NULL');
            $connection->statement('SET @app_service_reconfiguration_correlation_id = NULL');
        } finally {
            $this->databaseCapability->clear($connection);
        }
    }

    private function releaseHeldRoute(string $reservationKey, string $correlationId, string $requestHash): void
    {
        try {
            /** @var object{state:string,version:int|string}|null $row */
            $row = $this->database->connection()->table('panel_capacity_reservations')
                ->where('reservation_key', $reservationKey)
                ->first(['state', 'version']);
            if ($row === null || $row->state !== 'held') {
                return;
            }
            $this->capacity->release(
                $reservationKey,
                (int) $row->version,
                new CapacityOperationContext(
                    'service-reconfigure-preview-release:'.substr($requestHash, 0, 64),
                    $correlationId,
                    'service_reconfiguration',
                    'preview',
                    'preview_rejected',
                ),
            );
        } catch (Throwable) {
            // The reservation remains bounded by its expiry and can be reconciled by capacity maintenance.
        }
    }

    private function code(string $value, string $label): string
    {
        $normalized = strtolower(trim($value));
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $normalized) !== 1) {
            throw new DomainException($label.' is invalid.');
        }

        return $normalized;
    }

    private function token(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
