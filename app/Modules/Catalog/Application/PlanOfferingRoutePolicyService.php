<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\PlanOfferingRouteDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRoutePolicyDefinition;
use App\Modules\Catalog\Domain\PlanOfferingRouteType;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class PlanOfferingRoutePolicyService
{
    private const TARGET_TYPE = 'plan_offering_route_policy';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement CAT-002 CAT-004 CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        int $offeringId,
        PlanOfferingRoutePolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($offeringId < 1) {
            throw new RuntimeException('Plan offering ID must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'offering_id' => $offeringId,
            ...$definition->payload(),
        ]);

        return $this->executor->execute(
            'plan_offering_route_policy.create',
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($offeringId, $definition, $context, $payloadHash): CatalogMutationReceipt {
                $offering = $this->lockedOffering($connection, $offeringId);
                $this->assertDraft($offering->state);
                if ($connection->table('plan_offering_route_policies')->where('plan_offering_id', $offeringId)->exists()) {
                    throw new RuntimeException('Plan offering route policy already exists.');
                }
                $this->assertCompatible($connection, $offering, $definition);

                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $policyId = (int) $connection->table('plan_offering_route_policies')->insertGetId([
                    'plan_offering_id' => $offeringId,
                    'configuration_hash' => $configurationHash,
                    'version' => 1,
                    'created_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $this->replaceRoutes($connection, $policyId, $definition);
                $safe = $this->safeState($offeringId, 1, $definition, $configurationHash, $payloadHash);
                $this->recordHistory($connection, $policyId, 1, $definition, $configurationHash, $context);

                return $this->audit->record(
                    $connection,
                    'plan_offering_route_policy.create',
                    self::TARGET_TYPE,
                    $policyId,
                    $context,
                    [],
                    $safe,
                    true,
                );
            },
        );
    }

    public function update(
        int $policyId,
        int $expectedVersion,
        PlanOfferingRoutePolicyDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        if ($policyId < 1 || $expectedVersion < 1) {
            throw new RuntimeException('Route policy ID and version must be positive.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'policy_id' => $policyId,
            'expected_version' => $expectedVersion,
            ...$definition->payload(),
        ]);

        return $this->executor->execute(
            'plan_offering_route_policy.update',
            self::TARGET_TYPE,
            $policyId,
            $payloadHash,
            $context,
            function (Connection $connection) use ($policyId, $expectedVersion, $definition, $context, $payloadHash): CatalogMutationReceipt {
                /** @var object{id: int|string, plan_offering_id: int|string, configuration_hash: string, version: int|string}|null $policy */
                $policy = $connection->table('plan_offering_route_policies')
                    ->where('id', $policyId)
                    ->lockForUpdate()
                    ->first(['id', 'plan_offering_id', 'configuration_hash', 'version']);
                if ($policy === null) {
                    throw new RuntimeException('Plan offering route policy does not exist.');
                }
                if ((int) $policy->version !== $expectedVersion) {
                    throw new RuntimeException('Catalog version conflict.');
                }

                $offering = $this->lockedOffering($connection, (int) $policy->plan_offering_id);
                $this->assertDraft($offering->state);
                $this->assertCompatible($connection, $offering, $definition);
                $before = $this->safeState(
                    $offering->id,
                    (int) $policy->version,
                    null,
                    $policy->configuration_hash,
                    $payloadHash,
                    (int) $connection->table('plan_offering_routes')->where('plan_offering_route_policy_id', $policyId)->count(),
                    (int) $connection->table('plan_offering_routes')
                        ->where('plan_offering_route_policy_id', $policyId)
                        ->where('route_type', PlanOfferingRouteType::Fallback->value)
                        ->count(),
                );

                $configurationHash = CatalogPayloadHash::make($definition->payload());
                $newVersion = $expectedVersion + 1;
                $connection->table('plan_offering_route_policies')->where('id', $policyId)->update([
                    'configuration_hash' => $configurationHash,
                    'version' => $newVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $connection->table('plan_offering_routes')->where('plan_offering_route_policy_id', $policyId)->delete();
                $this->replaceRoutes($connection, $policyId, $definition);
                $after = $this->safeState($offering->id, $newVersion, $definition, $configurationHash, $payloadHash);
                $this->recordHistory($connection, $policyId, $newVersion, $definition, $configurationHash, $context);

                return $this->audit->record(
                    $connection,
                    'plan_offering_route_policy.update',
                    self::TARGET_TYPE,
                    $policyId,
                    $context,
                    $before,
                    $after,
                    $before !== $after,
                );
            },
        );
    }

    /** @return object{id: int, sales_server_id: int, panel_service_target_id: int, server_selection_mode: string, state: string} */
    private function lockedOffering(Connection $connection, int $offeringId): object
    {
        /** @var object{id: int|string, sales_server_id: int|string, panel_service_target_id: int|string, server_selection_mode: string, state: string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('id', $offeringId)
            ->lockForUpdate()
            ->first(['id', 'sales_server_id', 'panel_service_target_id', 'server_selection_mode', 'state']);
        if ($row === null) {
            throw new RuntimeException('Plan offering does not exist.');
        }

        return (object) [
            'id' => (int) $row->id,
            'sales_server_id' => (int) $row->sales_server_id,
            'panel_service_target_id' => (int) $row->panel_service_target_id,
            'server_selection_mode' => $row->server_selection_mode,
            'state' => $row->state,
        ];
    }

    private function assertDraft(string $state): void
    {
        if ($state !== 'draft') {
            throw new DomainException('Route policy is mutable only while the offering is draft.');
        }
    }

    /**
     * @param object{id: int, sales_server_id: int, panel_service_target_id: int, server_selection_mode: string, state: string} $offering
     */
    private function assertCompatible(
        Connection $connection,
        object $offering,
        PlanOfferingRoutePolicyDefinition $definition,
    ): void {
        $primary = $definition->routes[0];
        if ($primary->salesServerId !== $offering->sales_server_id
            || $primary->serviceTargetId !== $offering->panel_service_target_id
        ) {
            throw new DomainException('Primary route must match the offering server and target.');
        }

        $selectionMode = PlanOfferingServerSelectionMode::tryFrom($offering->server_selection_mode)
            ?? throw new RuntimeException('Stored offering server selection mode is invalid.');
        $selectableCount = count(array_filter(
            $definition->routes,
            static fn (PlanOfferingRouteDefinition $route): bool => $route->customerSelectable,
        ));
        if ($selectionMode === PlanOfferingServerSelectionMode::System && $selectableCount > 0) {
            throw new DomainException('System-selected offering routes cannot be customer selectable.');
        }
        if ($selectionMode !== PlanOfferingServerSelectionMode::System && $selectableCount < 1) {
            throw new DomainException('Customer-capable offering requires a selectable route.');
        }

        $serverIds = array_values(array_unique(array_map(
            static fn (PlanOfferingRouteDefinition $route): int => $route->salesServerId,
            $definition->routes,
        )));
        $targetIds = array_values(array_unique(array_map(
            static fn (PlanOfferingRouteDefinition $route): int => $route->serviceTargetId,
            $definition->routes,
        )));
        if ($connection->table('sales_servers')->whereIn('id', $serverIds)->where('state', '<>', 'archived')->count() !== count($serverIds)) {
            throw new DomainException('One or more offering route servers are unavailable.');
        }
        if ($connection->table('panel_service_targets')->whereIn('id', $targetIds)->where('state', '<>', 'archived')->count() !== count($targetIds)) {
            throw new DomainException('One or more offering route targets are unavailable.');
        }

        /** @var list<int|string> $profileRows */
        $profileRows = $connection->table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $offering->id)
            ->pluck('panel_protocol_profile_id')
            ->all();
        $profileIds = array_map(static fn (int|string $id): int => (int) $id, $profileRows);
        if ($profileIds === []) {
            throw new DomainException('Offering route policy requires protocol profiles.');
        }

        /** @var list<int|string> $requiredRows */
        $requiredRows = $connection->table('plan_offering_required_capabilities')
            ->where('plan_offering_id', $offering->id)
            ->pluck('capability_code')
            ->all();
        /** @var list<int|string> $operationRows */
        $operationRows = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', $offering->id)
            ->whereNotNull('required_capability_code')
            ->pluck('required_capability_code')
            ->all();
        $capabilities = array_values(array_unique(array_map(
            static fn (int|string $code): string => (string) $code,
            array_merge($requiredRows, $operationRows),
        )));

        foreach ($targetIds as $targetId) {
            if ($connection->table('panel_target_protocol_profiles')
                ->where('panel_service_target_id', $targetId)
                ->whereIn('panel_protocol_profile_id', $profileIds)
                ->count() !== count($profileIds)
            ) {
                throw new DomainException('Offering route target does not support every offering protocol profile.');
            }
            if ($capabilities !== []
                && $connection->table('panel_target_capabilities')
                    ->where('panel_service_target_id', $targetId)
                    ->whereIn('capability_code', $capabilities)
                    ->where('verification_status', '<>', 'stale')
                    ->count() !== count($capabilities)
            ) {
                throw new DomainException('Offering route target does not declare every required capability.');
            }
        }
    }

    private function replaceRoutes(
        Connection $connection,
        int $policyId,
        PlanOfferingRoutePolicyDefinition $definition,
    ): void {
        foreach ($definition->routes as $route) {
            $connection->table('plan_offering_routes')->insert([
                'plan_offering_route_policy_id' => $policyId,
                'sales_server_id' => $route->salesServerId,
                'panel_service_target_id' => $route->serviceTargetId,
                'route_type' => $route->type->value,
                'priority' => $route->priority,
                'customer_selectable' => $route->customerSelectable,
                'disclosure_fa' => $route->disclosureFa,
                'disclosure_en' => $route->disclosureEn,
                'created_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);
        }
    }

    private function recordHistory(
        Connection $connection,
        int $policyId,
        int $version,
        PlanOfferingRoutePolicyDefinition $definition,
        string $configurationHash,
        CatalogChangeContext $context,
    ): void {
        $connection->table('plan_offering_route_policy_histories')->insert([
            'plan_offering_route_policy_id' => $policyId,
            'version' => $version,
            'configuration_hash' => $configurationHash,
            'route_count' => count($definition->routes),
            'fallback_count' => count(array_filter(
                $definition->routes,
                static fn (PlanOfferingRouteDefinition $route): bool => $route->type === PlanOfferingRouteType::Fallback,
            )),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $offeringId,
        int $version,
        ?PlanOfferingRoutePolicyDefinition $definition,
        string $configurationHash,
        string $payloadHash,
        ?int $routeCount = null,
        ?int $fallbackCount = null,
    ): array {
        return [
            'offering_id' => $offeringId,
            'version' => $version,
            'configuration_hash' => $configurationHash,
            'route_count' => $routeCount ?? count($definition?->routes ?? []),
            'fallback_count' => $fallbackCount ?? count(array_filter(
                $definition?->routes ?? [],
                static fn (PlanOfferingRouteDefinition $route): bool => $route->type === PlanOfferingRouteType::Fallback,
            )),
            'request_payload_hash' => $payloadHash,
        ];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
