<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Contracts;

use App\Modules\Customers\Application\CustomerTierPurchaseMetrics;

interface CustomerTierPurchaseMetricsSource
{
    /** @requirement USR-002 */
    public function metricsFor(int $userId): CustomerTierPurchaseMetrics;
}
