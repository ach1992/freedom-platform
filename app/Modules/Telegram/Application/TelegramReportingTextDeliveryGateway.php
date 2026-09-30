<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Reporting\Application\Contracts\ReportingTextDeliveryGateway;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use DomainException;

/** Reviewed non-restricted gateway for aggregate permission-aware report text. */
final readonly class TelegramReportingTextDeliveryGateway implements ReportingTextDeliveryGateway
{
    public function __construct(
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
    ) {}

    public function findExisting(
        int $recipientChatId,
        string $requestKey,
        string $correlationId,
    ): ?string {
        if ($recipientChatId === 0) {
            throw new DomainException('Reporting Telegram destination must be non-zero.');
        }

        return $this->delivery->findExistingSendByRequestKey(
            $recipientChatId,
            $requestKey,
            $correlationId,
        )?->publicId;
    }

    /** @requirement REP-001 REP-002 REP-003 DAT-003 SEC-002 OPS-003 */
    public function send(
        int $recipientChatId,
        string $text,
        string $requestKey,
        string $correlationId,
    ): string {
        if ($recipientChatId === 0) {
            throw new DomainException('Reporting Telegram destination must be non-zero.');
        }

        $presentation = $this->presentations->fromSource(
            new readonly class($text) implements NonRestrictedTelegramPresentationSource
            {
                public function __construct(private string $text) {}

                public function nonRestrictedTelegramText(): string
                {
                    return $this->text;
                }
            },
        );

        return $this->delivery->queue(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            null,
            $presentation,
            $requestKey,
            $correlationId,
        )->publicId;
    }
}
