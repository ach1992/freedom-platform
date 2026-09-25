<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceReconfigurationManager;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** @requirement SVC-005 BUY-002 PAY-001 ACL-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class TelegramServiceReconfigurationNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.service.reconfigure';

    private const STATE_OFFERINGS = 'service_reconfigure_offerings';
    private const STATE_ROUTE = 'service_reconfigure_route';
    private const STATE_PROTOCOL = 'service_reconfigure_protocol';
    private const STATE_PREVIEW = 'service_reconfigure_preview';
    private const ACTION_OFFERING_PAGE = 'navigation.service.reconfigure.offering_page';
    private const ACTION_OFFERING_SELECT = 'navigation.service.reconfigure.offering';
    private const ACTION_ROUTE_SELECT = 'navigation.service.reconfigure.route';
    private const ACTION_ROUTE_AUTO = 'navigation.service.reconfigure.route_auto';
    private const ACTION_PROTOCOL_SELECT = 'navigation.service.reconfigure.protocol';
    private const ACTION_CONFIRM = 'navigation.service.reconfigure.confirm';
    private const ACTION_BACK = 'navigation.back';
    private const PAGE_SIZE = 6;

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramOwnedServiceProjection $services,
        private TelegramCustomerPurchaseCatalog $catalog,
        private TelegramOwnedServiceReconfigurationManager $reconfiguration,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
        private Clock $clock,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'service_detail'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [
                self::STATE_OFFERINGS,
                self::STATE_ROUTE,
                self::STATE_PROTOCOL,
                self::STATE_PREVIEW,
            ], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'service_detail') {
            if (array_keys($action->callbackPayload) !== ['service_selection']) {
                throw new RuntimeException('Telegram Service reconfiguration entry payload is invalid.');
            }
            $selection = $this->selection($action->callbackPayload['service_selection'] ?? null);
            try {
                $detail = $this->currentDetail($action, $selection);
            } catch (AuthorizationException|DomainException) {
                return;
            }
            if (! in_array(TelegramOwnedServiceAction::Reconfigure, $detail->allowedActions, true)) {
                $this->navigation->showOwnedServiceDetailForPage($action, $selection, $this->page($action->sessionPayload));
                return;
            }
            $this->showOfferings($action, $selection, $this->page($action->sessionPayload), 1);
            return;
        }

        if ($this->isBack($action)) {
            [$servicePage, $selection] = $this->serviceReturnState($action->sessionPayload);
            $this->navigation->showOwnedServiceDetailForPage($action, $selection, $servicePage);
            return;
        }

        match ($action->sessionState) {
            self::STATE_OFFERINGS => $this->handleOfferings($action),
            self::STATE_ROUTE => $this->handleRoute($action),
            self::STATE_PROTOCOL => $this->handleProtocol($action),
            self::STATE_PREVIEW => $this->handlePreview($action),
            default => throw new RuntimeException('Telegram Service reconfiguration state is unsupported.'),
        };
    }

    private function handleOfferings(TelegramInteractionAction $action): void
    {
        [$servicePage, $selection, $offeringPage] = $this->offeringsState($action->sessionPayload);
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }
        if ($action->callbackAction === self::ACTION_OFFERING_PAGE) {
            if (array_keys($action->callbackPayload) !== ['page']) {
                throw new RuntimeException('Telegram Service reconfiguration Offering page payload is invalid.');
            }
            $page = filter_var($action->callbackPayload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($page === false) {
                throw new RuntimeException('Telegram Service reconfiguration Offering page is invalid.');
            }
            $this->showOfferings($action, $selection, $servicePage, (int) $page);
            return;
        }
        if ($action->callbackAction !== self::ACTION_OFFERING_SELECT
            || array_keys($action->callbackPayload) !== ['offering_selection']) {
            throw new RuntimeException('Telegram Service reconfiguration Offering action is unsupported.');
        }

        $offeringSelection = $this->selection($action->callbackPayload['offering_selection'] ?? null);
        try {
            $options = $this->reconfiguration->optionsForSelf(
                $action->userId,
                $action->userId,
                $this->currentDetail($action, $selection)->publicId,
                $offeringSelection,
                null,
            );
        } catch (AuthorizationException|DomainException) {
            $this->showOfferings($action, $selection, $servicePage, $offeringPage, true);
            return;
        }

        if (in_array($options->serverSelectionMode, ['customer_selects', 'hybrid'], true)) {
            $this->showRoutes($action, $selection, $servicePage, $offeringPage, $offeringSelection, $options);
            return;
        }
        if ($options->protocolSelectionMode === 'customer_selects') {
            $this->showProtocols($action, $selection, $servicePage, $offeringPage, $offeringSelection, null, $options);
            return;
        }
        $this->createPreview($action, $selection, $servicePage, $offeringPage, $offeringSelection, null, null);
    }

    private function handleRoute(TelegramInteractionAction $action): void
    {
        [$servicePage, $selection, $offeringPage, $offeringSelection] = $this->routeState($action->sessionPayload);
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }

        $routeSelection = null;
        if ($action->callbackAction === self::ACTION_ROUTE_SELECT) {
            if (array_keys($action->callbackPayload) !== ['route_selection']) {
                throw new RuntimeException('Telegram Service reconfiguration route payload is invalid.');
            }
            $routeSelection = $this->selection($action->callbackPayload['route_selection'] ?? null);
        } elseif ($action->callbackAction === self::ACTION_ROUTE_AUTO) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Service reconfiguration automatic route payload is invalid.');
            }
        } else {
            throw new RuntimeException('Telegram Service reconfiguration route action is unsupported.');
        }

        try {
            $options = $this->reconfiguration->optionsForSelf(
                $action->userId,
                $action->userId,
                $this->currentDetail($action, $selection)->publicId,
                $offeringSelection,
                $routeSelection,
            );
        } catch (AuthorizationException|DomainException) {
            $this->showOfferings($action, $selection, $servicePage, $offeringPage, true);
            return;
        }

        if ($options->protocolSelectionMode === 'customer_selects') {
            $this->showProtocols($action, $selection, $servicePage, $offeringPage, $offeringSelection, $routeSelection, $options);
            return;
        }
        $this->createPreview($action, $selection, $servicePage, $offeringPage, $offeringSelection, $routeSelection, null);
    }

    private function handleProtocol(TelegramInteractionAction $action): void
    {
        [$servicePage, $selection, $offeringPage, $offeringSelection, $routeSelection] = $this->protocolState($action->sessionPayload);
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_PROTOCOL_SELECT
            || array_keys($action->callbackPayload) !== ['protocol_selection']) {
            throw new RuntimeException('Telegram Service reconfiguration protocol action is unsupported.');
        }
        $this->createPreview(
            $action,
            $selection,
            $servicePage,
            $offeringPage,
            $offeringSelection,
            $routeSelection,
            $this->selection($action->callbackPayload['protocol_selection'] ?? null),
        );
    }

    private function handlePreview(TelegramInteractionAction $action): void
    {
        [$servicePage, $selection, , $offeringSelection, $previewPublicId] = $this->previewState($action->sessionPayload);
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_CONFIRM
            || $action->callbackPayload !== []) {
            throw new RuntimeException('Telegram Service reconfiguration preview action is unsupported.');
        }

        try {
            $callback = $this->callbackPublicId($action);
            $quote = $this->reconfiguration->quoteForSelf(
                $action->userId,
                $action->userId,
                $offeringSelection,
                $previewPublicId,
                $this->clock->now(),
                'tg-service-reconfig-quote-'.substr(hash('sha256', $callback), 0, 48),
                'tg-service-reconfig-'.substr(hash('sha256', $callback.':quote'), 0, 40),
            );
        } catch (AuthorizationException|DomainException) {
            $this->showUnavailable($action, $selection, $servicePage);
            return;
        }

        $this->navigation->showPreparedServiceReconfigurationQuote(
            $action,
            $selection,
            $servicePage,
            $offeringSelection,
            $quote,
        );
    }

    private function showOfferings(
        TelegramInteractionAction $action,
        string $selection,
        int $servicePage,
        int $offeringPage,
        bool $stale = false,
    ): void {
        try {
            $detail = $this->currentDetail($action, $selection);
            if (! in_array(TelegramOwnedServiceAction::Reconfigure, $detail->allowedActions, true)) {
                throw new AuthorizationException('Service reconfiguration is unavailable.');
            }
            $catalog = $this->catalog->pageForSelf($action->userId, $action->userId, $offeringPage, self::PAGE_SIZE);
        } catch (AuthorizationException|DomainException) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selection, $servicePage);
            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_OFFERINGS,
            ['service_page' => $servicePage, 'service_selection' => $selection, 'offering_page' => $catalog->page],
            'offerings-'.$catalog->page,
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($catalog->items as $index => $offering) {
            $callback = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_OFFERING_SELECT,
                ['offering_selection' => $offering->selectionToken],
                'tg-service-reconfig-offering:'.hash('sha256', $action->requestKey.':'.$offering->offeringCode.':'.$index),
            );
            $name = $locale === 'en' && $offering->productNameEn !== null ? $offering->productNameEn : $offering->productNameFa;
            if ($offering->variantNameFa !== null) {
                $variant = $locale === 'en' && $offering->variantNameEn !== null ? $offering->variantNameEn : $offering->variantNameFa;
                $name .= ' — '.$variant;
            }
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.reconfiguration.offering_button', $locale, [
                    'name' => $name,
                    'price' => number_format($offering->basePriceIrr),
                ]),
                $callback->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }
        if ($catalog->page > 1) {
            $previous = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_OFFERING_PAGE,
                ['page' => $catalog->page - 1],
                'tg-service-reconfig-offering-prev:'.hash('sha256', $action->requestKey.':'.$catalog->page),
            );
            $rows[] = [new TelegramInlineCallbackButton('⬅️', $previous->publicId)];
        }
        if ($catalog->page < $catalog->totalPages) {
            $next = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_OFFERING_PAGE,
                ['page' => $catalog->page + 1],
                'tg-service-reconfig-offering-next:'.hash('sha256', $action->requestKey.':'.$catalog->page),
            );
            $rows[] = [new TelegramInlineCallbackButton('➡️', $next->publicId)];
        }
        $rows[] = [$this->backButton($session, $action, 'offerings-back')];
        $this->queue(
            $action,
            $this->translation('telegram.navigation.services.reconfiguration.offering_list', $locale, [
                'notice' => $stale ? $this->translation('telegram.navigation.services.reconfiguration.stale', $locale) : '',
            ]),
            'offerings',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function showRoutes(
        TelegramInteractionAction $action,
        string $selection,
        int $servicePage,
        int $offeringPage,
        string $offeringSelection,
        TelegramServiceReconfigurationOptions $options,
    ): void {
        $session = $this->transition(
            $action,
            self::STATE_ROUTE,
            [
                'service_page' => $servicePage,
                'service_selection' => $selection,
                'offering_page' => $offeringPage,
                'offering_selection' => $offeringSelection,
            ],
            'routes',
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $rows = [];
        if ($options->serverSelectionMode === 'hybrid') {
            $automatic = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_ROUTE_AUTO,
                [],
                'tg-service-reconfig-route-auto:'.hash('sha256', $action->requestKey),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.reconfiguration.route_auto', $locale),
                $automatic->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }
        foreach ($options->routeOptions as $index => $route) {
            $callback = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_ROUTE_SELECT,
                ['route_selection' => $route->selectionToken],
                'tg-service-reconfig-route:'.hash('sha256', $action->requestKey.':'.$route->serverCode.':'.$index),
            );
            $label = $locale === 'en' && $route->nameEn !== null ? $route->nameEn : $route->nameFa;
            $rows[] = [new TelegramInlineCallbackButton($label, $callback->publicId, TelegramInlineButtonStyle::Primary)];
        }
        $rows[] = [$this->backButton($session, $action, 'routes-back')];
        $this->queue(
            $action,
            $this->translation('telegram.navigation.services.reconfiguration.route_list', $locale),
            'routes',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function showProtocols(
        TelegramInteractionAction $action,
        string $selection,
        int $servicePage,
        int $offeringPage,
        string $offeringSelection,
        ?string $routeSelection,
        TelegramServiceReconfigurationOptions $options,
    ): void {
        $session = $this->transition(
            $action,
            self::STATE_PROTOCOL,
            [
                'service_page' => $servicePage,
                'service_selection' => $selection,
                'offering_page' => $offeringPage,
                'offering_selection' => $offeringSelection,
                'route_selection' => $routeSelection,
            ],
            'protocols',
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($options->protocolOptions as $index => $profile) {
            $callback = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_PROTOCOL_SELECT,
                ['protocol_selection' => $profile->selectionToken],
                'tg-service-reconfig-profile:'.hash('sha256', $action->requestKey.':'.$profile->profileCode.':'.$index),
            );
            $label = $locale === 'en' && $profile->nameEn !== null ? $profile->nameEn : $profile->nameFa;
            $rows[] = [new TelegramInlineCallbackButton($label, $callback->publicId, TelegramInlineButtonStyle::Primary)];
        }
        $rows[] = [$this->backButton($session, $action, 'protocols-back')];
        $this->queue(
            $action,
            $this->translation('telegram.navigation.services.reconfiguration.protocol_list', $locale),
            'protocols',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function createPreview(
        TelegramInteractionAction $action,
        string $selection,
        int $servicePage,
        int $offeringPage,
        string $offeringSelection,
        ?string $routeSelection,
        ?string $protocolSelection,
    ): void {
        try {
            $callback = $this->callbackPublicId($action);
            $detail = $this->currentDetail($action, $selection);
            $preview = $this->reconfiguration->previewForSelf(
                $action->userId,
                $action->userId,
                $detail->publicId,
                $offeringSelection,
                $routeSelection,
                $protocolSelection,
                'tg-service-reconfig-preview-'.substr(hash('sha256', $callback), 0, 48),
                'tg-service-reconfig-'.substr(hash('sha256', $callback.':preview'), 0, 40),
            );
        } catch (AuthorizationException|DomainException) {
            $this->showOfferings($action, $selection, $servicePage, $offeringPage, true);
            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_PREVIEW,
            [
                'service_page' => $servicePage,
                'service_selection' => $selection,
                'offering_page' => $offeringPage,
                'offering_selection' => $offeringSelection,
                'preview_public_id' => $preview->previewPublicId,
            ],
            'preview',
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $confirm = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_CONFIRM,
            [],
            'tg-service-reconfig-confirm:'.hash('sha256', $action->requestKey.':'.$preview->previewPublicId),
        );
        $this->queue(
            $action,
            $this->translation('telegram.navigation.services.reconfiguration.preview', $locale, [
                'source_plan' => $preview->sourceOfferingCode,
                'target_plan' => $preview->targetOfferingCode,
                'server' => $preview->targetSalesServerCode,
                'protocol' => $preview->targetProtocolProfileCode,
                'difference' => number_format($preview->priceDifferenceIrr),
                'fee' => number_format($preview->operationFeeIrr),
                'total' => number_format($preview->totalPriceIrr),
            ]),
            'preview',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation(
                        $preview->requiresPayment()
                            ? 'telegram.navigation.services.reconfiguration.confirm_paid'
                            : 'telegram.navigation.services.reconfiguration.confirm_free',
                        $locale,
                    ),
                    $confirm->publicId,
                    TelegramInlineButtonStyle::Success,
                )],
                [$this->backButton($session, $action, 'preview-back')],
            ]),
        );
    }

    private function showUnavailable(TelegramInteractionAction $action, string $selection, int $servicePage): void
    {
        $session = $this->transition(
            $action,
            self::STATE_PREVIEW,
            [
                'service_page' => $servicePage,
                'service_selection' => $selection,
                'offering_page' => 1,
                'offering_selection' => str_repeat('0', 40),
                'preview_public_id' => str_repeat('0', 26),
            ],
            'unavailable',
        );
        if ($session === null) {
            return;
        }
        $this->queue(
            $action,
            $this->translation('telegram.navigation.services.reconfiguration.unavailable', $this->locale($action->userId)),
            'unavailable',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($session, $action, 'unavailable-back')]]),
        );
    }

    private function currentDetail(TelegramInteractionAction $action, string $selection): TelegramOwnedServiceDetail
    {
        return $this->services->detailForSelf($action->userId, $action->userId, $selection);
    }

    /** @param array<string,mixed> $payload
     * @return array{int,string}
     */
    private function serviceReturnState(array $payload): array
    {
        $page = filter_var($payload['service_page'] ?? $payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $selection = $payload['service_selection'] ?? null;
        if ($page === false || ! is_string($selection) || preg_match('/\A[0-9a-f]{40}\z/', $selection) !== 1) {
            throw new RuntimeException('Stored Telegram Service reconfiguration return state is invalid.');
        }
        return [(int) $page, $selection];
    }

    /** @param array<string,mixed> $payload
     * @return array{int,string,int}
     */
    private function offeringsState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['offering_page', 'service_page', 'service_selection']) {
            throw new RuntimeException('Stored Telegram Service reconfiguration Offering state is invalid.');
        }
        [$servicePage, $selection] = $this->serviceReturnState($payload);
        $offeringPage = filter_var($payload['offering_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($offeringPage === false) {
            throw new RuntimeException('Stored Telegram Service reconfiguration Offering page is invalid.');
        }
        return [$servicePage, $selection, (int) $offeringPage];
    }

    /** @param array<string,mixed> $payload
     * @return array{int,string,int,string}
     */
    private function routeState(array $payload): array
    {
        if (count($payload) !== 4 || ! is_string($payload['offering_selection'] ?? null)) {
            throw new RuntimeException('Stored Telegram Service reconfiguration route state is invalid.');
        }
        [$servicePage, $selection] = $this->serviceReturnState($payload);
        $offeringPage = filter_var($payload['offering_page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($offeringPage === false) {
            throw new RuntimeException('Stored Telegram Service reconfiguration route page is invalid.');
        }
        return [$servicePage, $selection, (int) $offeringPage, $this->selection($payload['offering_selection'])];
    }

    /** @param array<string,mixed> $payload
     * @return array{int,string,int,string,?string}
     */
    private function protocolState(array $payload): array
    {
        if (count($payload) !== 5 || ! array_key_exists('route_selection', $payload)) {
            throw new RuntimeException('Stored Telegram Service reconfiguration protocol state is invalid.');
        }
        [$servicePage, $selection, $offeringPage, $offeringSelection] = $this->routeState(array_diff_key($payload, ['route_selection' => true]));
        $routeSelection = $payload['route_selection'];
        if ($routeSelection !== null) {
            $routeSelection = $this->selection($routeSelection);
        }
        return [$servicePage, $selection, $offeringPage, $offeringSelection, $routeSelection];
    }

    /** @param array<string,mixed> $payload
     * @return array{int,string,int,string,string}
     */
    private function previewState(array $payload): array
    {
        if (count($payload) !== 5
            || ! is_string($payload['preview_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['preview_public_id']) !== 1) {
            throw new RuntimeException('Stored Telegram Service reconfiguration preview state is invalid.');
        }
        [$servicePage, $selection, $offeringPage, $offeringSelection] = $this->routeState(array_diff_key($payload, ['preview_public_id' => true]));
        return [$servicePage, $selection, $offeringPage, $offeringSelection, $payload['preview_public_id']];
    }

    private function selection(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{40}\z/', $value) !== 1) {
            throw new RuntimeException('Telegram Service reconfiguration selection token is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function page(array $payload): int
    {
        $page = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new RuntimeException('Telegram Service reconfiguration Service page is invalid.');
        }
        return (int) $page;
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram Service reconfiguration callback identity is invalid.');
        }
        return $action->callbackPublicId;
    }

    private function isBack(TelegramInteractionAction $action): bool
    {
        return $action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []);
    }

    private function backButton(TelegramInteractionSessionReceipt $session, TelegramInteractionAction $action, string $surface): TelegramInlineCallbackButton
    {
        $callback = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-service-reconfig-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );
        return new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        );
    }

    /** @param array<string,mixed> $payload */
    private function transition(TelegramInteractionAction $action, string $state, array $payload, string $surface): ?TelegramInteractionSessionReceipt
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-service-reconfig-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Service reconfiguration actor binding changed.');
        }
        return $session;
    }

    private function queue(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}
            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };
        $this->delivery->send(
            $action->telegramUserId,
            $this->presentations->fromSource($source),
            'tg-service-reconfig-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-service-reconfig:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']' || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Service reconfiguration translation is unavailable.');
        }
        return $value;
    }
}
