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
        public ?NonRestrictedTelegramPresentation $presentation,
    ) {
        $presentation?->assertTrustedProvenance();

        if ($recipientChatId === 0) {
            throw new InvalidArgumentException('Telegram recipient chat identity must be non-zero.');
        }

        if ($action === TelegramDeliveryAction::Send) {
            if ($targetMessageId !== null || $presentation === null) {
                throw new InvalidArgumentException('Telegram send requires presentation text and no target message.');
            }

            return;
        }

        if ($targetMessageId === null || $targetMessageId < 1) {
            throw new InvalidArgumentException('Telegram edit/delete requires a positive target message identity.');
        }

        if ($action === TelegramDeliveryAction::Edit && $presentation === null) {
            throw new InvalidArgumentException('Telegram edit requires presentation text.');
        }

        if ($action === TelegramDeliveryAction::Delete && $presentation !== null) {
            throw new InvalidArgumentException('Telegram delete must not carry presentation text.');
        }
    }
}
