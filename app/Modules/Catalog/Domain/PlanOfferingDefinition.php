<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use App\Modules\Customers\Domain\CustomerTierCode;
use App\Modules\Panels\Domain\PanelCapabilityCode;
use InvalidArgumentException;

final readonly class PlanOfferingDefinition
{
    public string $code;

    /** @var list<string> */
    public array $tierCodes;

    /** @var list<int> */
    public array $tagIds;

    /** @var list<OfferingProtocolAssignment> */
    public array $protocols;

    /** @var list<string> */
    public array $requiredCapabilities;

    /** @var list<OfferingOperationPolicy> */
    public array $operations;

    /** @var list<OfferingPackageDefinition> */
    public array $packages;

    /**
     * @param  list<string>  $tierCodes
     * @param  list<int>  $tagIds
     * @param  list<OfferingProtocolAssignment>  $protocols
     * @param  list<string>  $requiredCapabilities
     * @param  list<OfferingOperationPolicy>  $operations
     * @param  list<OfferingPackageDefinition>  $packages
     */
    public function __construct(
        string $code,
        public int $productId,
        public ?int $variantId,
        public int $salesServerId,
        public int $serviceTargetId,
        public PlanOfferingServiceMode $serviceMode,
        public PlanOfferingAudience $audience,
        public PlanOfferingServerSelectionMode $serverSelectionMode,
        public PlanOfferingProtocolSelectionMode $protocolSelectionMode,
        public PlanOfferingTagMatchMode $tagMatchMode,
        public int $basePriceIrr,
        public int $durationDays,
        public ?int $dataAllowanceBytes,
        public ?int $deviceLimit,
        public int $sortOrder,
        public int $minPurchaseQuantity,
        public int $maxPurchaseQuantity,
        public bool $discountEligible,
        public bool $autoRenewAllowed,
        public bool $customPlanAllowed,
        public bool $trialAllowed,
        array $tierCodes,
        array $tagIds,
        array $protocols,
        array $requiredCapabilities,
        array $operations,
        array $packages,
    ) {
        $this->code = CatalogCode::fromInput($code)->value;
        self::positive($productId, 'Product ID');
        self::positive($salesServerId, 'Sales server ID');
        self::positive($serviceTargetId, 'Service target ID');
        if ($variantId !== null) {
            self::positive($variantId, 'Variant ID');
        }

        if ($basePriceIrr < 0 || $sortOrder < 0) {
            throw new InvalidArgumentException('Offering price and sort order must not be negative.');
        }
        if ($durationDays < 1) {
            throw new InvalidArgumentException('Offering duration must be positive.');
        }
        if ($dataAllowanceBytes !== null && $dataAllowanceBytes < 1) {
            throw new InvalidArgumentException('Offering data allowance must be positive or null for unlimited.');
        }
        if ($deviceLimit !== null && $deviceLimit < 1) {
            throw new InvalidArgumentException('Offering device limit must be positive or null for unlimited.');
        }
        if ($minPurchaseQuantity < 1 || $maxPurchaseQuantity < $minPurchaseQuantity) {
            throw new InvalidArgumentException('Offering purchase quantity range is invalid.');
        }

        $this->tierCodes = self::normalizeTiers($tierCodes);
        $this->tagIds = self::normalizePositiveIds($tagIds, 'Tag ID');
        $this->protocols = self::normalizeProtocols($protocols, $protocolSelectionMode);
        $this->requiredCapabilities = self::normalizeCapabilities($requiredCapabilities);
        $this->operations = self::normalizeOperations($operations);
        $this->packages = self::normalizePackages($packages);
    }

    /** @return array<string, bool|int|string|null> */
    public function scalarPayload(): array
    {
        return [
            'code' => $this->code,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'sales_server_id' => $this->salesServerId,
            'service_target_id' => $this->serviceTargetId,
            'service_mode_code' => $this->serviceMode->code,
            'service_mode_label_fa' => $this->serviceMode->labelFa,
            'service_mode_label_en' => $this->serviceMode->labelEn,
            'audience' => $this->audience->value,
            'server_selection_mode' => $this->serverSelectionMode->value,
            'protocol_selection_mode' => $this->protocolSelectionMode->value,
            'tag_match_mode' => $this->tagMatchMode->value,
            'base_price_irr' => $this->basePriceIrr,
            'duration_days' => $this->durationDays,
            'data_allowance_bytes' => $this->dataAllowanceBytes,
            'device_limit' => $this->deviceLimit,
            'sort_order' => $this->sortOrder,
            'min_purchase_quantity' => $this->minPurchaseQuantity,
            'max_purchase_quantity' => $this->maxPurchaseQuantity,
            'discount_eligible' => $this->discountEligible,
            'auto_renew_allowed' => $this->autoRenewAllowed,
            'custom_plan_allowed' => $this->customPlanAllowed,
            'trial_allowed' => $this->trialAllowed,
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            ...$this->scalarPayload(),
            'tier_codes' => $this->tierCodes,
            'tag_ids' => $this->tagIds,
            'protocols' => array_map(
                static fn (OfferingProtocolAssignment $profile): array => [
                    'protocol_profile_id' => $profile->protocolProfileId,
                    'customer_selectable' => $profile->customerSelectable,
                    'default' => $profile->default,
                ],
                $this->protocols,
            ),
            'required_capabilities' => $this->requiredCapabilities,
            'operations' => array_map(
                static fn (OfferingOperationPolicy $operation): array => [
                    'operation' => $operation->operation->value,
                    'customer_enabled' => $operation->customerEnabled,
                    'administrator_enabled' => $operation->administratorEnabled,
                    'price_irr' => $operation->priceIrr,
                    'discount_eligible' => $operation->discountEligible,
                    'required_capability_code' => $operation->requiredCapabilityCode,
                ],
                $this->operations,
            ),
            'packages' => array_map(
                static fn (OfferingPackageDefinition $package): array => [
                    'code' => $package->code,
                    'type' => $package->type->value,
                    'name_fa' => $package->nameFa,
                    'name_en' => $package->nameEn,
                    'price_irr' => $package->priceIrr,
                    'duration_days' => $package->durationDays,
                    'data_bytes' => $package->dataBytes,
                    'discount_eligible' => $package->discountEligible,
                    'sort_order' => $package->sortOrder,
                ],
                $this->packages,
            ),
        ];
    }

    /**
     * @param  list<string>  $tierCodes
     * @return list<string>
     */
    private static function normalizeTiers(array $tierCodes): array
    {
        $valid = array_map(static fn (CustomerTierCode $tier): string => $tier->value, CustomerTierCode::cases());
        $normalized = [];
        foreach ($tierCodes as $tierCode) {
            $tier = strtolower(trim($tierCode));
            if (! in_array($tier, $valid, true)) {
                throw new InvalidArgumentException('Offering tier code is invalid.');
            }
            $normalized[] = $tier;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function normalizePositiveIds(array $ids, string $label): array
    {
        foreach ($ids as $id) {
            self::positive($id, $label);
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  list<OfferingProtocolAssignment>  $protocols
     * @return list<OfferingProtocolAssignment>
     */
    private static function normalizeProtocols(
        array $protocols,
        PlanOfferingProtocolSelectionMode $mode,
    ): array {
        if ($protocols === []) {
            throw new InvalidArgumentException('Offering requires at least one protocol profile.');
        }

        /** @var array<int, OfferingProtocolAssignment> $byId */
        $byId = [];
        $defaultCount = 0;
        $selectableCount = 0;
        foreach ($protocols as $protocol) {
            if (isset($byId[$protocol->protocolProfileId])) {
                throw new InvalidArgumentException('Offering protocol profiles must be unique.');
            }
            $byId[$protocol->protocolProfileId] = $protocol;
            $defaultCount += $protocol->default ? 1 : 0;
            $selectableCount += $protocol->customerSelectable ? 1 : 0;
        }

        if ($defaultCount !== 1) {
            throw new InvalidArgumentException('Offering requires exactly one default protocol profile.');
        }
        if ($mode === PlanOfferingProtocolSelectionMode::Fixed && count($protocols) !== 1) {
            throw new InvalidArgumentException('Fixed protocol selection requires exactly one profile.');
        }
        if ($mode === PlanOfferingProtocolSelectionMode::Customer && $selectableCount < 1) {
            throw new InvalidArgumentException('Customer protocol selection requires a selectable profile.');
        }
        if ($mode !== PlanOfferingProtocolSelectionMode::Customer && $selectableCount > 0) {
            throw new InvalidArgumentException('Only customer protocol selection may expose selectable profiles.');
        }

        ksort($byId, SORT_NUMERIC);

        return array_values($byId);
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private static function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];
        foreach ($capabilities as $capability) {
            $normalized[] = PanelCapabilityCode::fromInput($capability)->value;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  list<OfferingOperationPolicy>  $operations
     * @return list<OfferingOperationPolicy>
     */
    private static function normalizeOperations(array $operations): array
    {
        /** @var array<string, OfferingOperationPolicy> $byCode */
        $byCode = [];
        foreach ($operations as $operation) {
            $code = $operation->operation->value;
            if (isset($byCode[$code])) {
                throw new InvalidArgumentException('Offering operation policies must be unique.');
            }
            $byCode[$code] = $operation;
        }
        ksort($byCode, SORT_STRING);

        return array_values($byCode);
    }

    /**
     * @param  list<OfferingPackageDefinition>  $packages
     * @return list<OfferingPackageDefinition>
     */
    private static function normalizePackages(array $packages): array
    {
        /** @var array<string, OfferingPackageDefinition> $byCode */
        $byCode = [];
        foreach ($packages as $package) {
            if (isset($byCode[$package->code])) {
                throw new InvalidArgumentException('Offering package codes must be unique.');
            }
            $byCode[$package->code] = $package;
        }
        ksort($byCode, SORT_STRING);

        return array_values($byCode);
    }

    private static function positive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException($label.' must be positive.');
        }
    }
}
