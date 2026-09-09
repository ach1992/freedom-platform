<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseZarinpalPayment;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded Zarinpal Telegram checkout orchestration. Financial/provider authority
 * remains in the Payments module; this handler owns only session-safe routing
 * and typed StartPay presentation through the existing durable Telegram path.
 */
final readonly class TelegramZarinpalNavigationHandler
{
    private const STATE_PAYMENT_METHODS = 'purchase_payment_methods';

    private const STATE_PREPARING = 'purchase_zarinpal_preparing';

    private const STATE_REDIRECT = 'purchase_zarinpal_redirect';

    private const STATE_PENDING = 'purchase_zarinpal_pending';

    private const ACTION_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private TelegramCustomerPurchaseZarinpalPayment $zarinpal,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [self::STATE_PREPARING, self::STATE_REDIRECT, self::STATE_PENDING], true)) {
            return true;
        }

        return $action->sessionState === self::STATE_PAYMENT_METHODS
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PAYMENT_METHOD_SELECT
            && ($action->callbackPayload['method_code'] ?? null) === 'zarinpal';
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_PAYMENT_METHODS) {
            if ($this->paymentMethodFromPayload($action->callbackPayload) !== 'zarinpal') {
                throw new RuntimeException('Telegram Zarinpal router received a non-Zarinpal selection.');
            }
            $this->beginPreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_PREPARING) {
            $this->resumePreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_REDIRECT) {
            $this->handleRedirectSurface($action);

            return;
        }
        if ($action->sessionState === self::STATE_PENDING) {
            $this->handlePendingSurface($action);

            return;
        }

        throw new RuntimeException('Telegram Zarinpal navigation state is unsupported.');
    }

    private function beginPreparation(TelegramInteractionAction $action): void
    {
        $state = $this->paymentMethodsStateFromPayload($action->sessionPayload);
        $state['payment_method_code'] = 'zarinpal';
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREPARING,
                $state,
                'tg-zarinpal-prepare-claim:'.hash('sha256', $action->requestKey),
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
            $redirect = $this->zarinpal->prepareForSelf(
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

        $activeState = [
            ...$state,
            'zarinpal_request_public_id' => $redirect->requestPublicId,
            'zarinpal_payment_intent_public_id' => $redirect->paymentIntentPublicId,
            'zarinpal_state' => $redirect->state,
        ];

        if ($redirect->state === 'redirectable' && $redirect->redirectUrl !== null) {
            try {
                $urlButton = $this->urlButton($action, $redirect->redirectUrl);
            } catch (InvalidArgumentException) {
                $this->moveToPending($action, $sessionVersion, $activeState, false);

                return;
            }

            try {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::STATE_REDIRECT,
                    $activeState,
                    'tg-zarinpal-redirect-state:'.hash('sha256', $state['order_public_id']),
                );
            } catch (DomainException) {
                return;
            }
            $this->assertActor($action, $session->userId);
            $this->renderRedirect($action, $session->version, $urlButton);

            return;
        }

        if ($redirect->state === 'failed') {
            $this->returnPaymentMethods($action, $state, true, $sessionVersion);

            return;
        }

        $this->moveToPending(
            $action,
            $sessionVersion,
            $activeState,
            $redirect->manualReviewRequired || in_array($redirect->state, ['uncertain', 'manual_review', 'initiating'], true),
        );
    }

    private function handleRedirectSurface(TelegramInteractionAction $action): void
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

        $this->replayRedirect($action, $state);
    }

    /** @param array<string,mixed> $state */
    private function replayRedirect(TelegramInteractionAction $action, array $state): void
    {
        try {
            $redirect = $this->zarinpal->prepareForSelf(
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
            $this->returnHome($action);

            return;
        } catch (DomainException|InvalidArgumentException) {
            $this->moveToPending($action, $action->sessionVersion, $state, false);

            return;
        }

        if (! hash_equals($state['zarinpal_request_public_id'], $redirect->requestPublicId)
            || ! hash_equals($state['zarinpal_payment_intent_public_id'], $redirect->paymentIntentPublicId)) {
            throw new RuntimeException('Telegram Zarinpal replay identity changed.');
        }

        $state['zarinpal_state'] = $redirect->state;
        if ($redirect->state !== 'redirectable' || $redirect->redirectUrl === null) {
            $this->moveToPending(
                $action,
                $action->sessionVersion,
                $state,
                $redirect->manualReviewRequired || in_array($redirect->state, ['uncertain', 'manual_review', 'initiating'], true),
            );

            return;
        }

        try {
            $urlButton = $this->urlButton($action, $redirect->redirectUrl);
        } catch (InvalidArgumentException) {
            $this->moveToPending($action, $action->sessionVersion, $state, false);

            return;
        }
        $this->renderRedirect($action, $action->sessionVersion, $urlButton);
    }

    private function handlePendingSurface(TelegramInteractionAction $action): void
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
            throw new RuntimeException('Telegram pending Zarinpal callback action is unsupported.');
        }
    }

    /** @param array<string,mixed> $state */
    private function moveToPending(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        bool $uncertain,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_PENDING,
                $state,
                'tg-zarinpal-pending-state:'.hash('sha256', $state['order_public_id'].':'.$state['zarinpal_request_public_id']),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderPending($action, $session->version, $uncertain);
    }

    /** @param array<string,mixed> $state */
    private function returnPaymentMethods(
        TelegramInteractionAction $action,
        array $state,
        bool $unavailable = false,
        ?int $expectedVersion = null,
    ): void {
        foreach ([
            'payment_method_code', 'zarinpal_request_public_id', 'zarinpal_payment_intent_public_id', 'zarinpal_state',
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
                'tg-zarinpal-methods-back:'.hash('sha256', $action->requestKey),
            );
            $this->assertActor($action, $session->userId);
            if ($unavailable) {
                $this->queueConfidential(
                    $action,
                    $this->translation('telegram_zarinpal.unavailable', $this->locale($action->userId)),
                    'unavailable',
                    new TelegramInlineKeyboardSnapshot([]),
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
                'tg-zarinpal-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':zarinpal-home',
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

    private function renderRedirect(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramInlineHttpsUrlButton $urlButton,
    ): void {
        $rows = [[$urlButton]];
        $this->appendBack($action, $sessionVersion, $rows, 'redirect');
        $this->queueConfidential(
            $action,
            $this->translation('telegram_zarinpal.redirect', $this->locale($action->userId)),
            'redirect',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderPending(TelegramInteractionAction $action, int $sessionVersion, bool $uncertain): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, $uncertain ? 'uncertain' : 'pending');
        $this->queueConfidential(
            $action,
            $this->translation($uncertain ? 'telegram_zarinpal.uncertain' : 'telegram_zarinpal.unavailable', $this->locale($action->userId)),
            $uncertain ? 'uncertain' : 'pending',
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
                'tg-zarinpal-method-button:'.hash('sha256', $action->requestKey.':'.$methodCode),
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

    /** @param list<list<TelegramInlineCallbackButton|TelegramInlineHttpsUrlButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $sessionVersion, array &$rows, string $surface): void
    {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-zarinpal-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        )];
    }

    private function urlButton(TelegramInteractionAction $action, string $url): TelegramInlineHttpsUrlButton
    {
        return new TelegramInlineHttpsUrlButton(
            $this->translation('telegram_zarinpal.open_gateway', $this->locale($action->userId)),
            $url,
            TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
            TelegramInlineButtonStyle::Primary,
        );
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
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
            'tg-zarinpal-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-zarinpal:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
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
            throw new RuntimeException('Telegram Zarinpal payment-method state is invalid.');
        }
        if ($keys === $discounted
            && (! $this->isUlid($payload['discount_consumption_public_id'] ?? null)
                || ! $this->isSha256($payload['discount_consumption_configuration_hash'] ?? null)
                || ! $this->isUlid($payload['promotion_resolution_public_id'] ?? null))) {
            throw new RuntimeException('Telegram Zarinpal discounted payment state is invalid.');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function selectedStateFromPayload(array $payload): array
    {
        if (($payload['payment_method_code'] ?? null) !== 'zarinpal') {
            throw new RuntimeException('Telegram Zarinpal selected-method state is invalid.');
        }
        $state = $payload;
        unset($state['payment_method_code']);
        $state = $this->paymentMethodsStateFromPayload($state);
        $state['payment_method_code'] = 'zarinpal';

        return $state;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function activeStateFromPayload(array $payload): array
    {
        if (! $this->isUlid($payload['zarinpal_request_public_id'] ?? null)
            || ! $this->isUlid($payload['zarinpal_payment_intent_public_id'] ?? null)
            || ! is_string($payload['zarinpal_state'] ?? null)
            || ! in_array($payload['zarinpal_state'], ['initiating', 'redirectable', 'uncertain', 'failed', 'verified', 'manual_review'], true)) {
            throw new RuntimeException('Telegram Zarinpal active state is invalid.');
        }
        $selected = $payload;
        unset($selected['zarinpal_request_public_id'], $selected['zarinpal_payment_intent_public_id'], $selected['zarinpal_state']);
        $this->selectedStateFromPayload($selected);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function paymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code'] || ! is_string($payload['method_code'] ?? null)) {
            throw new RuntimeException('Telegram Zarinpal method callback payload is invalid.');
        }

        return $payload['method_code'];
    }

    /** @param array<string,mixed> $state */
    private function operationKey(array $state): string
    {
        return hash('sha256', 'telegram-zarinpal-order:'.$state['order_public_id']);
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
        $value = $this->translator->get($key, $replace, $locale);
        if (! is_string($value)) {
            throw new RuntimeException('Telegram Zarinpal translation is invalid.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram Zarinpal session actor binding is invalid.');
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
