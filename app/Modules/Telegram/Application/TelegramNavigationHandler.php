<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Promotions\Application\ReferralSelfSummary;
use App\Modules\Promotions\Application\ReferralSelfSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramManagedUsdtRateSettings;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Modules\Wallet\Application\WalletSelfBalanceSummary;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramNavigationHandler implements TelegramInteractionHandler
{
    private const STATE_MY_ACCOUNT = 'my_account';

    private const STATE_PURCHASE_CATALOG = 'purchase_catalog';

    private const STATE_PURCHASE_DISCOUNT_INPUT = 'purchase_discount_input';

    private const STATE_PURCHASE_DISCOUNT_SUBMITTING = 'purchase_discount_submitting';

    private const STATE_PURCHASE_PAYMENT_METHODS = 'purchase_payment_methods';

    private const STATE_PURCHASE_PAYMENT_METHODS_SUBMITTING = 'purchase_payment_methods_submitting';

    private const STATE_PURCHASE_PAYMENT_METHOD_SELECTED = 'purchase_payment_method_selected';

    private const STATE_PURCHASE_PAYMENT_METHOD_SELECTING = 'purchase_payment_method_selecting';

    private const STATE_PURCHASE_QUOTE = 'purchase_quote';

    private const STATE_PURCHASE_QUOTE_SUBMITTING = 'purchase_quote_submitting';

    private const STATE_PURCHASE_OFFERING = 'purchase_offering';

    private const STATE_MY_SERVICES = 'my_services';

    private const STATE_SERVICE_DETAIL = 'service_detail';

    private const STATE_SERVICE_SEARCH = 'service_search';

    private const STATE_ADMIN_CONTROL = 'admin_control';

    private const STATE_ADMIN_USDT_RATE = 'admin_usdt_rate';

    private const STATE_ADMIN_USDT_RATE_EDIT = 'admin_usdt_rate_edit';

    private const STATE_ADMIN_USDT_RATE_CONFIRM = 'admin_usdt_rate_confirm';

    private const STATE_ADMIN_USDT_RATE_SUBMITTING = 'admin_usdt_rate_submitting';

    private const ACTION_MY_ACCOUNT = 'navigation.my_account';

    private const ACTION_PURCHASE_CATALOG = 'navigation.purchase';

    private const ACTION_PURCHASE_PAGE = 'navigation.purchase.page';

    private const ACTION_PURCHASE_DISCOUNT = 'navigation.purchase.discount';

    private const ACTION_PURCHASE_PAYMENT_METHODS = 'navigation.purchase.payment_methods';

    private const ACTION_PURCHASE_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';

    private const ACTION_PURCHASE_QUOTE = 'navigation.purchase.quote';

    private const ACTION_PURCHASE_OFFERING_PREFIX = 'navigation.purchase.';

    private const ACTION_MY_SERVICES = 'navigation.my_services';

    private const ACTION_SERVICES_PAGE = 'navigation.services.page';

    private const ACTION_SERVICES_SEARCH = 'navigation.services.search';

    private const ACTION_SERVICE_DETAIL_PREFIX = 'navigation.service.';

    private const ACTION_SERVICE_RESEND = 'navigation.service.resend';

    private const ACTION_ADMIN_CONTROL = 'navigation.admin';

    private const ACTION_ADMIN_USDT_RATE = 'navigation.admin.usdt_rate';

    private const ACTION_ADMIN_USDT_RATE_EDIT = 'navigation.admin.usdt_rate.edit';

    private const ACTION_ADMIN_USDT_RATE_CONFIRM = 'navigation.admin.usdt_rate.confirm';

    private const ACTION_BACK = 'navigation.back';

    private const PURCHASE_PAGE_SIZE = 6;

    private const SERVICE_PAGE_SIZE = 6;

    public function __construct(
        private Translator $translator,
        private NonRestrictedTelegramPresentationFactory $presentations,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private WalletSelfBalanceService $wallets,
        private ReferralSelfSummaryService $referrals,
        private TelegramCustomerPurchaseQuote $purchaseQuotes,
        private TelegramCustomerPurchaseDiscountQuote $purchaseDiscountQuotes,
        private TelegramCustomerPurchasePaymentMethods $purchasePaymentMethods,
        private TelegramCustomerPurchaseOrder $purchaseOrders,
        private TelegramCustomerPurchaseCatalog $purchaseCatalog,
        private TelegramOwnedServiceProjection $services,
        private TelegramOwnedServiceDeliveryResender $serviceDeliveryResender,
        private TelegramManagedUsdtRateSettings $managedUsdtRateSettings,
        private DatabaseManager $database,
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->kind === TelegramInteractionActionKind::Callback) {
                if ($action->callbackPayload !== []) {
                    throw new RuntimeException('Telegram home callback payload is unsupported.');
                }
                if ($action->callbackAction === self::ACTION_MY_ACCOUNT) {
                    $this->showMyAccount($action);

                    return;
                }
                if ($action->callbackAction === self::ACTION_PURCHASE_CATALOG) {
                    $this->showPurchaseCatalog($action, 1);

                    return;
                }
                if ($action->callbackAction === self::ACTION_MY_SERVICES) {
                    $this->showMyServices($action, 1);

                    return;
                }
                if ($action->callbackAction === self::ACTION_ADMIN_CONTROL) {
                    $this->showAdminControl($action);

                    return;
                }

                throw new RuntimeException('Telegram home callback action is unsupported.');
            }

            $this->renderHome($action, $action->sessionVersion, $action->requestKey);

            return;
        }

        if ($action->sessionState === self::STATE_MY_ACCOUNT) {
            $this->handleMyAccount($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_CATALOG) {
            $this->handlePurchaseCatalog($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_OFFERING) {
            $this->handlePurchaseOffering($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_QUOTE) {
            $this->handlePurchaseQuote($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_DISCOUNT_INPUT) {
            $this->handlePurchaseDiscountInput($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_PAYMENT_METHODS) {
            $this->handlePurchasePaymentMethods($action);

            return;
        }

        if ($action->sessionState === self::STATE_PURCHASE_PAYMENT_METHOD_SELECTED) {
            $this->handlePurchasePaymentMethodSelected($action);

            return;
        }

        if ($action->sessionState === self::STATE_MY_SERVICES) {
            $this->handleMyServices($action);

            return;
        }

        if ($action->sessionState === self::STATE_SERVICE_DETAIL) {
            $this->handleServiceDetail($action);

            return;
        }

        if ($action->sessionState === self::STATE_SERVICE_SEARCH) {
            $this->handleServiceSearch($action);

            return;
        }

        if ($action->sessionState === self::STATE_ADMIN_CONTROL) {
            $this->handleAdminControl($action);

            return;
        }

        if ($action->sessionState === self::STATE_ADMIN_USDT_RATE) {
            $this->handleAdminUsdtRate($action);

            return;
        }

        if ($action->sessionState === self::STATE_ADMIN_USDT_RATE_EDIT) {
            $this->handleAdminUsdtRateEdit($action);

            return;
        }

        if ($action->sessionState === self::STATE_ADMIN_USDT_RATE_CONFIRM) {
            $this->handleAdminUsdtRateConfirm($action);

            return;
        }

        throw new RuntimeException('Telegram navigation session state is unsupported.');
    }

    private function handlePurchaseCatalog(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_PURCHASE_PAGE) {
                $this->showPurchaseCatalog($action, $this->purchasePageFromPayload($action->callbackPayload));

                return;
            }
            if (is_string($action->callbackAction)
                && str_starts_with($action->callbackAction, self::ACTION_PURCHASE_OFFERING_PREFIX)
                && $action->callbackPayload === []) {
                $selectionToken = substr($action->callbackAction, strlen(self::ACTION_PURCHASE_OFFERING_PREFIX));
                if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
                    throw new RuntimeException('Telegram purchase offering selection token is invalid.');
                }
                $this->showPurchaseOffering($action, $selectionToken);

                return;
            }

            throw new RuntimeException('Telegram purchase catalog callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handlePurchaseOffering(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PURCHASE_QUOTE && $action->callbackPayload === []) {
                $this->showPurchaseQuote($action);

                return;
            }
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram purchase offering callback action is unsupported.');
            }

            $this->returnPurchaseCatalog($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPurchaseCatalog($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handlePurchaseQuote(TelegramInteractionAction $action): void
    {
        $state = $this->purchaseQuoteStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PURCHASE_DISCOUNT
                && $action->callbackPayload === []
                && ! $this->purchaseQuoteHasDiscount($state)) {
                $this->showPurchaseDiscountInput($action, $state);

                return;
            }
            if ($action->callbackAction === self::ACTION_PURCHASE_PAYMENT_METHODS && $action->callbackPayload === []) {
                $this->showPurchasePaymentMethods($action);

                return;
            }
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram purchase Quote callback action is unsupported.');
            }

            $this->returnPurchaseOffering($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPurchaseOffering($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handlePurchaseDiscountInput(TelegramInteractionAction $action): void
    {
        $state = $this->purchaseQuoteStateFromPayload($action->sessionPayload);
        if ($this->purchaseQuoteHasDiscount($state)) {
            throw new RuntimeException('Telegram discounted Quote cannot accept another discount code.');
        }
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram purchase discount callback action is unsupported.');
            }

            $this->returnPurchaseQuoteFromDiscountInput($action, $state);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPurchaseQuoteFromDiscountInput($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText !== null) {
            $this->showPurchaseDiscountQuote($action, $state);
        }
    }

    private function handlePurchasePaymentMethods(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PURCHASE_PAYMENT_METHOD_SELECT) {
                $this->selectPurchasePaymentMethod($action, $this->purchasePaymentMethodFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram purchase payment-method callback action is unsupported.');
            }

            $this->returnPurchaseOfferingFromPaymentMethods($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPurchaseOfferingFromPaymentMethods($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handlePurchasePaymentMethodSelected(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram selected payment-method callback action is unsupported.');
            }

            $this->returnPurchasePaymentMethodsFromSelection($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPurchasePaymentMethodsFromSelection($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleMyAccount(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram My Account callback action is unsupported.');
            }

            $this->returnHome($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleMyServices(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_SERVICES_PAGE) {
                $this->showMyServices($action, $this->pageFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_SERVICES_SEARCH && $action->callbackPayload === []) {
                $this->showServiceSearch($action);

                return;
            }
            if (is_string($action->callbackAction)
                && str_starts_with($action->callbackAction, self::ACTION_SERVICE_DETAIL_PREFIX)
                && $action->callbackPayload === []) {
                $selectionToken = substr($action->callbackAction, strlen(self::ACTION_SERVICE_DETAIL_PREFIX));
                if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
                    throw new RuntimeException('Telegram Service selection token is invalid.');
                }
                $this->showServiceDetail($action, $selectionToken);

                return;
            }

            throw new RuntimeException('Telegram My Services callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleServiceSearch(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Service search callback action is unsupported.');
            }

            $this->returnMyServices($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnMyServices($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText !== null) {
            $this->searchService($action, $action->messageText);
        }
    }

    private function handleServiceDetail(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnMyServices($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_SERVICE_RESEND) {
                $this->resendServiceDelivery($action, $this->serviceSelectionToken($action->callbackPayload));

                return;
            }

            throw new RuntimeException('Telegram Service detail callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnMyServices($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleAdminControl(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_ADMIN_USDT_RATE && $action->callbackPayload === []) {
                $this->showAdminUsdtRate($action);

                return;
            }

            throw new RuntimeException('Telegram administrator control callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleAdminUsdtRate(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showAdminControl($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_ADMIN_USDT_RATE_EDIT && $action->callbackPayload === []) {
                $this->showAdminUsdtRateEdit($action);

                return;
            }

            throw new RuntimeException('Telegram administrator USDT rate callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleAdminUsdtRateEdit(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator USDT rate edit callback action is unsupported.');
            }

            $this->showAdminUsdtRate($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAdminUsdtRate($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText !== null) {
            $this->prepareAdminUsdtRateConfirmation($action, $action->messageText);
        }
    }

    private function handleAdminUsdtRateConfirm(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showAdminUsdtRateEdit($action);

                return;
            }
            if ($action->callbackAction === self::ACTION_ADMIN_USDT_RATE_CONFIRM && $action->callbackPayload === []) {
                $this->confirmAdminUsdtRate($action);

                return;
            }

            throw new RuntimeException('Telegram administrator USDT rate confirmation callback action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAdminUsdtRateEdit($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function showAdminControl(TelegramInteractionAction $action): void
    {
        if (! $this->managedUsdtRateSettings->availableFor($action->userId)) {
            $this->returnHome($action);

            return;
        }

        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_ADMIN_CONTROL,
            [],
            'nav-admin-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderAdminControl(
            $action,
            $session->version,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function showAdminUsdtRate(TelegramInteractionAction $action): void
    {
        try {
            $rate = $this->managedUsdtRateSettings->currentFor($action->userId);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_ADMIN_USDT_RATE,
            [],
            'nav-admin-usdt-rate-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderAdminUsdtRate(
            $action,
            $session->version,
            $rate,
            $this->localeForActor($action->userId),
            false,
            $action->requestKey,
        );
    }

    private function showAdminUsdtRateEdit(TelegramInteractionAction $action): void
    {
        if (! $this->managedUsdtRateSettings->availableFor($action->userId)) {
            $this->returnHome($action);

            return;
        }

        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_ADMIN_USDT_RATE_EDIT,
            [],
            'nav-admin-usdt-rate-edit-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderAdminUsdtRateEdit(
            $action,
            $session->version,
            $this->localeForActor($action->userId),
            'prompt',
        );
    }

    private function prepareAdminUsdtRateConfirmation(TelegramInteractionAction $action, string $input): void
    {
        $locale = $this->localeForActor($action->userId);
        $normalized = $this->normalizeUsdtRateInput($input);
        if ($normalized === null) {
            $this->renderAdminUsdtRateEdit($action, $action->sessionVersion, $locale, 'invalid');

            return;
        }

        try {
            $validated = $this->managedUsdtRateSettings->validateFor($action->userId, $normalized);
        } catch (AuthorizationException) {
            try {
                $this->returnHome($action);
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        } catch (\DomainException) {
            $this->renderAdminUsdtRateEdit($action, $action->sessionVersion, $locale, 'invalid');

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_ADMIN_USDT_RATE_CONFIRM,
                ['rate_irr' => $validated],
                'nav-admin-usdt-rate-confirm-preview:'.hash('sha256', $action->requestKey),
            );
        } catch (\DomainException) {
            // Another accepted interaction already moved this edit session. No financial mutation occurred.
            return;
        }
        $this->assertActorBinding($action, $session->userId);
        $this->renderAdminUsdtRateConfirmation(
            $action,
            $session->version,
            $validated,
            $locale,
            $action->requestKey,
        );
    }

    private function confirmAdminUsdtRate(TelegramInteractionAction $action): void
    {
        $locale = $this->localeForActor($action->userId);
        $normalized = $this->pendingUsdtRateFromPayload($action->sessionPayload);

        try {
            [$session, $rate] = $this->database->connection()->transaction(function () use ($action, $normalized): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_ADMIN_USDT_RATE_SUBMITTING,
                    [],
                    'nav-admin-usdt-rate-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $claim->userId);

                try {
                    $rate = $this->managedUsdtRateSettings->setFor(
                        $action->userId,
                        $normalized,
                        $this->usdtRateRequestKey($action),
                        $this->usdtRateCorrelationId($action),
                    );
                } catch (\DomainException $exception) {
                    throw new TelegramManagedUsdtRateInputRejected($exception);
                }

                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_ADMIN_USDT_RATE,
                    [],
                    'nav-admin-usdt-rate-set:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $session->userId);

                return [$session, $rate];
            }, 3);
        } catch (AuthorizationException) {
            try {
                $this->returnHome($action);
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        } catch (TelegramManagedUsdtRateInputRejected) {
            try {
                $this->showAdminUsdtRateEdit($action);
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        } catch (\DomainException) {
            // The accepted confirmation lost the session-version race before the financial mutation.
            return;
        }

        $this->renderAdminUsdtRate(
            $action,
            $session->version,
            $rate,
            $locale,
            true,
            $action->requestKey,
        );
    }

    private function renderAdminControl(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $requestKey,
    ): void {
        $rate = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_ADMIN_USDT_RATE,
            [],
            'nav-admin-usdt-rate:'.$requestKey,
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-admin-back:'.$requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.buttons.usdt_rate', $locale),
                $rate->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.admin.control', $locale),
            'nav-admin-delivery:'.$requestKey,
            'admin',
            $keyboard,
        );
    }

    private function renderAdminUsdtRate(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?TelegramManagedUsdtRateSnapshot $rate,
        string $locale,
        bool $updated,
        string $requestKey,
    ): void {
        $edit = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_ADMIN_USDT_RATE_EDIT,
            [],
            'nav-admin-usdt-rate-edit:'.$requestKey,
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-admin-usdt-rate-back:'.$requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.usdt_rate.edit_button', $locale),
                $edit->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);

        $this->queueConfidential(
            $action,
            $this->adminUsdtRateText($rate, $locale, $updated),
            'nav-admin-usdt-rate-delivery:'.$requestKey,
            $updated ? 'usdt-rate-updated' : 'usdt-rate',
            $keyboard,
        );
    }

    private function renderAdminUsdtRateConfirmation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $rateIrr,
        string $locale,
        string $requestKey,
    ): void {
        $confirm = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_ADMIN_USDT_RATE_CONFIRM,
            [],
            'nav-admin-usdt-rate-confirm:'.$requestKey,
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-admin-usdt-rate-confirm-back:'.$requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.usdt_rate.confirm_button', $locale),
                $confirm->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.admin.usdt_rate.confirm', $locale, [
                'rate' => $this->formatUsdtRateIrr($rateIrr),
            ]),
            'nav-admin-usdt-rate-confirm-delivery:'.$requestKey,
            'usdt-rate-confirm',
            $keyboard,
        );
    }

    private function renderAdminUsdtRateEdit(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $surface,
    ): void {
        if (! in_array($surface, ['prompt', 'invalid'], true)) {
            throw new RuntimeException('Telegram administrator USDT rate edit surface is invalid.');
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-admin-usdt-rate-edit-back:'.$surface.':'.$action->requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([[new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )]]);

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.admin.usdt_rate.'.$surface, $locale),
            'nav-admin-usdt-rate-edit-delivery:'.$surface.':'.$action->requestKey,
            'usdt-rate-edit-'.$surface,
            $keyboard,
        );
    }

    private function adminUsdtRateText(
        ?TelegramManagedUsdtRateSnapshot $rate,
        string $locale,
        bool $updated,
    ): string {
        $notice = $updated
            ? $this->translation('telegram.navigation.admin.usdt_rate.updated_notice', $locale)."\n\n"
            : '';
        if ($rate === null) {
            return $notice.$this->translation('telegram.navigation.admin.usdt_rate.unset', $locale);
        }

        $source = $this->translation('telegram.navigation.admin.usdt_rate.sources.'.$rate->source, $locale);
        $version = $rate->version === null
            ? $this->translation('telegram.navigation.admin.usdt_rate.not_managed', $locale)
            : (string) $rate->version;

        return $notice.$this->translation('telegram.navigation.admin.usdt_rate.view', $locale, [
            'rate' => $this->formatUsdtRateIrr($rate->rateIrr),
            'source' => $source,
            'version' => $version,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function pendingUsdtRateFromPayload(array $payload): string
    {
        if (count($payload) !== 1 || ! array_key_exists('rate_irr', $payload) || ! is_string($payload['rate_irr'])) {
            throw new RuntimeException('Telegram administrator USDT rate confirmation payload is invalid.');
        }

        $rate = $payload['rate_irr'];
        if (preg_match('/\A[0-9]+\.[0-9]{8}\z/', $rate) !== 1) {
            throw new RuntimeException('Telegram administrator USDT rate confirmation value is invalid.');
        }

        return $rate;
    }

    private function normalizeUsdtRateInput(string $input): ?string
    {
        $normalized = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.',
        ]);
        if (strlen($normalized) > 32
            || preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,8})?\z/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function formatUsdtRateIrr(string $rate): string
    {
        if (preg_match('/\A([0-9]+)(?:\.([0-9]{1,8}))?\z/', $rate, $matches) !== 1) {
            throw new RuntimeException('Telegram USDT rate presentation value is invalid.');
        }
        $fraction = rtrim($matches[2] ?? '', '0');
        $formatted = number_format((int) $matches[1], 0, '.', ',');

        return $fraction === '' ? $formatted : $formatted.'.'.$fraction;
    }

    private function usdtRateRequestKey(TelegramInteractionAction $action): string
    {
        return 'telegram-usdt-rate:'.$this->callbackPublicId($action);
    }

    private function usdtRateCorrelationId(TelegramInteractionAction $action): string
    {
        return 'tg-usdt-rate:'.$this->callbackPublicId($action);
    }

    private function purchaseQuoteRequestKey(TelegramInteractionAction $action): string
    {
        return 'telegram-purchase-quote:'.$this->callbackPublicId($action);
    }

    private function purchaseQuoteCorrelationId(TelegramInteractionAction $action): string
    {
        return 'tg-purchase-quote:'.$this->callbackPublicId($action);
    }

    private function purchasePaymentMethodsDecisionKey(TelegramInteractionAction $action): string
    {
        return 'telegram-purchase-payment-methods:'.$this->callbackPublicId($action);
    }

    private function showPurchaseCatalog(TelegramInteractionAction $action, int $page): void
    {
        $locale = $this->localeForActor($action->userId);
        $catalog = $this->purchaseCatalog->pageForSelf(
            $action->userId,
            $action->userId,
            $page,
            self::PURCHASE_PAGE_SIZE,
        );
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_CATALOG,
            ['page' => $catalog->page],
            'nav-purchase-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $this->renderPurchaseCatalog($action, $session->version, $catalog, $locale, $action->requestKey);
    }

    private function showPurchaseOffering(TelegramInteractionAction $action, string $selectionToken): void
    {
        $page = $this->purchasePageFromPayload($action->sessionPayload);
        $offering = $this->purchaseCatalog->offeringForSelf($action->userId, $action->userId, $selectionToken);
        $locale = $this->localeForActor($action->userId);
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_OFFERING,
            ['page' => $page, 'offering_selection' => $selectionToken],
            'nav-purchase-offering-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseOffering($action, $session->version, $offering, $locale, $action->requestKey);
    }

    private function showPurchaseQuote(TelegramInteractionAction $action): void
    {
        $state = $this->purchaseOfferingStateFromPayload($action->sessionPayload);
        if ($action->callbackAcceptedAt === null) {
            throw new RuntimeException('Telegram purchase Quote callback acceptance time is unavailable.');
        }

        try {
            [$session, $preview] = $this->database->connection()->transaction(function () use ($action, $state): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PURCHASE_QUOTE_SUBMITTING,
                    [
                        'page' => $state['page'],
                        'offering_selection' => $state['offering_selection'],
                    ],
                    'nav-purchase-quote-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $claim->userId);

                $preview = $this->purchaseQuotes->quoteForSelf(
                    $action->userId,
                    $action->userId,
                    $state['offering_selection'],
                    $action->callbackAcceptedAt,
                    $this->purchaseQuoteRequestKey($action),
                    $this->purchaseQuoteCorrelationId($action),
                );

                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_PURCHASE_QUOTE,
                    [
                        'page' => $state['page'],
                        'offering_selection' => $state['offering_selection'],
                        'quote_public_id' => $preview->quotePublicId,
                        'quote_configuration_hash' => $preview->configurationSnapshotHash,
                    ],
                    'nav-purchase-quote-transition:'.$action->requestKey,
                );
                $this->assertActorBinding($action, $session->userId);

                return [$session, $preview];
            }, 3);
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseCatalog($action);
            } catch (AuthorizationException|\DomainException) {
                // Another accepted interaction already moved this purchase session. Fail closed.
            }

            return;
        } catch (\DomainException) {
            // The accepted Quote action lost the session-version race before its durable Quote could commit.
            return;
        }

        $quoteState = [
            'page' => $state['page'],
            'offering_selection' => $state['offering_selection'],
            'quote_public_id' => $preview->quotePublicId,
            'quote_configuration_hash' => $preview->configurationSnapshotHash,
        ];
        $this->renderPurchaseQuote(
            $action,
            $session->version,
            $preview,
            $quoteState,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function renderPurchaseQuote(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerPurchaseQuotePreview $preview,
        array $state,
        string $locale,
        string $requestKey,
    ): void {
        $rows = [];
        if (! $this->purchaseQuoteHasDiscount($state)) {
            $discount = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_DISCOUNT,
                [],
                'nav-purchase-quote-discount:'.$requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.discount.button', $locale),
                $discount->publicId,
            )];
        }
        $paymentMethods = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_PURCHASE_PAYMENT_METHODS,
            [],
            'nav-purchase-quote-payment-methods:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.purchase.payment_methods_button', $locale),
            $paymentMethods->publicId,
            TelegramInlineButtonStyle::Primary,
        )];
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-quote-back:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queueConfidential(
            $action,
            $this->purchaseQuoteText($preview, $locale),
            'nav-purchase-quote-delivery:'.$requestKey,
            $this->purchaseQuoteHasDiscount($state) ? 'purchase-quote-discounted' : 'purchase-quote',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function showPurchaseDiscountInput(TelegramInteractionAction $action, array $state): void
    {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_DISCOUNT_INPUT,
            $state,
            'nav-purchase-discount-input-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseDiscountPrompt(
            $action,
            $session->version,
            $this->localeForActor($action->userId),
            $action->requestKey,
            false,
        );
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function showPurchaseDiscountQuote(TelegramInteractionAction $action, array $state): void
    {
        if ($action->messageAcceptedAt === null) {
            throw new RuntimeException('Telegram purchase discount input acceptance time is unavailable.');
        }
        $code = $action->messageText === null ? '' : trim($action->messageText);
        if ($code === '' || strlen($code) > 256) {
            $this->renderPurchaseDiscountPrompt(
                $action,
                $action->sessionVersion,
                $this->localeForActor($action->userId),
                $action->requestKey,
                true,
            );

            return;
        }
        $operationKey = hash('sha256', $action->requestKey);

        try {
            [$session, $preview] = $this->database->connection()->transaction(function () use (
                $action,
                $state,
                $code,
                $operationKey,
            ): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PURCHASE_DISCOUNT_SUBMITTING,
                    $state,
                    'nav-purchase-discount-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $claim->userId);

                $preview = $this->purchaseDiscountQuotes->requoteForSelf(
                    $action->userId,
                    $action->userId,
                    $state['offering_selection'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $code,
                    $action->messageAcceptedAt,
                    $operationKey,
                );
                $quoteState = [
                    'page' => $state['page'],
                    'offering_selection' => $state['offering_selection'],
                    'quote_public_id' => $preview->quote->quotePublicId,
                    'quote_configuration_hash' => $preview->quote->configurationSnapshotHash,
                    'discount_consumption_public_id' => $preview->discountConsumptionPublicId,
                    'discount_consumption_configuration_hash' => $preview->discountConsumptionConfigurationHash,
                    'promotion_resolution_public_id' => $preview->promotionResolutionPublicId,
                ];
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_PURCHASE_QUOTE,
                    $quoteState,
                    'nav-purchase-discount-transition:'.$action->requestKey,
                );
                $this->assertActorBinding($action, $session->userId);

                return [$session, $preview];
            }, 3);
        } catch (TelegramCustomerPurchaseQuoteRefreshRequired) {
            try {
                $this->returnPurchaseCatalogFromQuote($action, $state['page']);
            } catch (AuthorizationException|\DomainException) {
                // Another accepted interaction already moved the input session. Fail closed without leaking the code.
            }

            return;
        } catch (AuthorizationException|\DomainException|\InvalidArgumentException) {
            try {
                $this->renderPurchaseDiscountPrompt(
                    $action,
                    $action->sessionVersion,
                    $this->localeForActor($action->userId),
                    $action->requestKey,
                    true,
                );
            } catch (\DomainException) {
                // Another accepted interaction already moved the input session. Fail closed without leaking the code.
            }

            return;
        }

        $quoteState = [
            'page' => $state['page'],
            'offering_selection' => $state['offering_selection'],
            'quote_public_id' => $preview->quote->quotePublicId,
            'quote_configuration_hash' => $preview->quote->configurationSnapshotHash,
            'discount_consumption_public_id' => $preview->discountConsumptionPublicId,
            'discount_consumption_configuration_hash' => $preview->discountConsumptionConfigurationHash,
            'promotion_resolution_public_id' => $preview->promotionResolutionPublicId,
        ];
        $this->renderPurchaseQuote(
            $action,
            $session->version,
            $preview->quote,
            $quoteState,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function renderPurchaseDiscountPrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $requestKey,
        bool $rejected,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-discount-back:'.$requestKey,
        );
        $this->queueConfidential(
            $action,
            $this->translation(
                $rejected ? 'telegram.navigation.purchase.discount.rejected' : 'telegram.navigation.purchase.discount.prompt',
                $locale,
            ),
            'nav-purchase-discount-delivery:'.$requestKey,
            $rejected ? 'purchase-discount-rejected' : 'purchase-discount-input',
            new TelegramInlineKeyboardSnapshot([[new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )]]),
        );
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function returnPurchaseQuoteFromDiscountInput(TelegramInteractionAction $action, array $state): void
    {
        try {
            $preview = $this->purchaseQuotes->previewForSelf(
                $action->userId,
                $action->userId,
                $state['offering_selection'],
                $state['quote_public_id'],
                $state['quote_configuration_hash'],
            );
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseCatalogFromQuote($action, $state['page']);
            } catch (AuthorizationException|\DomainException) {
                // Source Quote or actor changed while returning from discount input. Fail closed.
            }

            return;
        }
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_QUOTE,
            $state,
            'nav-purchase-discount-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseQuote(
            $action,
            $session->version,
            $preview,
            $state,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function showPurchasePaymentMethods(TelegramInteractionAction $action): void
    {
        $state = $this->purchaseQuoteStateFromPayload($action->sessionPayload);
        try {
            [$session, $decision] = $this->database->connection()->transaction(function () use ($action, $state): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PURCHASE_PAYMENT_METHODS_SUBMITTING,
                    $state,
                    'nav-purchase-payment-methods-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $claim->userId);

                $decision = $this->purchasePaymentMethods->discoverForSelf(
                    $action->userId,
                    $action->userId,
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $this->purchasePaymentMethodsDecisionKey($action),
                );

                $paymentState = $state;
                $paymentState['payment_decision_public_id'] = $decision->decisionPublicId;
                $paymentState['payment_decision_configuration_hash'] = $decision->configurationSnapshotHash;
                if ($decision->methodCodes !== []) {
                    $order = $this->purchaseOrders->openForSelf(
                        $action->userId,
                        $action->userId,
                        $state['quote_public_id'],
                        $state['quote_configuration_hash'],
                        'telegram-order:'.$this->callbackPublicId($action),
                    );
                    $paymentState['order_public_id'] = $order->orderPublicId;
                }
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_PURCHASE_PAYMENT_METHODS,
                    $paymentState,
                    'nav-purchase-payment-methods-transition:'.$action->requestKey,
                );
                $this->assertActorBinding($action, $session->userId);

                return [$session, $decision];
            }, 3);
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseOffering($action);
            } catch (AuthorizationException|\DomainException) {
                // The Quote or actor became unavailable while entering payment-method discovery. Fail closed.
            }

            return;
        } catch (\DomainException) {
            // Another accepted interaction already moved this Quote session before PAY-001 decision persistence.
            return;
        }

        $this->renderPurchasePaymentMethods(
            $action,
            $session->version,
            $decision,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function renderPurchasePaymentMethods(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerPurchasePaymentMethodsDecision $decision,
        string $locale,
        string $requestKey,
    ): void {
        $rows = [];
        foreach ($decision->methodCodes as $offset => $methodCode) {
            $selection = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_PAYMENT_METHOD_SELECT,
                ['method_code' => $methodCode],
                'nav-purchase-payment-method-select:'.hash('sha256', $requestKey.':'.$methodCode),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->purchasePaymentMethodLabel($methodCode, $locale, $offset + 1),
                $selection->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-payment-methods-back:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queueConfidential(
            $action,
            $this->purchasePaymentMethodsText($decision, $locale),
            'nav-purchase-payment-methods-delivery:'.$requestKey,
            $decision->methodCodes === [] ? 'purchase-payment-methods-empty' : 'purchase-payment-methods',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function selectPurchasePaymentMethod(TelegramInteractionAction $action, string $methodCode): void
    {
        $state = $this->purchasePaymentMethodsStateFromPayload($action->sessionPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram purchase payment method cannot be selected without a pre-payment Order.');
        }

        try {
            [$session, $selection] = $this->database->connection()->transaction(function () use ($action, $state, $methodCode): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PURCHASE_PAYMENT_METHOD_SELECTING,
                    $state,
                    'nav-purchase-payment-method-select-claim:'.hash('sha256', $action->requestKey),
                );
                $this->assertActorBinding($action, $claim->userId);

                $this->purchaseOrders->currentForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                );
                $selection = $this->purchasePaymentMethods->selectForSelf(
                    $action->userId,
                    $action->userId,
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $methodCode,
                );

                $selectedState = $state;
                $selectedState['payment_method_code'] = $selection->methodCode;
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_PURCHASE_PAYMENT_METHOD_SELECTED,
                    $selectedState,
                    'nav-purchase-payment-method-selected:'.$action->requestKey,
                );
                $this->assertActorBinding($action, $session->userId);

                return [$session, $selection];
            }, 3);
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseOfferingFromPaymentMethods($action);
            } catch (AuthorizationException|\DomainException) {
                // Quote, Order, decision or method changed while selecting. Fail closed.
            }

            return;
        } catch (\DomainException) {
            // Another accepted interaction won the same payment-method session version.
            return;
        }

        $this->renderPurchasePaymentMethodSelected(
            $action,
            $session->version,
            $selection->methodCode,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function renderPurchasePaymentMethodSelected(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $methodCode,
        string $locale,
        string $requestKey,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-payment-method-selected-back:'.$requestKey,
        );
        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.purchase.payment_methods.selected', $locale, [
                'method' => $this->purchaseSelectedPaymentMethodLabel($methodCode, $locale),
            ]),
            'nav-purchase-payment-method-selected-delivery:'.$requestKey,
            'purchase-payment-method-selected',
            new TelegramInlineKeyboardSnapshot([[new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )]]),
        );
    }

    private function renderPurchaseOffering(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerPurchaseOffering $offering,
        string $locale,
        string $requestKey,
    ): void {
        $quote = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_PURCHASE_QUOTE,
            [],
            'nav-purchase-offering-quote:'.$requestKey,
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-offering-back:'.$requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.quote_button', $locale),
                $quote->publicId,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);

        $this->queueConfidential(
            $action,
            $this->purchaseOfferingText($offering, $locale),
            'nav-purchase-offering-delivery:'.$requestKey,
            'purchase-offering',
            $keyboard,
        );
    }

    private function showMyAccount(TelegramInteractionAction $action): void
    {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_MY_ACCOUNT,
            [],
            'nav-account-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $customer = $this->customers->forSelf($action->userId, $action->userId);
        $wallet = $this->wallets->forSelf($action->userId, $action->userId);
        $referral = $this->referrals->forSelf($action->userId, $action->userId);
        $locale = $customer->locale === 'en' ? 'en' : 'fa';

        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'nav-account-back:'.$action->requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            ),
        ]]);

        $this->queueConfidential(
            $action,
            $this->accountText($customer, $wallet, $referral, $locale),
            'nav-account-delivery:'.$action->requestKey,
            'account',
            $keyboard,
        );
    }

    private function showMyServices(TelegramInteractionAction $action, int $page): void
    {
        $locale = $this->localeForActor($action->userId);
        $services = $this->services->pageForSelf(
            $action->userId,
            $action->userId,
            $page,
            self::SERVICE_PAGE_SIZE,
        );
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_MY_SERVICES,
            ['page' => $services->page],
            'nav-services-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $this->renderMyServices($action, $session->version, $services, $locale, $action->requestKey);
    }

    private function showServiceSearch(TelegramInteractionAction $action): void
    {
        $page = $this->pageFromPayload($action->sessionPayload);
        $locale = $this->localeForActor($action->userId);
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_SERVICE_SEARCH,
            ['page' => $page],
            'nav-service-search-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderServiceSearchMessage($action, $session->version, $locale, 'prompt');
    }

    private function searchService(TelegramInteractionAction $action, string $searchTerm): void
    {
        $page = $this->pageFromPayload($action->sessionPayload);
        $result = $this->services->searchForSelf($action->userId, $action->userId, $searchTerm);
        $locale = $this->localeForActor($action->userId);
        if ($result->status === TelegramOwnedServiceSearchResult::MATCHED) {
            if ($result->selectionToken === null) {
                throw new RuntimeException('Telegram Service search match is incomplete.');
            }
            $detail = $this->services->detailForSelf($action->userId, $action->userId, $result->selectionToken);
            $this->transitionToServiceDetail($action, $detail, $result->selectionToken, $page, $locale);

            return;
        }

        $surface = $result->status === TelegramOwnedServiceSearchResult::AMBIGUOUS ? 'ambiguous' : 'not_found';
        $this->renderServiceSearchMessage($action, $action->sessionVersion, $locale, $surface);
    }

    private function showServiceDetail(TelegramInteractionAction $action, string $selectionToken): void
    {
        $page = $this->pageFromPayload($action->sessionPayload);
        $detail = $this->services->detailForSelf($action->userId, $action->userId, $selectionToken);
        $locale = $this->localeForActor($action->userId);
        $this->transitionToServiceDetail($action, $detail, $selectionToken, $page, $locale);
    }

    private function transitionToServiceDetail(
        TelegramInteractionAction $action,
        TelegramOwnedServiceDetail $detail,
        string $selectionToken,
        int $page,
        string $locale,
    ): void {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_SERVICE_DETAIL,
            ['page' => $page],
            'nav-service-detail-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $rows = [];
        if ($this->canOfferServiceResend($detail)) {
            $resend = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_SERVICE_RESEND,
                ['service_selection' => $selectionToken],
                'nav-service-detail-resend:'.$action->requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.resend.button', $locale),
                $resend->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'nav-service-detail-back:'.$action->requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];
        $keyboard = new TelegramInlineKeyboardSnapshot($rows);

        $this->queueConfidential(
            $action,
            $this->serviceDetailText($detail, $locale),
            'nav-service-detail-delivery:'.$action->requestKey,
            'service-detail',
            $keyboard,
        );
    }

    private function resendServiceDelivery(TelegramInteractionAction $action, string $selectionToken): void
    {
        $locale = $this->localeForActor($action->userId);

        try {
            $detail = $this->services->detailForSelf($action->userId, $action->userId, $selectionToken);
        } catch (AuthorizationException) {
            $this->renderServiceResendResult($action, TelegramOwnedServiceDeliveryResendStatus::Unavailable, $locale);

            return;
        }

        $callbackPublicId = $this->callbackPublicId($action);
        $status = $this->serviceDeliveryResender->resendForSelf(
            $action->userId,
            $detail->publicId,
            'telegram-service-resend:'.$callbackPublicId,
            'telegram-resend:'.$callbackPublicId,
        );

        $this->renderServiceResendResult($action, $status, $locale);
    }

    private function renderServiceResendResult(
        TelegramInteractionAction $action,
        TelegramOwnedServiceDeliveryResendStatus $status,
        string $locale,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-service-resend-back:'.$action->requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            ),
        ]]);

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.services.resend.'.$status->value, $locale),
            'nav-service-resend-delivery:'.$action->requestKey,
            'service-resend-'.$status->value,
            $keyboard,
        );
    }

    private function canOfferServiceResend(TelegramOwnedServiceDetail $detail): bool
    {
        return $detail->provisionedAt !== null
            && in_array($detail->lifecycleState, ['active', 'suspended'], true);
    }

    /** @param array<string, mixed> $payload */
    private function serviceSelectionToken(array $payload): string
    {
        if (array_keys($payload) !== ['service_selection']
            || ! is_string($payload['service_selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['service_selection']) !== 1) {
            throw new RuntimeException('Telegram Service resend selection is invalid.');
        }

        return $payload['service_selection'];
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram callback identity is invalid.');
        }

        return $action->callbackPublicId;
    }

    private function renderServiceSearchMessage(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $surface,
    ): void {
        if (! in_array($surface, ['prompt', 'not_found', 'ambiguous'], true)) {
            throw new RuntimeException('Telegram Service search presentation surface is invalid.');
        }
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-service-search-back:'.$surface.':'.$action->requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            ),
        ]]);
        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.services.search.'.$surface, $locale),
            'nav-service-search-delivery:'.$surface.':'.$action->requestKey,
            'service-search-'.$surface,
            $keyboard,
        );
    }

    private function returnPurchaseOffering(TelegramInteractionAction $action): void
    {
        $state = $this->purchaseQuoteStateFromPayload($action->sessionPayload);
        try {
            $offering = $this->purchaseCatalog->offeringForSelf(
                $action->userId,
                $action->userId,
                $state['offering_selection'],
            );
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseCatalogFromQuote($action, $state['page']);
            } catch (AuthorizationException|\DomainException) {
                // The actor or session changed while returning from a stale Quote. Fail closed without retrying the update.
            }

            return;
        }
        $locale = $this->localeForActor($action->userId);
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_OFFERING,
            ['page' => $state['page'], 'offering_selection' => $state['offering_selection']],
            'nav-purchase-quote-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseOffering($action, $session->version, $offering, $locale, $action->requestKey);
    }

    private function returnPurchasePaymentMethodsFromSelection(TelegramInteractionAction $action): void
    {
        $state = $this->purchasePaymentMethodSelectedStateFromPayload($action->sessionPayload);
        try {
            $this->purchaseOrders->currentForSelf(
                $action->userId,
                $action->userId,
                $state['order_public_id'],
                $state['quote_public_id'],
                $state['quote_configuration_hash'],
            );
            $decision = $this->purchasePaymentMethods->currentForSelf(
                $action->userId,
                $action->userId,
                $state['quote_public_id'],
                $state['quote_configuration_hash'],
                $state['payment_decision_public_id'],
                $state['payment_decision_configuration_hash'],
            );
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseCatalogFromQuote($action, $state['page']);
            } catch (AuthorizationException|\DomainException) {
                // Commercial authority changed while returning from the selected method. Fail closed.
            }

            return;
        }
        unset($state['payment_method_code']);
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_PAYMENT_METHODS,
            $state,
            'nav-purchase-payment-method-selected-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchasePaymentMethods(
            $action,
            $session->version,
            $decision,
            $this->localeForActor($action->userId),
            $action->requestKey,
        );
    }

    private function returnPurchaseOfferingFromPaymentMethods(TelegramInteractionAction $action): void
    {
        $state = $this->purchasePaymentMethodsStateFromPayload($action->sessionPayload);
        try {
            $offering = $this->purchaseCatalog->offeringForSelf(
                $action->userId,
                $action->userId,
                $state['offering_selection'],
            );
        } catch (AuthorizationException) {
            try {
                $this->returnPurchaseCatalogFromQuote($action, $state['page']);
            } catch (AuthorizationException|\DomainException) {
                // The actor or Offering changed while returning from payment-method discovery. Fail closed.
            }

            return;
        }

        $locale = $this->localeForActor($action->userId);
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_OFFERING,
            ['page' => $state['page'], 'offering_selection' => $state['offering_selection']],
            'nav-purchase-payment-methods-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseOffering($action, $session->version, $offering, $locale, $action->requestKey);
    }

    private function returnPurchaseCatalogFromQuote(TelegramInteractionAction $action, int $page): void
    {
        $locale = $this->localeForActor($action->userId);
        $catalog = $this->purchaseCatalog->pageForSelf(
            $action->userId,
            $action->userId,
            $page,
            self::PURCHASE_PAGE_SIZE,
        );
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_CATALOG,
            ['page' => $catalog->page],
            'nav-purchase-quote-stale-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);
        $this->renderPurchaseCatalog($action, $session->version, $catalog, $locale, $action->requestKey);
    }

    private function returnPurchaseCatalog(TelegramInteractionAction $action): void
    {
        $page = $this->purchasePageFromPayload($action->sessionPayload);
        $locale = $this->localeForActor($action->userId);
        $catalog = $this->purchaseCatalog->pageForSelf(
            $action->userId,
            $action->userId,
            $page,
            self::PURCHASE_PAGE_SIZE,
        );
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_PURCHASE_CATALOG,
            ['page' => $catalog->page],
            'nav-purchase-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $this->renderPurchaseCatalog($action, $session->version, $catalog, $locale, $action->requestKey);
    }

    private function returnMyServices(TelegramInteractionAction $action): void
    {
        $page = $this->pageFromPayload($action->sessionPayload);
        $locale = $this->localeForActor($action->userId);
        $services = $this->services->pageForSelf(
            $action->userId,
            $action->userId,
            $page,
            self::SERVICE_PAGE_SIZE,
        );
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            self::STATE_MY_SERVICES,
            ['page' => $services->page],
            'nav-services-back-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $this->renderMyServices($action, $session->version, $services, $locale, $action->requestKey);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $action->sessionVersion,
            TelegramNavigationEntryGateway::STATE,
            [],
            'nav-home-transition:'.$action->requestKey,
        );
        $this->assertActorBinding($action, $session->userId);

        $this->renderHome($action, $session->version, $action->requestKey);
    }

    private function renderHome(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $requestKey,
    ): void {
        $customer = $this->customers->forSelf($action->userId, $action->userId);
        $locale = $customer->locale === 'en' ? 'en' : 'fa';
        $account = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_MY_ACCOUNT,
            [],
            'nav-home-account:'.$requestKey,
        );
        $services = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_MY_SERVICES,
            [],
            'nav-home-services:'.$requestKey,
        );
        $rows = [
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.my_account', $locale),
                $account->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
        ];
        if ($customer->accountType === 'customer' && $customer->accountStatus === 'active') {
            $purchase = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_CATALOG,
                [],
                'nav-home-purchase:'.$requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.buy_service', $locale),
                $purchase->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.my_services', $locale),
            $services->publicId,
            TelegramInlineButtonStyle::Primary,
        )];
        if ($this->managedUsdtRateSettings->availableFor($action->userId)) {
            $admin = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_ADMIN_CONTROL,
                [],
                'nav-home-admin:'.$requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.admin', $locale),
                $admin->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }
        $keyboard = new TelegramInlineKeyboardSnapshot($rows);
        $text = $this->translation('telegram.navigation.home', $locale);
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->presentations->fromSource($source);

        $this->delivery->queue(
            TelegramDeliveryAction::Send,
            $action->telegramUserId,
            null,
            $presentation,
            'nav-home-delivery:'.$requestKey,
            $this->correlationId($action, 'home'),
            $keyboard,
        );
    }

    private function renderPurchaseCatalog(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerPurchaseCatalogPage $catalog,
        string $locale,
        string $requestKey,
    ): void {
        $rows = [];
        foreach ($catalog->items as $offset => $offering) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_OFFERING_PREFIX.$offering->selectionToken,
                [],
                'nav-purchase-item:'.$requestKey.':'.($offset + 1),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.offering_button', $locale, [
                    'number' => ($catalog->page - 1) * self::PURCHASE_PAGE_SIZE + $offset + 1,
                ]),
                $callback->publicId,
            )];
        }

        $pagination = [];
        if ($catalog->page > 1) {
            $previous = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_PAGE,
                ['page' => $catalog->page - 1],
                'nav-purchase-previous:'.$requestKey,
            );
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.previous', $locale),
                $previous->publicId,
            );
        }
        if ($catalog->page < $catalog->totalPages) {
            $next = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PURCHASE_PAGE,
                ['page' => $catalog->page + 1],
                'nav-purchase-next:'.$requestKey,
            );
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.next', $locale),
                $next->publicId,
            );
        }
        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-purchase-back:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queueConfidential(
            $action,
            $this->purchaseCatalogText($catalog, $locale),
            'nav-purchase-delivery:'.$requestKey,
            'purchase-catalog',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderMyServices(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramOwnedServicePage $services,
        string $locale,
        string $requestKey,
    ): void {
        $rows = [];
        foreach ($services->items as $offset => $service) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SERVICE_DETAIL_PREFIX.$service->selectionToken,
                [],
                'nav-services-item:'.$requestKey.':'.($offset + 1),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.service_button', $locale, [
                    'number' => ($services->page - 1) * self::SERVICE_PAGE_SIZE + $offset + 1,
                ]),
                $callback->publicId,
            )];
        }

        $pagination = [];
        if ($services->page > 1) {
            $previous = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SERVICES_PAGE,
                ['page' => $services->page - 1],
                'nav-services-previous:'.$requestKey,
            );
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.previous', $locale),
                $previous->publicId,
            );
        }
        if ($services->page < $services->totalPages) {
            $next = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_SERVICES_PAGE,
                ['page' => $services->page + 1],
                'nav-services-next:'.$requestKey,
            );
            $pagination[] = new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.next', $locale),
                $next->publicId,
            );
        }
        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $search = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_SERVICES_SEARCH,
            [],
            'nav-services-search:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.services.search.button', $locale),
            $search->publicId,
            TelegramInlineButtonStyle::Primary,
        )];

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'nav-services-back:'.$requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queueConfidential(
            $action,
            $this->serviceListText($services, $locale),
            'nav-services-delivery:'.$requestKey,
            'services',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function purchaseCatalogText(TelegramCustomerPurchaseCatalogPage $catalog, string $locale): string
    {
        if ($catalog->items === []) {
            return $this->translation('telegram.navigation.purchase.empty', $locale);
        }

        $lines = [];
        foreach ($catalog->items as $offset => $offering) {
            $lines[] = $this->translation('telegram.navigation.purchase.list_item', $locale, [
                'number' => ($catalog->page - 1) * self::PURCHASE_PAGE_SIZE + $offset + 1,
                'category' => $this->localizedLabel($offering->categoryNameFa, $offering->categoryNameEn, $locale),
                'plan' => $this->purchasePlanLabel($offering, $locale),
                'mode' => $this->localizedLabel($offering->serviceModeLabelFa, $offering->serviceModeLabelEn, $locale),
                'price' => $this->formatIrr($offering->basePriceIrr),
                'duration' => $offering->durationDays,
            ]);
        }

        return $this->translation('telegram.navigation.purchase.list', $locale, [
            'items' => implode("\n\n", $lines),
            'page' => $catalog->page,
            'total_pages' => $catalog->totalPages,
            'total_items' => $catalog->totalItems,
        ]);
    }

    private function purchaseOfferingText(TelegramCustomerPurchaseOffering $offering, string $locale): string
    {
        $notAvailable = $this->translation('telegram.navigation.purchase.not_available', $locale);

        return $this->translation('telegram.navigation.purchase.detail', $locale, [
            'category' => $this->localizedLabel($offering->categoryNameFa, $offering->categoryNameEn, $locale),
            'plan' => $this->purchasePlanLabel($offering, $locale),
            'mode' => $this->localizedLabel($offering->serviceModeLabelFa, $offering->serviceModeLabelEn, $locale),
            'price' => $this->formatIrr($offering->basePriceIrr),
            'duration' => $offering->durationDays,
            'data' => $this->formatBytes($offering->dataAllowanceBytes, $notAvailable),
            'devices' => $offering->deviceLimit === null ? $notAvailable : (string) $offering->deviceLimit,
        ]);
    }

    private function purchaseQuoteText(TelegramCustomerPurchaseQuotePreview $preview, string $locale): string
    {
        return $this->translation('telegram.navigation.purchase.quote', $locale, [
            'quote_id' => $preview->quotePublicId,
            'plan' => $this->purchasePlanLabel($preview->offering, $locale),
            'base_price' => $this->formatIrr($preview->basePriceIrr),
            'effective_price' => $this->formatIrr($preview->effectivePriceIrr),
            'discount' => $this->formatIrr($preview->discountIrr),
            'final_price' => $this->formatIrr($preview->finalPriceIrr),
            'currency' => $preview->currency,
            'expires_at' => $this->formatBusinessDateTime($preview->expiresAt),
        ]);
    }

    private function purchasePaymentMethodsText(
        TelegramCustomerPurchasePaymentMethodsDecision $decision,
        string $locale,
    ): string {
        if ($decision->methodCodes === []) {
            return $this->translation('telegram.navigation.purchase.payment_methods.empty', $locale);
        }

        $items = [];
        foreach ($decision->methodCodes as $offset => $methodCode) {
            $number = $offset + 1;
            $items[] = $this->translation('telegram.navigation.purchase.payment_methods.item', $locale, [
                'number' => $number,
                'method' => $this->purchasePaymentMethodLabel($methodCode, $locale, $number),
            ]);
        }

        return $this->translation('telegram.navigation.purchase.payment_methods.list', $locale, [
            'items' => implode("\n", $items),
        ]);
    }

    private function purchasePaymentMethodLabel(string $methodCode, string $locale, int $number): string
    {
        if (in_array($methodCode, ['wallet', 'card_to_card', 'gift_card', 'usdt_bep20', 'zarinpal', 'nowpayments'], true)) {
            return $this->translation('telegram.navigation.purchase.payment_methods.methods.'.$methodCode, $locale);
        }

        return $this->translation('telegram.navigation.purchase.payment_methods.methods.other', $locale, [
            'number' => $number,
        ]);
    }

    private function purchaseSelectedPaymentMethodLabel(string $methodCode, string $locale): string
    {
        $key = 'telegram.navigation.purchase.payment_methods.methods.'.$methodCode;
        $translated = $this->translation($key, $locale);
        if ($translated !== $key) {
            return $translated;
        }

        return $this->translation('telegram.navigation.purchase.payment_methods.methods.other_selected', $locale);
    }

    private function formatBusinessDateTime(DateTimeImmutable $value): string
    {
        $timezone = config('business.display_timezone');
        if (! is_string($timezone) || $timezone === '') {
            throw new RuntimeException('Telegram business display timezone is invalid.');
        }

        return $value->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
    }

    private function purchasePlanLabel(TelegramCustomerPurchaseOffering $offering, string $locale): string
    {
        $product = $this->localizedLabel($offering->productNameFa, $offering->productNameEn, $locale);
        $variant = $locale === 'en' ? $offering->variantNameEn : $offering->variantNameFa;
        if ($variant === null) {
            $variant = $offering->variantNameFa ?? $offering->variantNameEn;
        }

        return $variant === null ? $product : $product.' — '.$variant;
    }

    private function accountText(
        CustomerAccountSummary $customer,
        WalletSelfBalanceSummary $wallet,
        ReferralSelfSummary $referral,
        string $locale,
    ): string {
        $identityLines = [];
        foreach ($customer->identityItems as $item) {
            $identityLines[] = $this->translation('telegram.navigation.account.identity_item', $locale, [
                'type' => $this->localizedValue('identity_type', $item['type'], $locale),
                'masked' => $item['masked_value'],
                'state' => $this->localizedValue('verification', $item['state'], $locale),
            ]);
        }
        if ($identityLines === []) {
            $identityLines[] = $this->translation('telegram.navigation.account.identity_none', $locale);
        }

        return $this->translation('telegram.navigation.account.view', $locale, [
            'public_id' => $customer->publicId,
            'account_type' => $this->localizedValue('account_type', $customer->accountType, $locale),
            'account_status' => $this->localizedValue('account_status', $customer->accountStatus, $locale),
            'tier' => $customer->tierCode === null
                ? $this->translation('telegram.navigation.account.not_available', $locale)
                : $this->localizedValue('tier', $customer->tierCode, $locale),
            'phone_verification' => $this->localizedValue('verification', $customer->phoneVerificationStatus, $locale),
            'identity_verification' => $this->localizedValue('verification', $customer->identityVerificationStatus, $locale),
            'identity_items' => implode("\n", $identityLines),
            'joined_at' => $customer->joinedAt,
            'last_seen_at' => $customer->lastSeenAt ?? $this->translation('telegram.navigation.account.not_available', $locale),
            'cash_available' => $this->formatIrr($wallet->cashAvailableBalanceIrr),
            'cash_holds' => $this->formatIrr($wallet->cashActiveHoldsIrr),
            'promotional_available' => $this->formatIrr($wallet->promotionalAvailableBalanceIrr),
            'referral_token' => $referral->referralToken,
            'has_inviter' => $this->yesNo($referral->hasInviter, $locale),
            'referral_locked' => $this->yesNo($referral->locked, $locale),
        ]);
    }

    private function serviceListText(TelegramOwnedServicePage $services, string $locale): string
    {
        if ($services->items === []) {
            return $this->translation('telegram.navigation.services.empty', $locale);
        }

        $lines = [];
        foreach ($services->items as $offset => $service) {
            $lines[] = $this->translation('telegram.navigation.services.list_item', $locale, [
                'number' => ($services->page - 1) * self::SERVICE_PAGE_SIZE + $offset + 1,
                'public_id' => $service->publicId,
                'state' => $this->localizedServiceValue('lifecycle', $service->lifecycleState, $locale),
                'plan' => $this->localizedLabel($service->planNameFa, $service->planNameEn, $locale),
                'server' => $this->localizedLabel($service->serverNameFa, $service->serverNameEn, $locale),
            ]);
        }

        return $this->translation('telegram.navigation.services.list', $locale, [
            'items' => implode("\n\n", $lines),
            'page' => $services->page,
            'total_pages' => $services->totalPages,
            'total_items' => $services->totalItems,
        ]);
    }

    private function serviceDetailText(TelegramOwnedServiceDetail $service, string $locale): string
    {
        $notAvailable = $this->translation('telegram.navigation.services.not_available', $locale);
        $syncState = $this->localizedServiceValue('sync_state', $service->syncState, $locale);
        $remoteDisposition = $service->remoteDisposition === null
            ? $notAvailable
            : $this->localizedServiceValue('remote_disposition', $service->remoteDisposition, $locale);
        $remoteStatus = $service->remoteStatus === null
            ? $notAvailable
            : $this->localizedServiceValue('remote_status', $service->remoteStatus, $locale);
        $allowedActions = $service->allowedActions === []
            ? $this->translation('telegram.navigation.services.allowed_actions_none', $locale)
            : implode(
                $this->translation('telegram.navigation.services.allowed_actions_separator', $locale),
                array_map(
                    fn (TelegramOwnedServiceAction $action): string => $this->localizedServiceValue('action', $action->value, $locale),
                    $service->allowedActions,
                ),
            );

        return $this->translation('telegram.navigation.services.detail', $locale, [
            'public_id' => $service->publicId,
            'state' => $this->localizedServiceValue('lifecycle', $service->lifecycleState, $locale),
            'plan' => $this->localizedLabel($service->planNameFa, $service->planNameEn, $locale),
            'server' => $this->localizedLabel($service->serverNameFa, $service->serverNameEn, $locale),
            'provisioned_at' => $service->provisionedAt ?? $notAvailable,
            'allowed_actions' => $allowedActions,
            'sync_state' => $syncState,
            'remote_disposition' => $remoteDisposition,
            'remote_status' => $remoteStatus,
            'data_limit' => $this->formatBytes($service->dataLimitBytes, $notAvailable),
            'used' => $this->formatBytes($service->usedBytes, $notAvailable),
            'remaining' => $this->formatBytes($service->remainingBytes(), $notAvailable),
            'expires_at' => $service->expiresAt ?? $notAvailable,
            'observed_at' => $service->observedAt ?? $notAvailable,
        ]);
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $requestKey,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
    ): void {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $presentation = $this->confidentialPresentations->fromSource($source);

        $this->delivery->queueConfidential(
            TelegramDeliveryAction::Send,
            $action->telegramUserId,
            null,
            $presentation,
            $requestKey,
            $this->correlationId($action, $surface),
            $keyboard,
        );
    }

    /** @param array<string, int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->translator->get($key, $replace, $locale);
        if (! is_string($text) || $text === '' || $text === $key) {
            $text = $this->translator->get($key, $replace, 'en');
        }
        if (! is_string($text) || $text === '' || $text === $key) {
            throw new RuntimeException('Telegram navigation translation is unavailable.');
        }

        return $text;
    }

    private function localizedValue(string $group, string $value, string $locale): string
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $group) !== 1
            || preg_match('/\A[a-z0-9_]+\z/', $value) !== 1
        ) {
            throw new RuntimeException('Telegram navigation localized value is invalid.');
        }

        return $this->translation(
            'telegram.navigation.account.values.'.$group.'.'.$value,
            $locale,
        );
    }

    private function localizedServiceValue(string $group, string $value, string $locale): string
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $group) !== 1
            || preg_match('/\A[a-z0-9_]+\z/', $value) !== 1
        ) {
            throw new RuntimeException('Telegram Service localized value is invalid.');
        }

        return $this->translation('telegram.navigation.services.values.'.$group.'.'.$value, $locale);
    }

    private function localizedLabel(string $fa, ?string $en, string $locale): string
    {
        return $locale === 'en' && $en !== null ? $en : $fa;
    }

    private function localeForActor(int $userId): string
    {
        $customer = $this->customers->forSelf($userId, $userId);

        return $customer->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string, mixed> $payload */
    private function purchasePageFromPayload(array $payload): int
    {
        if (! is_int($payload['page'] ?? null) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram purchase page state is invalid.');
        }
        $allowedKeys = ['page'];
        if (array_key_exists('offering_selection', $payload)) {
            if (! is_string($payload['offering_selection'])
                || preg_match('/\A[0-9a-f]{40}\z/', $payload['offering_selection']) !== 1) {
                throw new RuntimeException('Telegram purchase offering state is invalid.');
            }
            $allowedKeys[] = 'offering_selection';
        }
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($allowedKeys, SORT_STRING);
        if ($keys !== $allowedKeys) {
            throw new RuntimeException('Telegram purchase state payload is invalid.');
        }

        return $payload['page'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{page:int,offering_selection:string}
     */
    private function purchaseOfferingStateFromPayload(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['offering_selection', 'page']
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! is_string($payload['offering_selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['offering_selection']) !== 1) {
            throw new RuntimeException('Telegram purchase offering state is invalid.');
        }

        return ['page' => $payload['page'], 'offering_selection' => $payload['offering_selection']];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function purchaseQuoteStateFromPayload(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $baseKeys = ['offering_selection', 'page', 'quote_configuration_hash', 'quote_public_id'];
        $discountedKeys = [
            'discount_consumption_configuration_hash',
            'discount_consumption_public_id',
            'offering_selection',
            'page',
            'promotion_resolution_public_id',
            'quote_configuration_hash',
            'quote_public_id',
        ];
        if (! in_array($keys, [$baseKeys, $discountedKeys], true)
            || ! is_int($payload['page'] ?? null)
            || $payload['page'] < 1
            || ! is_string($payload['offering_selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['offering_selection']) !== 1
            || ! is_string($payload['quote_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['quote_public_id']) !== 1
            || ! is_string($payload['quote_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['quote_configuration_hash']) !== 1) {
            throw new RuntimeException('Telegram purchase Quote state is invalid.');
        }
        if ($keys === $discountedKeys
            && (! is_string($payload['discount_consumption_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['discount_consumption_public_id']) !== 1
                || ! is_string($payload['discount_consumption_configuration_hash'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['discount_consumption_configuration_hash']) !== 1
                || ! is_string($payload['promotion_resolution_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['promotion_resolution_public_id']) !== 1)) {
            throw new RuntimeException('Telegram discounted Quote state is invalid.');
        }

        /** @var array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string} $payload */
        return $payload;
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function purchaseQuoteHasDiscount(array $state): bool
    {
        return isset(
            $state['discount_consumption_public_id'],
            $state['discount_consumption_configuration_hash'],
            $state['promotion_resolution_public_id'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id?:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function purchasePaymentMethodsStateFromPayload(array $payload): array
    {
        if (! is_string($payload['payment_decision_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_decision_public_id']) !== 1
            || ! is_string($payload['payment_decision_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['payment_decision_configuration_hash']) !== 1
            || (isset($payload['order_public_id'])
                && (! is_string($payload['order_public_id'])
                    || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['order_public_id']) !== 1))) {
            throw new RuntimeException('Telegram purchase payment-method state is invalid.');
        }
        $quotePayload = $payload;
        $decisionPublicId = $quotePayload['payment_decision_public_id'];
        $decisionConfigurationHash = $quotePayload['payment_decision_configuration_hash'];
        $orderPublicId = $quotePayload['order_public_id'] ?? null;
        unset($quotePayload['payment_decision_public_id'], $quotePayload['payment_decision_configuration_hash'], $quotePayload['order_public_id']);
        $state = $this->purchaseQuoteStateFromPayload($quotePayload);
        $state['payment_decision_public_id'] = $decisionPublicId;
        $state['payment_decision_configuration_hash'] = $decisionConfigurationHash;
        if (is_string($orderPublicId)) {
            $state['order_public_id'] = $orderPublicId;
        }

        /** @var array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id?:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string} $state */
        return $state;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function purchasePaymentMethodSelectedStateFromPayload(array $payload): array
    {
        if (! is_string($payload['payment_method_code'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $payload['payment_method_code']) !== 1) {
            throw new RuntimeException('Telegram selected payment-method state is invalid.');
        }
        $methodsPayload = $payload;
        $methodCode = $methodsPayload['payment_method_code'];
        unset($methodsPayload['payment_method_code']);
        $state = $this->purchasePaymentMethodsStateFromPayload($methodsPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram selected payment-method Order identity is unavailable.');
        }
        $state['payment_method_code'] = $methodCode;

        /** @var array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string} $state */
        return $state;
    }

    /** @param array<string, mixed> $payload */
    private function purchasePaymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code']
            || ! is_string($payload['method_code'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $payload['method_code']) !== 1) {
            throw new RuntimeException('Telegram payment-method callback payload is invalid.');
        }

        return $payload['method_code'];
    }

    /** @param array<string, mixed> $payload */
    private function pageFromPayload(array $payload): int
    {
        if (array_keys($payload) !== ['page'] || ! is_int($payload['page'] ?? null) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram My Services page state is invalid.');
        }

        return $payload['page'];
    }

    private function yesNo(bool $value, string $locale): string
    {
        return $this->translation(
            $value ? 'telegram.navigation.account.yes' : 'telegram.navigation.account.no',
            $locale,
        );
    }

    private function formatIrr(int $amount): string
    {
        if ($amount < 0) {
            throw new RuntimeException('Telegram account balance cannot be negative.');
        }

        return number_format($amount, 0, '.', ',');
    }

    private function formatBytes(?int $bytes, string $notAvailable): string
    {
        if ($bytes === null) {
            return $notAvailable;
        }
        if ($bytes < 0) {
            throw new RuntimeException('Telegram Service byte value cannot be negative.');
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, '.', '').' KiB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, '.', '').' MiB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2, '.', '').' GiB';
    }

    private function assertActorBinding(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram navigation session actor binding is invalid.');
        }
    }

    private function correlationId(TelegramInteractionAction $action, string $surface): string
    {
        return "telegram-nav:{$action->botId}:{$action->updateId}:{$surface}";
    }

    private function isEntryCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $trimmed = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $trimmed) === 1;
    }
}
