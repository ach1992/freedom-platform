<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Promotions\Application\ReferralSelfSummary;
use App\Modules\Promotions\Application\ReferralSelfSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramManagedUsdtRateSettings;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceDeliveryResender;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Modules\Wallet\Application\WalletSelfBalanceSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramNavigationHandler implements TelegramInteractionHandler
{
    private const STATE_MY_ACCOUNT = 'my_account';

    private const STATE_MY_SERVICES = 'my_services';

    private const STATE_SERVICE_DETAIL = 'service_detail';

    private const STATE_SERVICE_SEARCH = 'service_search';

    private const STATE_ADMIN_CONTROL = 'admin_control';

    private const STATE_ADMIN_USDT_RATE = 'admin_usdt_rate';

    private const STATE_ADMIN_USDT_RATE_EDIT = 'admin_usdt_rate_edit';

    private const STATE_ADMIN_USDT_RATE_SUBMITTING = 'admin_usdt_rate_submitting';

    private const ACTION_MY_ACCOUNT = 'navigation.my_account';

    private const ACTION_MY_SERVICES = 'navigation.my_services';

    private const ACTION_SERVICES_PAGE = 'navigation.services.page';

    private const ACTION_SERVICES_SEARCH = 'navigation.services.search';

    private const ACTION_SERVICE_DETAIL_PREFIX = 'navigation.service.';

    private const ACTION_SERVICE_RESEND = 'navigation.service.resend';

    private const ACTION_ADMIN_CONTROL = 'navigation.admin';

    private const ACTION_ADMIN_USDT_RATE = 'navigation.admin.usdt_rate';

    private const ACTION_ADMIN_USDT_RATE_EDIT = 'navigation.admin.usdt_rate.edit';

    private const ACTION_BACK = 'navigation.back';

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

        throw new RuntimeException('Telegram navigation session state is unsupported.');
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
            $this->setAdminUsdtRate($action, $action->messageText);
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

    private function setAdminUsdtRate(TelegramInteractionAction $action, string $input): void
    {
        if (! $this->managedUsdtRateSettings->availableFor($action->userId)) {
            try {
                $this->returnHome($action);
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        }

        $locale = $this->localeForActor($action->userId);
        $normalized = $this->normalizeUsdtRateInput($input);
        if ($normalized === null) {
            try {
                $this->renderAdminUsdtRateEdit($action, $action->sessionVersion, $locale, 'invalid');
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        }

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
                $this->renderAdminUsdtRateEdit($action, $action->sessionVersion, $locale, 'invalid');
            } catch (\DomainException) {
                // A concurrent interaction already moved this session. Fail closed.
            }

            return;
        } catch (\DomainException) {
            // The accepted message lost the session-version race before the financial mutation.
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
        return 'telegram-usdt-rate:'.hash('sha256', $action->requestKey);
    }

    private function usdtRateCorrelationId(TelegramInteractionAction $action): string
    {
        return 'tg-usdt-rate:'.substr(hash('sha256', $action->requestKey), 0, 40);
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
            throw new RuntimeException('Telegram Service resend callback identity is invalid.');
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
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.my_services', $locale),
                $services->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
        ];
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
