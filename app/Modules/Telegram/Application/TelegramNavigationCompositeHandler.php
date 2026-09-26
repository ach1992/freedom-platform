<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramBroadcastNavigationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramNowPaymentsNavigationResolver;

/**
 * Keeps the established navigation handler unchanged while routing bounded
 * payment and Support extension journeys to their dedicated reviewed gateways.
 */
final readonly class TelegramNavigationCompositeHandler implements TelegramInteractionHandler
{
    public function __construct(
        private TelegramNavigationHandler $navigation,
        private TelegramAdministratorAccessNavigationHandler $adminAccess,
        private TelegramAlternativePaymentReviewNavigationHandler $adminPayments,
        private TelegramAdministratorSearchNavigationHandler $adminSearch,
        private TelegramAdminCustomerNavigationHandler $adminCustomers,
        private TelegramAdministratorServiceOperationsNavigationHandler $adminServiceOperations,
        private TelegramBroadcastNavigationResolver $broadcastResolver,
        private TelegramClientGuideNavigationHandler $clientGuides,
        private TelegramMenuConfigurationNavigationHandler $menuConfiguration,
        private TelegramMembershipConfigurationNavigationHandler $membershipConfiguration,
        private TelegramServiceAutoRenewNavigationHandler $serviceAutoRenew,
        private TelegramServiceAutoRenewPolicyNavigationHandler $serviceAutoRenewPolicy,
        private TelegramServiceLifecycleNavigationHandler $serviceLifecycle,
        private TelegramServiceReconfigurationNavigationHandler $serviceReconfiguration,
        private TelegramServiceNotificationPreferenceNavigationHandler $serviceNotifications,
        private TelegramAgentBulkPurchaseNavigationHandler $agentBulk,
        private TelegramAgentNavigationHandler $agent,
        private TelegramTrialNavigationHandler $trial,
        private TelegramSupportNavigationHandler $support,
        private TelegramSupportRatingNavigationHandler $supportRating,
        private TelegramSupportCategoryNavigationHandler $supportCategories,
        private TelegramSupportRoutingNavigationHandler $supportRouting,
        private TelegramSupportAttachmentNavigationHandler $supportAttachments,
        private TelegramSupportMembershipFreshnessGuard $supportMembership,
        private TelegramGiftCardNavigationHandler $giftCards,
        private TelegramPhoneVerificationNavigationHandler $phoneVerification,
        private TelegramWalletTopUpNavigationHandler $walletTopUps,
        private TelegramWalletTransferNavigationHandler $walletTransfers,
        private TelegramUsdtNavigationHandler $usdt,
        private TelegramNowPaymentsNavigationResolver $nowPaymentsResolver,
        private TelegramZarinpalNavigationHandler $zarinpal,
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if (TelegramBroadcastNavigationHandler::supportsAction($action)) {
            $this->broadcastResolver->resolve()->handle($action);

            return;
        }
        if ($this->clientGuides->supports($action)) {
            $this->clientGuides->handle($action);

            return;
        }
        if ($this->menuConfiguration->supports($action)) {
            $this->menuConfiguration->handle($action);

            return;
        }
        if ($this->membershipConfiguration->supports($action)) {
            $this->membershipConfiguration->handle($action);

            return;
        }
        if ($this->serviceAutoRenew->supports($action)) {
            $this->serviceAutoRenew->handle($action);

            return;
        }
        if ($this->serviceAutoRenewPolicy->supports($action)) {
            $this->serviceAutoRenewPolicy->handle($action);

            return;
        }
        if ($this->serviceLifecycle->supports($action)) {
            $this->serviceLifecycle->handle($action);

            return;
        }
        if ($this->serviceReconfiguration->supports($action)) {
            $this->serviceReconfiguration->handle($action);

            return;
        }
        if ($this->serviceNotifications->supports($action)) {
            $this->serviceNotifications->handle($action);

            return;
        }
        if ($this->adminAccess->supports($action)) {
            $this->adminAccess->handle($action);

            return;
        }
        if ($this->adminPayments->supports($action)) {
            $this->adminPayments->handle($action);

            return;
        }
        if ($this->adminSearch->supports($action)) {
            $this->adminSearch->handle($action);

            return;
        }
        if ($this->adminCustomers->supports($action)) {
            $this->adminCustomers->handle($action);

            return;
        }
        if ($this->adminServiceOperations->supports($action)) {
            $this->adminServiceOperations->handle($action);

            return;
        }
        if ($this->supportAttachments->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->supportAttachments->handle($action);

            return;
        }
        if ($this->supportRating->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->supportRating->handle($action);

            return;
        }
        if ($this->supportCategories->supports($action)) {
            $this->supportMembership->assertCurrent($action);
            $this->supportCategories->handle($action);

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
        if ($this->agentBulk->supports($action)) {
            $this->agentBulk->handle($action);

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
        if ($this->phoneVerification->supports($action)) {
            $this->phoneVerification->handle($action);

            return;
        }
        if ($this->walletTopUps->supports($action)) {
            $this->walletTopUps->handle($action);

            return;
        }
        if ($this->walletTransfers->supports($action)) {
            $this->walletTransfers->handle($action);

            return;
        }
        if ($this->usdt->supports($action)) {
            $this->usdt->handle($action);

            return;
        }
        if (TelegramNowPaymentsNavigationHandler::supportsAction($action)) {
            $this->nowPaymentsResolver->resolve()->handle($action);

            return;
        }
        if ($this->zarinpal->supports($action)) {
            $this->zarinpal->handle($action);

            return;
        }

        $this->navigation->handle($action);
    }
}
