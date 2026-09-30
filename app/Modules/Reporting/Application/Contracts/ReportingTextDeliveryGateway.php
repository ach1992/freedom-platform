<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Contracts;

interface ReportingTextDeliveryGateway
{
    public function findExisting(
        int $recipientChatId,
        string $requestKey,
        string $correlationId,
    ): ?string;

    public function send(
        int $recipientChatId,
        string $text,
        string $requestKey,
        string $correlationId,
    ): string;
}
