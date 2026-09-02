<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Catalog\Domain\RouteSelectionActor;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramCustomerPurchaseCatalogService implements TelegramCustomerPurchaseCatalog
{
    private const MAXIMUM_PAGE_SIZE = 6;

    public function __construct(
        private DatabaseManager $database,
        private PlanOfferingActorEligibility $eligibility,
        private RouteOperationalVerifier $routeVerifier,
        private TargetCapacityAllocator $capacity,
    ) {}

    /** @requirement BUY-001 BUY-003 CAT-002 CAT-003 CAT-008 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramCustomerPurchaseCatalogPage {
        $this->assertSelf($actorUserId, $subjectUserId);
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new InvalidArgumentException('Telegram purchase catalog page request is invalid.');
        }

        $items = $this->eligibleOfferings($subjectUserId);
        $totalItems = count($items);
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);
        $pageItems = array_values(array_slice($items, ($effectivePage - 1) * $pageSize, $pageSize));

        return new TelegramCustomerPurchaseCatalogPage($pageItems, $effectivePage, $totalPages, $totalItems);
    }

    /** @requirement BUY-001 BUY-003 DAT-002 DAT-003 SEC-002 */
    public function offeringForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $selectionToken,
    ): TelegramCustomerPurchaseOffering {
        $this->assertSelf($actorUserId, $subjectUserId);
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram purchase offering selection is invalid.');
        }

        foreach ($this->eligibleOfferings($subjectUserId) as $offering) {
            if (hash_equals($offering->selectionToken, $selectionToken)) {
                return $offering;
            }
        }

        throw new AuthorizationException('Telegram purchase offering is unavailable for this actor.');
    }

    /** @return list<TelegramCustomerPurchaseOffering> */
    private function eligibleOfferings(int $userId): array
    {
        $connection = $this->database->connection();
        $this->assertActiveCustomer($connection, $userId);
        $actor = $this->eligibility->authoritativeActor($connection, $userId, RouteSelectionActor::Customer);

        /** @var list<object{id:int|string,code:string,category_name_fa:string,category_name_en:?string,product_name_fa:string,product_name_en:?string,variant_name_fa:?string,variant_name_en:?string,service_mode_label_fa:string,service_mode_label_en:?string,audience:string,server_selection_mode:string,protocol_selection_mode:string,tag_match_mode:string,base_price_irr:int|string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null}> $rows */
        $rows = $connection->table('plan_offerings as offering')
            ->join('products as product', 'product.id', '=', 'offering.product_id')
            ->join('product_categories as category', 'category.id', '=', 'product.category_id')
            ->leftJoin('product_variants as variant', 'variant.id', '=', 'offering.variant_id')
            ->where('offering.state', 'active')
            ->where('offering.visibility', 'visible')
            ->where('product.state', 'active')
            ->where('product.visibility', 'visible')
            ->where('category.state', 'active')
            ->whereIn('offering.audience', ['customers', 'both'])
            ->where(function ($query): void {
                $query->whereNull('offering.variant_id')->orWhere('variant.state', 'active');
            })
            ->orderBy('category.sort_order')
            ->orderBy('category.id')
            ->orderBy('product.sort_order')
            ->orderBy('product.id')
            ->orderBy('offering.sort_order')
            ->orderBy('offering.id')
            ->get([
                'offering.id', 'offering.code', 'offering.service_mode_label_fa', 'offering.service_mode_label_en',
                'offering.audience', 'offering.server_selection_mode', 'offering.protocol_selection_mode',
                'offering.tag_match_mode', 'offering.base_price_irr', 'offering.duration_days',
                'offering.data_allowance_bytes', 'offering.device_limit',
                'category.name_fa as category_name_fa', 'category.name_en as category_name_en',
                'product.name_fa as product_name_fa', 'product.name_en as product_name_en',
                'variant.name_fa as variant_name_fa', 'variant.name_en as variant_name_en',
            ])
            ->all();

        $items = [];
        foreach ($rows as $row) {
            $offeringId = $this->positiveDatabaseInt($row->id, 'Plan Offering ID');
            try {
                $this->eligibility->assertAudience((string) $row->audience, RouteSelectionActor::Customer);
                $this->eligibility->assertOfferingEligibility(
                    $connection,
                    $offeringId,
                    (string) $row->tag_match_mode,
                    $actor,
                );
            } catch (DomainException) {
                continue;
            }
            if (! $this->hasOperationalRoute(
                $connection,
                $offeringId,
                (string) $row->server_selection_mode,
                (string) $row->protocol_selection_mode,
            )) {
                continue;
            }

            $offeringCode = $this->databaseString($row->code, 'Plan Offering code');
            $items[] = new TelegramCustomerPurchaseOffering(
                $this->selectionToken($userId, $offeringCode),
                $this->databaseString($row->category_name_fa, 'Purchase category Persian label'),
                $this->optionalDatabaseString($row->category_name_en),
                $this->databaseString($row->product_name_fa, 'Purchase product Persian label'),
                $this->optionalDatabaseString($row->product_name_en),
                $this->optionalDatabaseString($row->variant_name_fa),
                $this->optionalDatabaseString($row->variant_name_en),
                $this->databaseString($row->service_mode_label_fa, 'Purchase service mode Persian label'),
                $this->optionalDatabaseString($row->service_mode_label_en),
                $this->nonNegativeDatabaseInt($row->base_price_irr, 'Plan Offering base price'),
                $this->positiveDatabaseInt($row->duration_days, 'Plan Offering duration'),
                $this->optionalPositiveDatabaseInt($row->data_allowance_bytes, 'Plan Offering data allowance'),
                $this->optionalPositiveDatabaseInt($row->device_limit, 'Plan Offering device limit'),
            );
        }

        return $items;
    }

    private function assertActiveCustomer(Connection $connection, int $userId): void
    {
        if ($userId < 1) {
            throw new AuthorizationException('Telegram purchase catalog access denied.');
        }
        /** @var object{account_type:string,account_status:string}|null $user */
        $user = $connection->table('users')->where('id', $userId)->first(['account_type', 'account_status']);
        if ($user === null || $user->account_type !== 'customer' || $user->account_status !== 'active') {
            throw new AuthorizationException('Telegram purchase catalog requires an active customer.');
        }
    }

    private function hasOperationalRoute(
        Connection $connection,
        int $offeringId,
        string $serverSelectionMode,
        string $protocolSelectionMode,
    ): bool {
        $profiles = $connection->table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $offeringId);
        if ($protocolSelectionMode === 'customer_selects') {
            $profiles->where('customer_selectable', true);
        } elseif (in_array($protocolSelectionMode, ['fixed', 'system_selects'], true)) {
            $profiles->where('is_default', true);
        } else {
            throw new RuntimeException('Stored Plan Offering protocol selection mode is invalid.');
        }
        /** @var list<int|string> $profileRows */
        $profileRows = $profiles->orderBy('panel_protocol_profile_id')->pluck('panel_protocol_profile_id')->all();
        if ($profileRows === []) {
            return false;
        }
        $profileIds = array_map(static fn (int|string $id): int => (int) $id, $profileRows);

        $policyId = $connection->table('plan_offering_route_policies')
            ->where('plan_offering_id', $offeringId)
            ->value('id');
        if (! is_int($policyId) && ! is_string($policyId)) {
            return false;
        }
        $routes = $connection->table('plan_offering_routes')
            ->where('plan_offering_route_policy_id', (int) $policyId);
        if ($serverSelectionMode === 'customer_selects') {
            $routes->where('customer_selectable', true);
        } elseif (! in_array($serverSelectionMode, ['system_selects', 'hybrid'], true)) {
            throw new RuntimeException('Stored Plan Offering server selection mode is invalid.');
        }
        /** @var list<object{sales_server_id:int|string,panel_service_target_id:int|string}> $routeRows */
        $routeRows = $routes
            ->orderBy('priority')
            ->get(['sales_server_id', 'panel_service_target_id'])
            ->all();

        foreach ($routeRows as $route) {
            foreach ($profileIds as $profileId) {
                try {
                    $serviceTargetId = $this->positiveDatabaseInt(
                        $route->panel_service_target_id,
                        'Route service target ID',
                    );
                    $this->routeVerifier->assertOperational(
                        $connection,
                        $offeringId,
                        $this->positiveDatabaseInt($route->sales_server_id, 'Route sales server ID'),
                        $serviceTargetId,
                        $profileId,
                    );
                    $availability = $this->capacity->availability($serviceTargetId);
                    if ($availability->acceptingReservations && $availability->availableUnits > 0) {
                        return true;
                    }
                } catch (RouteCandidateUnavailable) {
                    continue;
                }
            }
        }

        return false;
    }

    private function selectionToken(int $userId, string $offeringCode): string
    {
        return substr(hash('sha256', "telegram-purchase-offering-v1:{$userId}:{$offeringCode}"), 0, 40);
    }

    private function assertSelf(int $actorUserId, int $subjectUserId): void
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram purchase catalog self access denied.');
        }
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
            throw new RuntimeException('Optional purchase catalog string is invalid.');
        }

        return $value;
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function nonNegativeDatabaseInt(mixed $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $normalized;
    }

    private function optionalPositiveDatabaseInt(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveDatabaseInt($value, $label);
    }
}
