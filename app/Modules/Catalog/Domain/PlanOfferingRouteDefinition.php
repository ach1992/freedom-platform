<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain;

use InvalidArgumentException;

final readonly class PlanOfferingRouteDefinition
{
    public ?string $disclosureFa;

    public ?string $disclosureEn;

    public function __construct(
        public int $salesServerId,
        public int $serviceTargetId,
        public PlanOfferingRouteType $type,
        public int $priority,
        public bool $customerSelectable,
        ?string $disclosureFa,
        ?string $disclosureEn,
    ) {
        if ($salesServerId < 1 || $serviceTargetId < 1) {
            throw new InvalidArgumentException('Offering route dependencies must be positive.');
        }
        if ($priority < 0) {
            throw new InvalidArgumentException('Offering route priority must not be negative.');
        }
        if (($type === PlanOfferingRouteType::Primary && $priority !== 0)
            || ($type === PlanOfferingRouteType::Fallback && $priority < 1)
        ) {
            throw new InvalidArgumentException('Offering route type and priority are inconsistent.');
        }

        $this->disclosureFa = CatalogText::optionalDescription($disclosureFa);
        $this->disclosureEn = CatalogText::optionalDescription($disclosureEn);
        if ($type === PlanOfferingRouteType::Fallback && $this->disclosureFa === null) {
            throw new InvalidArgumentException('Fallback route requires Persian disclosure text.');
        }
    }

    /** @return array<string, bool|int|string|null> */
    public function payload(): array
    {
        return [
            'sales_server_id' => $this->salesServerId,
            'service_target_id' => $this->serviceTargetId,
            'route_type' => $this->type->value,
            'priority' => $this->priority,
            'customer_selectable' => $this->customerSelectable,
            'disclosure_fa' => $this->disclosureFa,
            'disclosure_en' => $this->disclosureEn,
        ];
    }
}
