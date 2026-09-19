<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;

final readonly class TelegramPrivateMediaDeliveryQueue
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}

    public function findExistingSend(
        int $recipientChatId,
        TelegramPrivateMediaPresentationReference $reference,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): ?TelegramDeliveryOperationReceipt {
        return $this->delivery->findExistingPrivateMediaReference(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            $reference,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }

    public function send(
        int $recipientChatId,
        TelegramPrivateMediaPresentationReference $reference,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        return $this->delivery->queuePrivateMediaReference(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            $reference,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }
}
