<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use App\Modules\Telegram\Application\TelegramOwnedServiceListItem;
use App\Modules\Telegram\Application\TelegramOwnedServicePage;
use App\Modules\Telegram\Application\TelegramOwnedServiceSearchResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramOwnedServiceProjectionService implements TelegramOwnedServiceProjection
{
    private const MAXIMUM_PAGE_SIZE = 6;

    public function __construct(
        private DatabaseManager $database,
        private TelegramOwnedServiceAllowedActionResolver $allowedActionResolver,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage
    {
        $this->assertSelf($actorUserId, $subjectUserId);
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new InvalidArgumentException('Telegram owned Service page request is invalid.');
        }

        $connection = $this->database->connection();
        $totalItems = (int) $connection->table('service_subscriptions as service')
            ->where('service.user_id', $subjectUserId)
            ->count();
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);

        $rows = $connection->table('service_subscriptions as service')
            ->join('order_items as item', 'item.id', '=', 'service.order_item_id')
            ->join('plan_offerings as offering', 'offering.id', '=', 'item.plan_offering_id')
            ->join('products as product', 'product.id', '=', 'offering.product_id')
            ->join('sales_servers as server', 'server.id', '=', 'offering.sales_server_id')
            ->leftJoin('product_variants as variant', 'variant.id', '=', 'offering.variant_id')
            ->where('service.user_id', $subjectUserId)
            ->orderByDesc('service.created_at')
            ->orderByDesc('service.id')
            ->offset(($effectivePage - 1) * $pageSize)
            ->limit($pageSize)
            ->get([
                'service.public_id', 'service.lifecycle_state', 'service.provisioned_at',
                'product.name_fa as product_name_fa', 'product.name_en as product_name_en',
                'variant.name_fa as variant_name_fa', 'variant.name_en as variant_name_en',
                'server.name_fa as server_name_fa', 'server.name_en as server_name_en',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $publicId = $this->databaseString($row->public_id ?? null, 'Service public ID');
            $items[] = new TelegramOwnedServiceListItem(
                $this->selectionToken($subjectUserId, $publicId),
                $publicId,
                $this->databaseString($row->lifecycle_state ?? null, 'Service lifecycle state'),
                $this->planName($row->product_name_fa ?? null, $row->variant_name_fa ?? null),
                $this->optionalPlanName($row->product_name_en ?? null, $row->variant_name_en ?? null),
                $this->databaseString($row->server_name_fa ?? null, 'Service server label'),
                $this->optionalDatabaseString($row->server_name_en ?? null),
                $this->optionalDatabaseString($row->provisioned_at ?? null),
            );
        }

        return new TelegramOwnedServicePage($items, $effectivePage, $totalPages, $totalItems);
    }

    public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail
    {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram owned Service selection is invalid.');
        }

        $connection = $this->database->connection();
        $service = $connection->table('service_subscriptions as service')
            ->join('order_items as item', 'item.id', '=', 'service.order_item_id')
            ->join('plan_offerings as offering', 'offering.id', '=', 'item.plan_offering_id')
            ->join('products as product', 'product.id', '=', 'offering.product_id')
            ->join('sales_servers as server', 'server.id', '=', 'offering.sales_server_id')
            ->leftJoin('product_variants as variant', 'variant.id', '=', 'offering.variant_id')
            ->where('service.user_id', $subjectUserId)
            ->whereRaw("LEFT(SHA2(CONCAT('telegram-owned-service-v1:', CAST(service.user_id AS CHAR), ':', service.public_id), 256), 40) = ?", [$selectionToken])
            ->first([
                'service.id', 'service.public_id', 'service.lifecycle_state', 'service.lifecycle_version',
                'service.remote_identity_generation', 'service.mutation_generation', 'service.provisioned_at',
                'service.service_target_id', 'service.remote_service_id', 'service.remote_deleted_at',
                'item.plan_offering_id',
                'product.name_fa as product_name_fa', 'product.name_en as product_name_en',
                'variant.name_fa as variant_name_fa', 'variant.name_en as variant_name_en',
                'server.name_fa as server_name_fa', 'server.name_en as server_name_en',
            ]);
        if ($service === null) {
            throw new AuthorizationException('Telegram owned Service is unavailable for this actor.');
        }

        $currentSnapshot = $connection->table('service_sync_snapshots')
            ->where('service_subscription_id', (int) $service->id)
            ->where('local_lifecycle_state', (string) $service->lifecycle_state)
            ->where('local_lifecycle_version', (int) $service->lifecycle_version)
            ->where('local_remote_identity_generation', (int) $service->remote_identity_generation)
            ->where('local_mutation_generation', (int) $service->mutation_generation)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first([
                'id', 'remote_disposition', 'remote_status', 'remote_data_limit_bytes',
                'remote_used_bytes', 'remote_expires_at', 'observed_at',
            ]);

        $syncState = 'none';
        $remoteDisposition = null;
        $remoteStatus = null;
        $dataLimitBytes = null;
        $usedBytes = null;
        $expiresAt = null;
        $observedAt = null;

        if ($currentSnapshot !== null) {
            $syncState = 'current';
            $remoteDisposition = $this->databaseString($currentSnapshot->remote_disposition ?? null, 'Service remote disposition');
            $observedAt = $this->databaseString($currentSnapshot->observed_at ?? null, 'Service observation time');
            if ($remoteDisposition === 'present') {
                $remoteStatus = $this->optionalDatabaseString($currentSnapshot->remote_status ?? null);
                $dataLimitBytes = $this->optionalNonNegativeInt($currentSnapshot->remote_data_limit_bytes ?? null, 'Service remote data limit');
                $usedBytes = $this->optionalNonNegativeInt($currentSnapshot->remote_used_bytes ?? null, 'Service remote usage');
                $expiresAt = $this->optionalDatabaseString($currentSnapshot->remote_expires_at ?? null);
            } elseif ($remoteDisposition === 'unavailable') {
                $latestPriorAuthoritative = $connection->table('service_sync_snapshots')
                    ->where('service_subscription_id', (int) $service->id)
                    ->where('local_lifecycle_state', (string) $service->lifecycle_state)
                    ->where('local_lifecycle_version', (int) $service->lifecycle_version)
                    ->where('local_remote_identity_generation', (int) $service->remote_identity_generation)
                    ->where('local_mutation_generation', (int) $service->mutation_generation)
                    ->where('remote_disposition', '<>', 'unavailable')
                    ->where(function ($query) use ($currentSnapshot): void {
                        $query->where('observed_at', '<', (string) $currentSnapshot->observed_at)
                            ->orWhere(function ($sameTime) use ($currentSnapshot): void {
                                $sameTime->where('observed_at', (string) $currentSnapshot->observed_at)
                                    ->where('id', '<', (int) $currentSnapshot->id);
                            });
                    })
                    ->orderByDesc('observed_at')
                    ->orderByDesc('id')
                    ->first([
                        'remote_disposition', 'remote_status', 'remote_data_limit_bytes', 'remote_used_bytes',
                        'remote_expires_at', 'observed_at',
                    ]);
                if ($latestPriorAuthoritative !== null
                    && (string) $latestPriorAuthoritative->remote_disposition === 'present') {
                    $syncState = 'cached';
                    $remoteStatus = $this->databaseString($latestPriorAuthoritative->remote_status ?? null, 'Cached Service remote status');
                    $dataLimitBytes = $this->optionalNonNegativeInt($latestPriorAuthoritative->remote_data_limit_bytes ?? null, 'Cached Service remote data limit');
                    $usedBytes = $this->optionalNonNegativeInt($latestPriorAuthoritative->remote_used_bytes ?? null, 'Cached Service remote usage');
                    $expiresAt = $this->optionalDatabaseString($latestPriorAuthoritative->remote_expires_at ?? null);
                    $observedAt = $this->databaseString($latestPriorAuthoritative->observed_at ?? null, 'Cached Service observation time');
                }
            }
        } else {
            $latestSnapshot = $connection->table('service_sync_snapshots')
                ->where('service_subscription_id', (int) $service->id)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first(['observed_at']);
            if ($latestSnapshot !== null) {
                $syncState = 'stale';
                $observedAt = $this->databaseString($latestSnapshot->observed_at ?? null, 'Stale Service observation time');
            }
        }

        $lifecycleState = $this->databaseString($service->lifecycle_state ?? null, 'Service lifecycle state');
        $allowedActions = $this->allowedActions(
            $connection,
            $this->positiveDatabaseInt($service->plan_offering_id ?? null, 'Service Plan Offering ID'),
            $lifecycleState,
            $service->provisioned_at ?? null,
            $service->service_target_id ?? null,
            $service->remote_service_id ?? null,
            $service->remote_deleted_at ?? null,
        );

        return new TelegramOwnedServiceDetail(
            $this->databaseString($service->public_id ?? null, 'Service public ID'),
            $lifecycleState,
            $this->planName($service->product_name_fa ?? null, $service->variant_name_fa ?? null),
            $this->optionalPlanName($service->product_name_en ?? null, $service->variant_name_en ?? null),
            $this->databaseString($service->server_name_fa ?? null, 'Service server label'),
            $this->optionalDatabaseString($service->server_name_en ?? null),
            $this->optionalDatabaseString($service->provisioned_at ?? null),
            $syncState,
            $remoteDisposition,
            $remoteStatus,
            $dataLimitBytes,
            $usedBytes,
            $expiresAt,
            $observedAt,
            $allowedActions,
        );
    }

    public function searchForSelf(int $actorUserId, int $subjectUserId, string $searchTerm): TelegramOwnedServiceSearchResult
    {
        $this->assertSelf($actorUserId, $subjectUserId);
        $term = $searchTerm;
        if ($term === '' || ! mb_check_encoding($term, 'UTF-8') || str_contains($term, "\0") || mb_strlen($term) > 191) {
            return TelegramOwnedServiceSearchResult::notFound();
        }

        $connection = $this->database->connection();
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $term) === 1) {
            $identifier = strtoupper($term);
            $identifierMatches = array_values($connection->table('service_subscriptions as service')
                ->join('orders as order_row', 'order_row.id', '=', 'service.order_id')
                ->where('service.user_id', $subjectUserId)
                ->where(function ($query) use ($identifier): void {
                    $query->whereRaw('BINARY service.public_id = ?', [$identifier])
                        ->orWhereRaw('BINARY order_row.public_id = ?', [$identifier]);
                })
                ->limit(2)
                ->pluck('service.public_id')
                ->all());
            $identifierResult = $this->searchResult($subjectUserId, $identifierMatches);
            if ($identifierResult->status !== TelegramOwnedServiceSearchResult::NOT_FOUND) {
                return $identifierResult;
            }
        }

        $importMatches = array_values($connection->table('service_subscriptions as service')
            ->join('service_imports as import_row', 'import_row.service_subscription_id', '=', 'service.id')
            ->where('service.user_id', $subjectUserId)
            ->where('import_row.state', 'attached')
            ->whereRaw('BINARY import_row.remote_username = ?', [$term])
            ->limit(2)
            ->pluck('service.public_id')
            ->all());
        $provisioningMatches = array_values($connection->table('service_subscriptions as service')
            ->join('provisioning_operations as operation', 'operation.service_subscription_id', '=', 'service.id')
            ->where('service.user_id', $subjectUserId)
            ->where('operation.operation_type', 'initial_provision')
            ->whereNotNull('operation.remote_username')
            ->whereRaw('BINARY operation.remote_username = ?', [$term])
            ->limit(2)
            ->pluck('service.public_id')
            ->all());

        return $this->searchResult($subjectUserId, array_values(array_merge($importMatches, $provisioningMatches)));
    }

    /** @return list<TelegramOwnedServiceAction> */
    private function allowedActions(
        Connection $connection,
        int $planOfferingId,
        string $lifecycleState,
        mixed $provisionedAt,
        mixed $serviceTargetId,
        mixed $remoteServiceId,
        mixed $remoteDeletedAt,
    ): array {
        $fullyProvisioned = $provisionedAt !== null
            && $remoteDeletedAt === null
            && is_string($remoteServiceId)
            && $remoteServiceId !== '';
        if (! $fullyProvisioned) {
            return $this->allowedActionResolver->resolve($lifecycleState, false, [], [], []);
        }

        $targetId = $this->positiveDatabaseInt($serviceTargetId, 'Service target ID');
        $actionCodes = array_map(
            static fn (TelegramOwnedServiceAction $action): string => $action->value,
            TelegramOwnedServiceAction::ordered(),
        );

        $policies = [];
        $policyRows = $connection->table('plan_offering_operations')
            ->where('plan_offering_id', $planOfferingId)
            ->whereIn('operation_code', $actionCodes)
            ->get(['operation_code', 'customer_enabled', 'required_capability_code']);
        foreach ($policyRows as $policy) {
            $operationCode = $this->databaseString($policy->operation_code ?? null, 'Service operation policy code');
            $requiredCapability = $policy->required_capability_code ?? null;
            $policies[$operationCode] = [
                'customer_enabled' => (bool) ($policy->customer_enabled ?? false),
                'required_capability_code' => $requiredCapability === null
                    ? null
                    : $this->databaseString($requiredCapability, 'Service operation policy capability'),
            ];
        }

        $packageTypes = [];
        foreach ($connection->table('plan_offering_packages')
            ->where('plan_offering_id', $planOfferingId)
            ->distinct()
            ->pluck('package_type') as $packageType) {
            $packageTypes[] = $this->databaseString($packageType, 'Service package type');
        }

        $verifiedCapabilities = [];
        foreach ($connection->table('panel_target_capabilities')
            ->where('panel_service_target_id', $targetId)
            ->where('verification_status', 'verified')
            ->pluck('capability_code') as $capabilityCode) {
            $verifiedCapabilities[] = $this->databaseString($capabilityCode, 'Service target capability');
        }

        return $this->allowedActionResolver->resolve(
            $lifecycleState,
            true,
            $policies,
            $packageTypes,
            $verifiedCapabilities,
        );
    }

    /** @param list<mixed> $publicIds */
    private function searchResult(int $subjectUserId, array $publicIds): TelegramOwnedServiceSearchResult
    {
        $matches = [];
        foreach ($publicIds as $publicId) {
            $value = $this->databaseString($publicId, 'Service search public ID');
            $matches[$value] = true;
            if (count($matches) > 1) {
                return TelegramOwnedServiceSearchResult::ambiguous();
            }
        }
        $matchedPublicIds = array_keys($matches);
        if ($matchedPublicIds === []) {
            return TelegramOwnedServiceSearchResult::notFound();
        }

        return TelegramOwnedServiceSearchResult::matched($this->selectionToken($subjectUserId, $matchedPublicIds[0]));
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram owned Service projection is self-only.');
        }
    }

    private function selectionToken(int $userId, string $publicId): string
    {
        return substr(hash('sha256', "telegram-owned-service-v1:{$userId}:{$publicId}"), 0, 40);
    }

    private function planName(mixed $product, mixed $variant): string
    {
        $productName = $this->databaseString($product, 'Service product label');
        $variantName = $this->optionalDatabaseString($variant);

        return $variantName === null ? $productName : $productName.' — '.$variantName;
    }

    private function optionalPlanName(mixed $product, mixed $variant): ?string
    {
        $productName = $this->optionalDatabaseString($product);
        if ($productName === null) {
            return null;
        }
        $variantName = $this->optionalDatabaseString($variant);

        return $variantName === null ? $productName : $productName.' — '.$variantName;
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function optionalDatabaseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Optional Service projection value is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $validated;
    }

    private function optionalNonNegativeInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($validated === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $validated;
    }
}
