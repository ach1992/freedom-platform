<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use DomainException;

/** Narrow reviewed gateway for permission-aware report export references only. */
final readonly class TelegramReportingProtectedReferenceDeliveryQueue
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}

    /** @requirement REP-003 ACL-001 ACL-002 DAT-003 SEC-002 OPS-003 */
    public function send(
        int $recipientChatId,
        TelegramProtectedPresentationReference $reference,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        if (! $reference->isReportExport()) {
            throw new DomainException('Reporting Telegram delivery requires a report-export reference.');
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
