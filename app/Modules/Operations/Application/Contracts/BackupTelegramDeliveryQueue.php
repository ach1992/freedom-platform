<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupTelegramDeliveryQueue
{
    public function queue(
        int $recipientChatId,
        string $backupId,
        string $item,
        int $partIndex,
        int $partCount,
        int $contentBytes,
        string $contentSha256,
        string $requestKey,
        string $correlationId,
    ): string;
}
