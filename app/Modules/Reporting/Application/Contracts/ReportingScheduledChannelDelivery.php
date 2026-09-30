<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

use App\Modules\Reporting\Application\ReportDateRange;

interface ReportingScheduledChannelDelivery
{
    public function deliverToConfiguredChannel(
        int $actorUserId,
        ReportDateRange $range,
        string $correlationId,
        string $requestKey,
    ): string;
}
