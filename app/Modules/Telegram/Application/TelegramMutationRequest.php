<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use InvalidArgumentException;

final readonly class TelegramMutationRequest
{
    public function __construct(
        public TelegramDeliveryAction $action,
        public int $recipientChatId,
        public ?int $targetMessageId,
        public NonRestrictedTelegramPresentation|ConfidentialTelegramPresentation|TelegramProtectedPresentationReference|TelegramPrivateMediaPresentationReference|TelegramSourceMessagePresentationReference|null $presentation,
        public TelegramResolvedInlineKeyboardMarkup|TelegramResolvedContactRequestMarkup|null $inlineKeyboard = null,
    ) {
        if ($recipientChatId === 0) {
            throw new InvalidArgumentException('Telegram recipient chat identity must be non-zero.');
        }

        if ($action === TelegramDeliveryAction::Send) {
            if ($targetMessageId !== null || $presentation === null) {
                throw new InvalidArgumentException('Telegram send requires presentation text and no target message.');
            }

            return;
        }

        if ($inlineKeyboard instanceof TelegramResolvedContactRequestMarkup) {
            throw new InvalidArgumentException('Telegram contact-request reply markup is supported for send mutations only.');
        }

        if ($targetMessageId === null || $targetMessageId < 1) {
            throw new InvalidArgumentException('Telegram edit/delete requires a positive target message identity.');
        }

        if ($action === TelegramDeliveryAction::Edit && $presentation === null) {
            throw new InvalidArgumentException('Telegram edit requires presentation text.');
        }

        if ($action === TelegramDeliveryAction::Delete && ($presentation !== null || $inlineKeyboard !== null)) {
            throw new InvalidArgumentException('Telegram delete must not carry presentation text or interactive markup.');
        }
    }
}
