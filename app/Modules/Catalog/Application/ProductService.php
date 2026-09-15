<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Application\Clock;

final readonly class ProductService
{
    use ProductDefinitionOperations;
    use ProductLifecycleOperations;
    use ProductServiceSupport;

    private const TARGET_TYPE = 'catalog_product';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}
}
