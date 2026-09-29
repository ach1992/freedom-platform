<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Operations\Application\Contracts\BackupTelegramDeliveryQueue;

final readonly class TelegramBackupDeliveryQueue implements BackupTelegramDeliveryQueue
{
    public function __construct(private TelegramBackupProtectedReferenceDeliveryQueue $delivery) {}

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
    ): string {
        $receipt = $this->delivery->send(
            $recipientChatId,
            TelegramProtectedPresentationReference::backupExport(
                $backupId,
                $item,
                $partIndex,
                $partCount,
                $contentBytes,
                $contentSha256,
            ),
            $requestKey,
            $correlationId,
        );

        return $receipt->publicId;
    }
}
