<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportMetric;

interface ReportingBroadcastMetricsSource
{
    /** @return list<ReportMetric> */
    public function metrics(ReportDateRange $range): array;
}
