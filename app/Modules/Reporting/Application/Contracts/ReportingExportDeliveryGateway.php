<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

use App\Modules\Reporting\Application\ReportDateRange;

interface ReportingExportDeliveryGateway
{
    public function queue(
        int $recipientChatId,
        ReportDateRange $range,
        string $format,
        string $locale,
        string $requestKey,
        string $correlationId,
    ): string;
}
