<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use InvalidArgumentException;

final readonly class TelegramBroadcastLifecycleMutationRequest
{
    private function __construct(
        public TelegramBroadcastLifecycleAction $action,
        public int $recipientChatId,
        public int $messageId,
        public ?string $caption,
        public ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard,
    ) {
        if ($recipientChatId < 1 || $messageId < 1) {
            throw new InvalidArgumentException('Broadcast lifecycle target identity is invalid.');
        }

        if ($action === TelegramBroadcastLifecycleAction::Edit) {
            if ($caption === null
                || mb_strlen($caption) > 1024
                || strlen($caption) > 4096
                || ! mb_check_encoding($caption, 'UTF-8')
                || str_contains($caption, "\0")
            ) {
                throw new InvalidArgumentException('Broadcast lifecycle caption is invalid.');
            }

            return;
        }

        if ($caption !== null) {
            throw new InvalidArgumentException('Broadcast lifecycle caption is only valid for edit.');
        }
        if ($action === TelegramBroadcastLifecycleAction::Buttons) {
            return;
        }
        if ($inlineKeyboard !== null) {
            throw new InvalidArgumentException('Broadcast lifecycle keyboard is only valid for edit/buttons.');
        }
        if (! in_array($action, [
            TelegramBroadcastLifecycleAction::Pin,
            TelegramBroadcastLifecycleAction::Unpin,
        ], true)) {
            throw new InvalidArgumentException('Broadcast lifecycle mutation is not supported by the direct transport.');
        }
    }

    public static function editCaption(
        int $recipientChatId,
        int $messageId,
        string $caption,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard,
    ): self {
        return new self(
            TelegramBroadcastLifecycleAction::Edit,
            $recipientChatId,
            $messageId,
            $caption,
            $inlineKeyboard,
        );
    }

    public static function buttons(
        int $recipientChatId,
        int $messageId,
        ?TelegramResolvedInlineKeyboardMarkup $inlineKeyboard,
    ): self {
        return new self(
            TelegramBroadcastLifecycleAction::Buttons,
            $recipientChatId,
            $messageId,
            null,
            $inlineKeyboard,
        );
    }

    public static function pin(int $recipientChatId, int $messageId): self
    {
        return new self(TelegramBroadcastLifecycleAction::Pin, $recipientChatId, $messageId, null, null);
    }

    public static function unpin(int $recipientChatId, int $messageId): self
    {
        return new self(TelegramBroadcastLifecycleAction::Unpin, $recipientChatId, $messageId, null, null);
    }
}
