<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Application\Clock;

final readonly class PlanOfferingService
{
    use PlanOfferingDefinitionOperations;
    use PlanOfferingDependencyChecks;
    use PlanOfferingLifecycleOperations;
    use PlanOfferingPersistence;
    use PlanOfferingServiceSupport;

    private const TARGET_TYPE = 'plan_offering';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}
}
