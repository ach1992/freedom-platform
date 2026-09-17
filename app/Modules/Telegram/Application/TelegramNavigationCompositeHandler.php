<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;

/**
 * Keeps the established navigation handler unchanged while routing bounded
 * payment and Support extension journeys to their dedicated reviewed gateways.
 */
final readonly class TelegramNavigationCompositeHandler implements TelegramInteractionHandler
{
    public function __construct(
        private TelegramNavigationHandler $navigation,
        private TelegramAgentNavigationHandler $agent,
        private TelegramTrialNavigationHandler $trial,
        private TelegramSupportNavigationHandler $support,
        private TelegramSupportRoutingNavigationHandler $supportRouting,
        private TelegramSupportAttachmentNavigationHandler $supportAttachments,
        private TelegramSupportMembershipFreshnessGuard $supportMembership,
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
        if ($this->supportAttachments->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->supportAttachments->handle($action);

            return;
        }
        if ($this->supportRouting->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->supportRouting->handle($action);

            return;
        }
        if ($this->support->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->support->handle($action);

            return;
        }
        if ($this->trial->supports($action)) {
            $this->trial->handle($action);

            return;
        }
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
