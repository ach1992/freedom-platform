<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseNowPaymentsPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded NOWPayments Telegram checkout orchestration. Provider, pricing,
 * settlement, promotion and Order authority remain in Payments/Orders.
 */
final readonly class TelegramNowPaymentsNavigationHandler
{
    private const STATE_PAYMENT_METHODS = 'purchase_payment_methods';
    private const STATE_PREPARING = 'purchase_nowpayments_preparing';
    private const STATE_PAYMENT = 'purchase_nowpayments_payment';
    private const STATE_PENDING = 'purchase_nowpayments_pending';
    private const STATE_FINISHED = 'purchase_nowpayments_finished';

    private const ACTION_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';
    private const ACTION_REFRESH = 'navigation.purchase.nowpayments.refresh';
    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private TelegramCustomerPurchaseNowPaymentsPayment $nowPayments,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_PREPARING,
            self::STATE_PAYMENT,
            self::STATE_PENDING,
            self::STATE_FINISHED,
        ], true)) {
            return true;
        }

        return $action->sessionState === self::STATE_PAYMENT_METHODS
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PAYMENT_METHOD_SELECT
            && ($action->callbackPayload['method_code'] ?? null) === 'nowpayments';
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_PAYMENT_METHODS) {
            if ($this->paymentMethodFromPayload($action->callbackPayload) !== 'nowpayments') {
                throw new RuntimeException('Telegram NOWPayments router received a non-NOWPayments selection.');
            }
            $this->beginPreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_PREPARING) {
            $this->resumePreparation($action);

            return;
        }
        if (in_array($action->sessionState, [self::STATE_PAYMENT, self::STATE_PENDING], true)) {
            $this->handleActiveSurface($action);

            return;
        }
        if ($action->sessionState === self::STATE_FINISHED) {
            $this->handleFinishedSurface($action);

            return;
        }

        throw new RuntimeException('Telegram NOWPayments navigation state is unsupported.');
    }

    private function beginPreparation(TelegramInteractionAction $action): void
    {
        $state = $this->paymentMethodsStateFromPayload($action->sessionPayload);
        $state['payment_method_code'] = 'nowpayments';
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREPARING,
                $state,
                'tg-nowpayments-prepare-claim:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $claim->userId);
        $this->completePreparation($action, $claim->version, $state);
    }

    private function resumePreparation(TelegramInteractionAction $action): void
    {
        $state = $this->selectedStateFromPayload($action->sessionPayload);
        if ($this->isBackAction($action)) {
            $this->returnPaymentMethods($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        $this->completePreparation($action, $action->sessionVersion, $state);
    }

    /** @param array<string,mixed> $state */
    private function completePreparation(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        try {
            $receipt = $this->nowPayments->prepareForSelf(
                $action->userId,
                $action->userId,
                $state['order_public_id'],
                $state['quote_public_id'],
                $state['quote_configuration_hash'],
                $state['payment_decision_public_id'],
                $state['payment_decision_configuration_hash'],
                $this->operationKey($state),
            );
        } catch (AuthorizationException) {
            $this->returnHome($action, $sessionVersion);

            return;
        } catch (DomainException|InvalidArgumentException) {
            $this->returnPaymentMethods($action, $state, true, $sessionVersion);

            return;
        }

        $this->routeReceipt($action, $sessionVersion, $state, $receipt);
    }

    private function handleActiveSurface(TelegramInteractionAction $action): void
    {
        $state = $this->activeStateFromPayload($action->sessionPayload);
        if ($this->isBackAction($action)) {
            $this->returnPaymentMethods($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_REFRESH || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram NOWPayments active callback action is unsupported.');
            }
            $this->refresh($action, $state);
        }
    }

    private function handleFinishedSurface(TelegramInteractionAction $action): void
    {
        $this->activeStateFromPayload($action->sessionPayload);
        if ($this->isBackAction($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            throw new RuntimeException('Telegram NOWPayments finished callback action is unsupported.');
        }
    }

    /** @param array<string,mixed> $state */
    private function refresh(TelegramInteractionAction $action, array $state): void
    {
        try {
            $receipt = $this->nowPayments->refreshForSelf(
                $action->userId,
                $action->userId,
                $state['nowpayments_payment_intent_public_id'],
                $this->operationKey($state),
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException|InvalidArgumentException) {
            $this->renderPending($action, $action->sessionVersion, true);

            return;
        }

        if (! hash_equals($state['nowpayments_authority_public_id'], $receipt->authorityPublicId)
            || ! hash_equals($state['nowpayments_payment_intent_public_id'], $receipt->paymentIntentPublicId)) {
            throw new RuntimeException('Telegram NOWPayments replay identity changed.');
        }
        $this->routeReceipt($action, $action->sessionVersion, $state, $receipt);
    }

    /** @param array<string,mixed> $state */
    private function routeReceipt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        TelegramCustomerPurchaseNowPaymentsReceipt $receipt,
    ): void {
        $state = [
            ...$state,
            'nowpayments_authority_public_id' => $receipt->authorityPublicId,
            'nowpayments_payment_intent_public_id' => $receipt->paymentIntentPublicId,
            'nowpayments_state' => $receipt->state,
            'nowpayments_rate_source' => $receipt->rateSource,
            'nowpayments_rate_irr' => $receipt->rateIrr,
            'nowpayments_price_amount_usd' => $receipt->priceAmountUsd,
            'nowpayments_pay_currency' => $receipt->payCurrency,
            'nowpayments_provider_payment_id' => $receipt->providerPaymentId,
            'nowpayments_provider_status' => $receipt->providerStatus,
            'nowpayments_pay_amount' => $receipt->providerPayAmount,
            'nowpayments_pay_address' => $receipt->providerPayAddress,
            'nowpayments_settlement_public_id' => $receipt->settlementPublicId,
            'nowpayments_manual_review' => $receipt->manualReviewRequired,
        ];

        if ($receipt->state === 'finished' && $receipt->settlementPublicId !== null) {
            $this->moveToSurface($action, $sessionVersion, self::STATE_FINISHED, $state, 'finished');

            return;
        }
        if (in_array($receipt->state, ['failed', 'expired'], true)) {
            $this->returnPaymentMethods($action, $state, true, $sessionVersion);

            return;
        }
        if ($receipt->state === 'created'
            && $receipt->providerPayAmount !== null
            && $receipt->providerPayAddress !== null) {
            $this->moveToSurface($action, $sessionVersion, self::STATE_PAYMENT, $state, 'payment');

            return;
        }

        $this->moveToSurface($action, $sessionVersion, self::STATE_PENDING, $state, 'pending');
    }

    /** @param array<string,mixed> $state */
    private function moveToSurface(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $targetState,
        array $state,
        string $surface,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                $targetState,
                $state,
                'tg-nowpayments-'.$surface.'-state:'.hash('sha256', $state['order_public_id'].':'.$state['nowpayments_authority_public_id']),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        if ($targetState === self::STATE_PAYMENT) {
            $this->renderPayment($action, $session->version, $state);

            return;
        }
        if ($targetState === self::STATE_FINISHED) {
            $this->renderFinished($action, $session->version, $state);

            return;
        }
        $this->renderPending(
            $action,
            $session->version,
            (bool) $state['nowpayments_manual_review'] || in_array($state['nowpayments_state'], ['uncertain', 'manual_review', 'initiating'], true),
        );
    }

    /** @param array<string,mixed> $state */
    private function returnPaymentMethods(
        TelegramInteractionAction $action,
        array $state,
        bool $unavailable = false,
        ?int $expectedVersion = null,
    ): void {
        foreach ([
            'payment_method_code',
            'nowpayments_authority_public_id', 'nowpayments_payment_intent_public_id', 'nowpayments_state',
            'nowpayments_rate_source', 'nowpayments_rate_irr', 'nowpayments_price_amount_usd', 'nowpayments_pay_currency',
            'nowpayments_provider_payment_id', 'nowpayments_provider_status', 'nowpayments_pay_amount',
            'nowpayments_pay_address', 'nowpayments_settlement_public_id', 'nowpayments_manual_review',
        ] as $key) {
            unset($state[$key]);
        }
        $methodsState = $this->paymentMethodsStateFromPayload($state);
        try {
            $this->orders->currentForSelf(
                $action->userId,
                $action->userId,
                $methodsState['order_public_id'],
                $methodsState['quote_public_id'],
                $methodsState['quote_configuration_hash'],
            );
            $decision = $this->paymentMethods->currentForSelf(
                $action->userId,
                $action->userId,
                $methodsState['quote_public_id'],
                $methodsState['quote_configuration_hash'],
                $methodsState['payment_decision_public_id'],
                $methodsState['payment_decision_configuration_hash'],
            );
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $expectedVersion ?? $action->sessionVersion,
                self::STATE_PAYMENT_METHODS,
                $methodsState,
                'tg-nowpayments-methods-back:'.hash('sha256', $action->requestKey),
            );
            $this->assertActor($action, $session->userId);
            if ($unavailable) {
                $this->queueConfidential(
                    $action,
                    $this->translation('telegram_nowpayments.unavailable', $this->locale($action->userId)),
                    'unavailable',
                );
            }
            $this->renderPaymentMethods($action, $session->version, $decision);
        } catch (AuthorizationException|DomainException) {
            $this->returnHome($action, $expectedVersion);
        }
    }

    private function returnHome(TelegramInteractionAction $action, ?int $expectedVersion = null): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $expectedVersion ?? $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-nowpayments-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':nowpayments-home',
            $action->botId,
            $action->updateId,
            $action->telegramAccountId,
            $action->userId,
            $action->telegramUserId,
            $session->publicId,
            $session->flow,
            $session->state,
            $session->version,
            $session->payload,
            null,
            null,
            null,
            [],
            $action->replayed,
            null,
            $action->messageAcceptedAt,
        ));
    }

    /** @param array<string,mixed> $state */
    private function renderPayment(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        $locale = $this->locale($action->userId);
        $rows = [[$this->refreshButton($action, $sessionVersion, 'payment')]];
        $this->appendBack($action, $sessionVersion, $rows, 'payment');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_nowpayments.payment', $locale, [
                'pay_amount' => (string) $state['nowpayments_pay_amount'],
                'pay_currency' => strtoupper((string) $state['nowpayments_pay_currency']),
                'pay_address' => (string) $state['nowpayments_pay_address'],
                'price_usd' => (string) $state['nowpayments_price_amount_usd'],
                'rate_irr' => (string) $state['nowpayments_rate_irr'],
                'rate_source' => (string) $state['nowpayments_rate_source'],
            ]),
            'payment',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderPending(TelegramInteractionAction $action, int $sessionVersion, bool $uncertain): void
    {
        $locale = $this->locale($action->userId);
        $rows = [[$this->refreshButton($action, $sessionVersion, $uncertain ? 'uncertain' : 'pending')]];
        $this->appendBack($action, $sessionVersion, $rows, $uncertain ? 'uncertain' : 'pending');
        $this->queueConfidential(
            $action,
            $this->translation($uncertain ? 'telegram_nowpayments.uncertain' : 'telegram_nowpayments.pending', $locale),
            $uncertain ? 'uncertain' : 'pending',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param array<string,mixed> $state */
    private function renderFinished(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, 'finished');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_nowpayments.finished', $this->locale($action->userId), [
                'settlement' => (string) $state['nowpayments_settlement_public_id'],
            ]),
            'finished',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderPaymentMethods(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerPurchasePaymentMethodsDecision $decision,
    ): void {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($decision->methodCodes as $offset => $methodCode) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PAYMENT_METHOD_SELECT,
                ['method_code' => $methodCode],
                'tg-nowpayments-method-button:'.hash('sha256', $action->requestKey.':'.$methodCode),
            );
            $label = $this->methodLabel($methodCode, $locale, $offset + 1);
            $rows[] = [new TelegramInlineCallbackButton($label, $callback->publicId, TelegramInlineButtonStyle::Primary)];
            $items[] = $this->translation('telegram.navigation.purchase.payment_methods.item', $locale, [
                'number' => $offset + 1,
                'method' => $label,
            ]);
        }
        $this->appendBack($action, $sessionVersion, $rows, 'methods');
        $text = $decision->methodCodes === []
            ? $this->translation('telegram.navigation.purchase.payment_methods.empty', $locale)
            : $this->translation('telegram.navigation.purchase.payment_methods.list', $locale, ['items' => implode("\n", $items)]);
        $this->queueConfidential($action, $text, 'methods', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function refreshButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_REFRESH,
            [],
            'tg-nowpayments-refresh:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton(
            $this->translation('telegram_nowpayments.refresh', $this->locale($action->userId)),
            $callback->publicId,
            TelegramInlineButtonStyle::Primary,
        );
    }

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $sessionVersion, array &$rows, string $surface): void
    {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-nowpayments-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        )];
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        ?TelegramInlineKeyboardSnapshot $keyboard = null,
    ): void {
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
            $this->confidentialPresentations->fromSource($source),
            'tg-nowpayments-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-nowpayments:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function paymentMethodsStateFromPayload(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $base = ['offering_selection', 'order_public_id', 'page', 'payment_decision_configuration_hash', 'payment_decision_public_id', 'quote_configuration_hash', 'quote_public_id'];
        $discounted = ['discount_consumption_configuration_hash', 'discount_consumption_public_id', 'offering_selection', 'order_public_id', 'page', 'payment_decision_configuration_hash', 'payment_decision_public_id', 'promotion_resolution_public_id', 'quote_configuration_hash', 'quote_public_id'];
        if (! in_array($keys, [$base, $discounted], true)
            || ! is_int($payload['page'] ?? null) || $payload['page'] < 1
            || ! is_string($payload['offering_selection'] ?? null) || preg_match('/\A[0-9a-f]{40}\z/', $payload['offering_selection']) !== 1
            || ! $this->isUlid($payload['quote_public_id'] ?? null)
            || ! $this->isSha256($payload['quote_configuration_hash'] ?? null)
            || ! $this->isUlid($payload['payment_decision_public_id'] ?? null)
            || ! $this->isSha256($payload['payment_decision_configuration_hash'] ?? null)
            || ! $this->isUlid($payload['order_public_id'] ?? null)) {
            throw new RuntimeException('Telegram NOWPayments payment-method state is invalid.');
        }
        if ($keys === $discounted
            && (! $this->isUlid($payload['discount_consumption_public_id'] ?? null)
                || ! $this->isSha256($payload['discount_consumption_configuration_hash'] ?? null)
                || ! $this->isUlid($payload['promotion_resolution_public_id'] ?? null))) {
            throw new RuntimeException('Telegram NOWPayments discounted payment state is invalid.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function selectedStateFromPayload(array $payload): array
    {
        if (($payload['payment_method_code'] ?? null) !== 'nowpayments') {
            throw new RuntimeException('Telegram NOWPayments selected-method state is invalid.');
        }
        $state = $payload;
        unset($state['payment_method_code']);
        $state = $this->paymentMethodsStateFromPayload($state);
        $state['payment_method_code'] = 'nowpayments';

        return $state;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function activeStateFromPayload(array $payload): array
    {
        foreach ([
            'nowpayments_provider_payment_id', 'nowpayments_provider_status', 'nowpayments_pay_amount',
            'nowpayments_pay_address', 'nowpayments_settlement_public_id',
        ] as $key) {
            if (! array_key_exists($key, $payload) || ($payload[$key] !== null && ! is_string($payload[$key]))) {
                throw new RuntimeException('Telegram NOWPayments active state is invalid.');
            }
        }
        if (! $this->isUlid($payload['nowpayments_authority_public_id'] ?? null)
            || ! $this->isUlid($payload['nowpayments_payment_intent_public_id'] ?? null)
            || ! is_string($payload['nowpayments_state'] ?? null)
            || ! in_array($payload['nowpayments_state'], ['initiating', 'created', 'uncertain', 'manual_review', 'finished', 'failed', 'expired'], true)
            || ! is_string($payload['nowpayments_rate_source'] ?? null)
            || ! is_string($payload['nowpayments_rate_irr'] ?? null)
            || ! is_string($payload['nowpayments_price_amount_usd'] ?? null)
            || ! is_string($payload['nowpayments_pay_currency'] ?? null)
            || ! is_bool($payload['nowpayments_manual_review'] ?? null)) {
            throw new RuntimeException('Telegram NOWPayments active state is invalid.');
        }
        $selected = $payload;
        foreach ([
            'nowpayments_authority_public_id', 'nowpayments_payment_intent_public_id', 'nowpayments_state',
            'nowpayments_rate_source', 'nowpayments_rate_irr', 'nowpayments_price_amount_usd', 'nowpayments_pay_currency',
            'nowpayments_provider_payment_id', 'nowpayments_provider_status', 'nowpayments_pay_amount',
            'nowpayments_pay_address', 'nowpayments_settlement_public_id', 'nowpayments_manual_review',
        ] as $key) {
            unset($selected[$key]);
        }
        $this->selectedStateFromPayload($selected);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function paymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code'] || ! is_string($payload['method_code'] ?? null)) {
            throw new RuntimeException('Telegram NOWPayments method callback payload is invalid.');
        }

        return $payload['method_code'];
    }

    /** @param array<string,mixed> $state */
    private function operationKey(array $state): string
    {
        return hash('sha256', 'telegram-nowpayments-order:'.$state['order_public_id']);
    }

    private function methodLabel(string $methodCode, string $locale, int $number): string
    {
        if (in_array($methodCode, ['wallet', 'card_to_card', 'gift_card', 'usdt_bep20', 'zarinpal', 'nowpayments'], true)) {
            return $this->translation('telegram.navigation.purchase.payment_methods.methods.'.$methodCode, $locale);
        }

        return $this->translation('telegram.navigation.purchase.payment_methods.methods.other', $locale, ['number' => $number]);
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram NOWPayments translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram NOWPayments translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram NOWPayments session actor binding is invalid.');
        }
    }

    private function isBackAction(TelegramInteractionAction $action): bool
    {
        return $action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []);
    }

    private function isUlid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
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
