<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use DomainException;

/**
 * Narrow reviewed gateway for durable encrypted-backup references only.
 */
final readonly class TelegramBackupProtectedReferenceDeliveryQueue
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}

    public function send(
        int $recipientChatId,
        TelegramProtectedPresentationReference $reference,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        if (! $reference->isBackupExport()) {
            throw new DomainException('Backup Telegram delivery requires a backup-export reference.');
        }

        return $this->delivery->queueProtectedReference(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            $reference,
            $requestKey,
            $correlationId,
        );
    }
}
