<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseUsdtPayment;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded direct-USDT Telegram journey. TXID text crosses only the transient
 * message action and the existing USDT submission authority; session and
 * presentation state retain only safe identifiers and a masked TXID.
 */
final readonly class TelegramUsdtNavigationHandler
{
    private const STATE_PAYMENT_METHODS = 'purchase_payment_methods';
    private const STATE_PREPARING = 'purchase_usdt_preparing';
    private const STATE_TXID_INPUT = 'purchase_usdt_txid_input';
    private const STATE_SUBMITTING = 'purchase_usdt_submitting';
    private const STATE_SUBMITTED = 'purchase_usdt_submitted';
    private const ACTION_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';
    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private TelegramCustomerPurchaseUsdtPayment $usdt,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [self::STATE_PREPARING, self::STATE_TXID_INPUT, self::STATE_SUBMITTED], true)) {
            return true;
        }

        return $action->sessionState === self::STATE_PAYMENT_METHODS
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PAYMENT_METHOD_SELECT
            && ($action->callbackPayload['method_code'] ?? null) === 'usdt_bep20';
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_PAYMENT_METHODS) {
            if ($this->paymentMethodFromPayload($action->callbackPayload) !== 'usdt_bep20') {
                throw new RuntimeException('Telegram USDT router received a non-USDT selection.');
            }
            $this->beginPreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_PREPARING) {
            $this->resumePreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_TXID_INPUT) {
            $this->handleTxidInput($action);

            return;
        }
        if ($action->sessionState === self::STATE_SUBMITTED) {
            $this->handleSubmitted($action);

            return;
        }

        throw new RuntimeException('Telegram USDT navigation state is unsupported.');
    }

    private function beginPreparation(TelegramInteractionAction $action): void
    {
        $state = $this->paymentMethodsStateFromPayload($action->sessionPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram USDT selection requires a pre-payment Order.');
        }
        $state['payment_method_code'] = 'usdt_bep20';
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREPARING,
                $state,
                'tg-usdt-prepare-claim:'.hash('sha256', $action->requestKey),
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
        if ($action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])) {
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
            $instructions = $this->usdt->prepareForSelf(
                $action->userId,
                $action->userId,
                $state['order_public_id'],
                $state['quote_public_id'],
                $state['quote_configuration_hash'],
                $state['payment_decision_public_id'],
                $state['payment_decision_configuration_hash'],
                hash('sha256', 'telegram-usdt-order:'.$state['order_public_id']),
            );
        } catch (AuthorizationException) {
            $this->returnHome($action, $sessionVersion);

            return;
        } catch (DomainException|InvalidArgumentException) {
            $this->returnPaymentMethods($action, $state, true, $sessionVersion);

            return;
        }

        $inputState = [
            ...$state,
            'usdt_authority_public_id' => $instructions->authorityPublicId,
            'usdt_payment_intent_public_id' => $instructions->paymentIntentPublicId,
            'usdt_amount_quote_public_id' => $instructions->amountQuotePublicId,
            'usdt_network' => $instructions->network,
            'usdt_exact_amount' => $instructions->exactUsdt,
            'usdt_destination_address' => $instructions->destinationAddress,
            'usdt_expires_at' => $instructions->expiresAt->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_TXID_INPUT,
                $inputState,
                'tg-usdt-input-state:'.hash('sha256', $state['order_public_id']),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderInstructions($action, $session->version, $inputState, false);
    }

    private function handleTxidInput(TelegramInteractionAction $action): void
    {
        $state = $this->inputStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnPaymentMethods($action, $state);

                return;
            }
            throw new RuntimeException('Telegram USDT TXID callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPaymentMethods($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }
        $txid = strtolower(trim($action->messageText));
        if (preg_match('/\A0x[a-f0-9]{64}\z/', $txid) !== 1) {
            $this->renderInstructions($action, $action->sessionVersion, $state, true);

            return;
        }
        $this->submitTxid($action, $state, $txid);
    }

    /** @param array<string,mixed> $state */
    private function submitTxid(TelegramInteractionAction $action, array $state, string $txid): void
    {
        $operationKey = hash('sha256', $action->requestKey);
        try {
            $this->database->connection()->transaction(function () use ($action, $state, $txid, $operationKey): void {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_SUBMITTING,
                    $state,
                    'tg-usdt-submit-claim:'.$operationKey,
                );
                $this->assertActor($action, $claim->userId);
                $submission = $this->usdt->submitTxidForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $state['usdt_authority_public_id'],
                    $txid,
                    $operationKey,
                );
                $safeState = [
                    'submission_public_id' => $submission->submissionPublicId,
                    'authority_public_id' => $submission->authorityPublicId,
                    'payment_intent_public_id' => $submission->paymentIntentPublicId,
                    'masked_txid' => $this->maskTxid($submission->txid),
                    'state' => $submission->state,
                ];
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_SUBMITTED,
                    $safeState,
                    'tg-usdt-submitted-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);
                $this->renderSubmitted($action, $session->version, $safeState);
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);
        } catch (DomainException|InvalidArgumentException) {
            try {
                $this->renderInstructions($action, $action->sessionVersion, $state, true);
            } catch (DomainException) {
                // Another accepted interaction already advanced this session.
            }
        }
    }

    private function handleSubmitted(TelegramInteractionAction $action): void
    {
        $this->submittedStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            throw new RuntimeException('Telegram submitted USDT callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    /** @param array<string,mixed> $state */
    private function returnPaymentMethods(
        TelegramInteractionAction $action,
        array $state,
        bool $unavailable = false,
        ?int $expectedVersion = null,
    ): void {
        foreach ([
            'payment_method_code', 'usdt_authority_public_id', 'usdt_payment_intent_public_id', 'usdt_amount_quote_public_id',
            'usdt_network', 'usdt_exact_amount', 'usdt_destination_address', 'usdt_expires_at',
        ] as $key) {
            unset($state[$key]);
        }
        $methodsState = $this->paymentMethodsStateFromPayload($state);
        try {
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
                'tg-usdt-methods-back:'.hash('sha256', $action->requestKey),
            );
            $this->assertActor($action, $session->userId);
            if ($unavailable) {
                $this->queueConfidential(
                    $action,
                    $this->translation('telegram.navigation.purchase.payment_methods.usdt_payment.unavailable', $this->locale($action->userId)),
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
                'tg-usdt-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':usdt-home',
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
    private function renderInstructions(TelegramInteractionAction $action, int $sessionVersion, array $state, bool $invalid): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, $invalid ? 'txid-invalid' : 'instructions');
        $expiry = new DateTimeImmutable($state['usdt_expires_at']);
        $this->queueConfidential(
            $action,
            $this->translation(
                'telegram.navigation.purchase.payment_methods.usdt_payment.'.($invalid ? 'txid_invalid' : 'instructions'),
                $this->locale($action->userId),
                [
                    'network' => $state['usdt_network'],
                    'amount' => $state['usdt_exact_amount'],
                    'address' => $state['usdt_destination_address'],
                    'expires_at' => $expiry->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i:s'),
                ],
            ),
            $invalid ? 'txid-invalid' : 'instructions',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param array<string,mixed> $state */
    private function renderSubmitted(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, 'submitted');
        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.purchase.payment_methods.usdt_payment.submitted', $this->locale($action->userId), [
                'submission_id' => $state['submission_public_id'],
                'txid' => $state['masked_txid'],
            ]),
            'submitted',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderPaymentMethods(TelegramInteractionAction $action, int $sessionVersion, TelegramCustomerPurchasePaymentMethodsDecision $decision): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($decision->methodCodes as $offset => $methodCode) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PAYMENT_METHOD_SELECT,
                ['method_code' => $methodCode],
                'tg-usdt-method-button:'.hash('sha256', $action->requestKey.':'.$methodCode),
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

    /** @param list<list<TelegramInlineCallbackButton>> $rows */
    private function appendBack(TelegramInteractionAction $action, int $sessionVersion, array &$rows, string $surface): void
    {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-usdt-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        )];
    }

    private function queueConfidential(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
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
            'tg-usdt-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-usdt:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /** @param array<string,mixed> $payload */
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
            throw new RuntimeException('Telegram USDT payment-method state is invalid.');
        }
        if ($keys === $discounted
            && (! $this->isUlid($payload['discount_consumption_public_id'] ?? null)
                || ! $this->isSha256($payload['discount_consumption_configuration_hash'] ?? null)
                || ! $this->isUlid($payload['promotion_resolution_public_id'] ?? null))) {
            throw new RuntimeException('Telegram USDT discounted payment state is invalid.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function selectedStateFromPayload(array $payload): array
    {
        if (($payload['payment_method_code'] ?? null) !== 'usdt_bep20') {
            throw new RuntimeException('Telegram USDT selected-method state is invalid.');
        }
        $state = $payload;
        unset($state['payment_method_code']);
        $state = $this->paymentMethodsStateFromPayload($state);
        $state['payment_method_code'] = 'usdt_bep20';

        return $state;
    }

    /** @param array<string,mixed> $payload */
    private function inputStateFromPayload(array $payload): array
    {
        foreach (['usdt_authority_public_id', 'usdt_payment_intent_public_id', 'usdt_amount_quote_public_id'] as $key) {
            if (! $this->isUlid($payload[$key] ?? null)) {
                throw new RuntimeException('Telegram USDT instruction identity is invalid.');
            }
        }
        if (($payload['usdt_network'] ?? null) !== 'BEP20'
            || ! is_string($payload['usdt_exact_amount'] ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', $payload['usdt_exact_amount']) !== 1
            || ! is_string($payload['usdt_destination_address'] ?? null)
            || preg_match('/\A0x[a-f0-9]{40}\z/', $payload['usdt_destination_address']) !== 1
            || ! is_string($payload['usdt_expires_at'] ?? null)) {
            throw new RuntimeException('Telegram USDT instruction state is invalid.');
        }
        try {
            new DateTimeImmutable($payload['usdt_expires_at']);
        } catch (\Exception $exception) {
            throw new RuntimeException('Telegram USDT expiry state is invalid.', previous: $exception);
        }
        $selected = $payload;
        foreach (['usdt_authority_public_id', 'usdt_payment_intent_public_id', 'usdt_amount_quote_public_id', 'usdt_network', 'usdt_exact_amount', 'usdt_destination_address', 'usdt_expires_at'] as $key) {
            unset($selected[$key]);
        }
        $this->selectedStateFromPayload($selected);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function submittedStateFromPayload(array $payload): array
    {
        if (count($payload) !== 5
            || ! $this->isUlid($payload['submission_public_id'] ?? null)
            || ! $this->isUlid($payload['authority_public_id'] ?? null)
            || ! $this->isUlid($payload['payment_intent_public_id'] ?? null)
            || ! is_string($payload['masked_txid'] ?? null)
            || preg_match('/\A0x[a-f0-9]{8}…[a-f0-9]{8}\z/u', $payload['masked_txid']) !== 1
            || ($payload['state'] ?? null) !== 'submitted') {
            throw new RuntimeException('Telegram submitted USDT state is invalid.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function paymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code'] || ! is_string($payload['method_code'] ?? null)) {
            throw new RuntimeException('Telegram USDT method callback payload is invalid.');
        }

        return $payload['method_code'];
    }

    private function methodLabel(string $methodCode, string $locale, int $number): string
    {
        if (in_array($methodCode, ['wallet', 'card_to_card', 'gift_card', 'usdt_bep20', 'zarinpal', 'nowpayments'], true)) {
            return $this->translation('telegram.navigation.purchase.payment_methods.methods.'.$methodCode, $locale);
        }

        return $this->translation('telegram.navigation.purchase.payment_methods.methods.other', $locale, ['number' => $number]);
    }

    private function maskTxid(string $txid): string
    {
        return substr($txid, 0, 10).'…'.substr($txid, -8);
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
            throw new RuntimeException('Telegram USDT translation is invalid.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram USDT session actor binding is invalid.');
        }
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
