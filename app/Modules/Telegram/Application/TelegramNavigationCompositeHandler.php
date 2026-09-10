<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;

/**
 * Keeps the established navigation handler unchanged while routing bounded
 * payment journeys to their dedicated reviewed gateways.
 */
final readonly class TelegramNavigationCompositeHandler implements TelegramInteractionHandler
{
    public function __construct(
        private TelegramNavigationHandler $navigation,
        private TelegramAgentNavigationHandler $agent,
        private TelegramGiftCardNavigationHandler $giftCards,
        private TelegramUsdtNavigationHandler $usdt,
        private TelegramZarinpalNavigationHandler $zarinpal,
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($this->agent->supports($action)) {
            $this->agent->handle($action);

            return;
        }
        if ($this->giftCards->supports($action)) {
            $this->giftCards->handle($action);

            return;
        }
        if ($this->usdt->supports($action)) {
            $this->usdt->handle($action);

            return;
        }
        if ($this->zarinpal->supports($action)) {
            $this->zarinpal->handle($action);

            return;
        }

        $this->navigation->handle($action);
    }
}
