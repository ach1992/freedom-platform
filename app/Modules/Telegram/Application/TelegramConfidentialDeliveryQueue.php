<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;

/**
 * Narrow confidential send-only facade for reviewed private journeys.
 *
 * Callers never receive the generic TelegramDeliveryQueueService capability.
 * Runtime confidential provenance still validates the original reviewed source
 * behind this internal trampoline.
 */
final readonly class TelegramConfidentialDeliveryQueue
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}

    public function send(
        int $recipientChatId,
        ConfidentialTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        return $this->delivery->queueConfidential(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            null,
            $presentation,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }
}
