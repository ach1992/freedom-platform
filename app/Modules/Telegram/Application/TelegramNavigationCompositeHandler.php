<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;

/**
 * Keeps the established navigation handler unchanged while routing the bounded
 * Gift Card journey to its dedicated reviewed gateway.
 */
final readonly class TelegramNavigationCompositeHandler implements TelegramInteractionHandler
{
    public function __construct(
        private TelegramNavigationHandler $navigation,
        private TelegramGiftCardNavigationHandler $giftCards,
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($this->giftCards->supports($action)) {
            $this->giftCards->handle($action);

            return;
        }

        $this->navigation->handle($action);
    }
}
