<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

interface ReportingTextDeliveryGateway
{
    public function send(
        int $recipientChatId,
        string $text,
        string $requestKey,
        string $correlationId,
    ): string;
}
