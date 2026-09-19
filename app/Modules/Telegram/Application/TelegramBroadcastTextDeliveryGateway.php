<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;

final readonly class TelegramBroadcastTextDeliveryGateway
{
    public function __construct(
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
    ) {}

    /** @requirement COM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function queueSend(
        int $recipientChatId,
        string $text,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        $presentation = $this->presentations->fromSource(new TelegramBroadcastTextPresentationSource($text));

        return $this->delivery->queue(
            TelegramDeliveryAction::Send,
            $recipientChatId,
            null,
            $presentation,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }

    /** @requirement COM-003 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function queueEdit(
        int $recipientChatId,
        int $messageId,
        string $text,
        string $requestKey,
        string $correlationId,
        ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ): TelegramDeliveryOperationReceipt {
        $presentation = $this->presentations->fromSource(new TelegramBroadcastTextPresentationSource($text));

        return $this->delivery->queue(
            TelegramDeliveryAction::Edit,
            $recipientChatId,
            $messageId,
            $presentation,
            $requestKey,
            $correlationId,
            $inlineKeyboard,
        );
    }

    /** @requirement COM-003 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function queueDelete(
        int $recipientChatId,
        int $messageId,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        return $this->delivery->queue(
            TelegramDeliveryAction::Delete,
            $recipientChatId,
            $messageId,
            null,
            $requestKey,
            $correlationId,
        );
    }
}

final readonly class TelegramBroadcastTextPresentationSource implements NonRestrictedTelegramPresentationSource
{
    public function __construct(private string $text) {}

    public function nonRestrictedTelegramText(): string
    {
        return $this->text;
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'broadcast_text_presentation'];
    }
}
