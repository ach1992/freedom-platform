<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Application\Clock;

final readonly class ProductCategoryService
{
    use ProductCategoryDefinitionOperations;
    use ProductCategoryLifecycleOperations;
    use ProductCategoryServiceSupport;

    private const TARGET_TYPE = 'catalog_category';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}
}
