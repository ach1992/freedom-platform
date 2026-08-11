<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Application\Clock;

final readonly class ProductVariantService
{
    use ProductVariantDefinitionOperations;
    use ProductVariantLifecycleOperations;
    use ProductVariantServiceSupport;

    private const TARGET_TYPE = 'catalog_variant';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}
}
