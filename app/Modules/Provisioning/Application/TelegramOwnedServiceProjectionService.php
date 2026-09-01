<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use App\Modules\Telegram\Application\TelegramOwnedServiceListItem;
use App\Modules\Telegram\Application\TelegramOwnedServicePage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramOwnedServiceProjectionService implements TelegramOwnedServiceProjection
{
    private const MAXIMUM_PAGE_SIZE = 6;

    public function __construct(private DatabaseManager $database) {}

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
                'product.name_fa as product_name_fa', 'product.name_en as product_name_en',
                'variant.name_fa as variant_name_fa', 'variant.name_en as variant_name_en',
                'server.name_fa as server_name_fa', 'server.name_en as server_name_en',
            ]);
        if ($service === null) {
            throw new AuthorizationException('Telegram owned Service is unavailable for this actor.');
        }

        $snapshot = $connection->table('service_sync_snapshots')
            ->where('service_subscription_id', (int) $service->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first([
                'local_lifecycle_state', 'local_lifecycle_version',
                'local_remote_identity_generation', 'local_mutation_generation',
                'remote_disposition', 'remote_status', 'remote_data_limit_bytes',
                'remote_used_bytes', 'remote_expires_at', 'observed_at',
            ]);
        $snapshotIsCurrent = $snapshot !== null
            && (string) $snapshot->local_lifecycle_state === (string) $service->lifecycle_state
            && (int) $snapshot->local_lifecycle_version === (int) $service->lifecycle_version
            && (int) $snapshot->local_remote_identity_generation === (int) $service->remote_identity_generation
            && (int) $snapshot->local_mutation_generation === (int) $service->mutation_generation;
        $syncState = $snapshot === null ? 'none' : ($snapshotIsCurrent ? 'current' : 'stale');
        $remoteFactsVisible = $snapshotIsCurrent && (string) $snapshot->remote_disposition === 'present';

        return new TelegramOwnedServiceDetail(
            $this->databaseString($service->public_id ?? null, 'Service public ID'),
            $this->databaseString($service->lifecycle_state ?? null, 'Service lifecycle state'),
            $this->planName($service->product_name_fa ?? null, $service->variant_name_fa ?? null),
            $this->optionalPlanName($service->product_name_en ?? null, $service->variant_name_en ?? null),
            $this->databaseString($service->server_name_fa ?? null, 'Service server label'),
            $this->optionalDatabaseString($service->server_name_en ?? null),
            $this->optionalDatabaseString($service->provisioned_at ?? null),
            $syncState,
            $snapshotIsCurrent ? $this->databaseString($snapshot->remote_disposition ?? null, 'Service remote disposition') : null,
            $remoteFactsVisible ? $this->optionalDatabaseString($snapshot->remote_status ?? null) : null,
            $remoteFactsVisible ? $this->optionalNonNegativeInt($snapshot->remote_data_limit_bytes ?? null, 'Service remote data limit') : null,
            $remoteFactsVisible ? $this->optionalNonNegativeInt($snapshot->remote_used_bytes ?? null, 'Service remote usage') : null,
            $remoteFactsVisible ? $this->optionalDatabaseString($snapshot->remote_expires_at ?? null) : null,
            $snapshot === null ? null : $this->databaseString($snapshot->observed_at ?? null, 'Service observation time'),
        );
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
