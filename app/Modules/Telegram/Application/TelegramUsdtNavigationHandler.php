<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
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
 * Bounded direct-USDT Telegram journey.
 *
 * Session state contains only public payment identities and safe payment
 * instructions. Blockchain verification, settlement and provisioning remain
 * outside this navigation boundary.
 */
final readonly class TelegramUsdtNavigationHandler
{
    private const STATE_PAYMENT_METHODS = 'purchase_payment_methods';

    private const STATE_PAYMENT_METHOD_SELECTING = 'purchase_payment_method_selecting';

    private const STATE_USDT_TXID_INPUT = 'purchase_usdt_txid_input';

    private const STATE_USDT_SUBMITTING = 'purchase_usdt_submitting';

    private const STATE_USDT_SUBMITTED = 'purchase_usdt_submitted';

    private const ACTION_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';

    private const ACTION_BACK = 'navigation.back';

    private const METHOD_CODE = 'usdt_bep20';

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchaseUsdtPayment $usdt,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_USDT_TXID_INPUT,
            self::STATE_USDT_SUBMITTED,
        ], true)) {
            return true;
        }

        return $action->sessionState === self::STATE_PAYMENT_METHODS
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PAYMENT_METHOD_SELECT
            && ($action->callbackPayload['method_code'] ?? null) === self::METHOD_CODE;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_PAYMENT_METHODS) {
            $method = $this->paymentMethodFromPayload($action->callbackPayload);
            if ($method !== self::METHOD_CODE) {
                throw new RuntimeException('Telegram USDT router received a non-USDT selection.');
            }
            $this->initiate($action);

            return;
        }
        if ($action->sessionState === self::STATE_USDT_TXID_INPUT) {
            $this->handleTxidInput($action);

            return;
        }
        if ($action->sessionState === self::STATE_USDT_SUBMITTED) {
            $this->handleSubmitted($action);

            return;
        }

        throw new RuntimeException('Telegram USDT navigation state is unsupported.');
    }

    private function initiate(TelegramInteractionAction $action): void
    {
        $state = $this->paymentMethodsStateFromPayload($action->sessionPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram USDT selection requires a pre-payment Order.');
        }
        $operationKey = hash('sha256', $action->requestKey);

        try {
            [$session, $instructions] = $this->database->connection()->transaction(function () use ($action, $state, $operationKey): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PAYMENT_METHOD_SELECTING,
                    $state,
                    'tg-usdt-select-claim:'.$operationKey,
                );
                $this->assertActor($action, $claim->userId);

                $instructions = $this->usdt->initiateForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $operationKey,
                );
                $safeState = $state;
                $safeState['payment_method_code'] = self::METHOD_CODE;
                $safeState['usdt_authority_public_id'] = $instructions->authorityPublicId;
                $safeState['payment_intent_public_id'] = $instructions->paymentIntentPublicId;
                $safeState['usdt_amount_quote_public_id'] = $instructions->amountQuotePublicId;
                $safeState['usdt_network'] = $instructions->network;
                $safeState['usdt_destination_address'] = $instructions->destinationAddress;
                $safeState['usdt_exact_amount'] = $instructions->exactUsdt;
                $safeState['usdt_expires_at'] = $instructions->expiresAt->getTimestamp();

                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_USDT_TXID_INPUT,
                    $safeState,
                    'tg-usdt-input-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);

                return [$session, $instructions];
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->renderInstructions($action, $session->version, $this->inputStateFromPayload($session->payload), false);
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

        if (preg_match('/\A0x[0-9a-f]{64}\z/i', trim($action->messageText)) !== 1) {
            $this->renderInstructions($action, $action->sessionVersion, $state, true);

            return;
        }

        $this->submitTxid($action, $state, $action->messageText);
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,usdt_authority_public_id:string,payment_intent_public_id:string,usdt_amount_quote_public_id:string,usdt_network:string,usdt_destination_address:string,usdt_exact_amount:string,usdt_expires_at:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function submitTxid(TelegramInteractionAction $action, array $state, string $txid): void
    {
        $operationKey = hash('sha256', $action->requestKey);
        try {
            $this->database->connection()->transaction(function () use ($action, $state, $txid, $operationKey): void {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_USDT_SUBMITTING,
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
                    $state['payment_intent_public_id'],
                    $state['usdt_amount_quote_public_id'],
                    $txid,
                    $operationKey,
                );
                $safeState = [
                    'submission_public_id' => $submission->submissionPublicId,
                    'usdt_authority_public_id' => $submission->authorityPublicId,
                    'payment_intent_public_id' => $submission->paymentIntentPublicId,
                    'txid' => $submission->txid,
                ];
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_USDT_SUBMITTED,
                    $safeState,
                    'tg-usdt-submitted-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);
                $this->renderSubmitted($action, $session->version, $submission);
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);
        } catch (DomainException|InvalidArgumentException) {
            try {
                $this->renderInstructions($action, $action->sessionVersion, $state, true);
            } catch (DomainException) {
                // A concurrent accepted interaction already advanced this TXID-input session.
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

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,usdt_authority_public_id:string,payment_intent_public_id:string,usdt_amount_quote_public_id:string,usdt_network:string,usdt_destination_address:string,usdt_exact_amount:string,usdt_expires_at:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function returnPaymentMethods(TelegramInteractionAction $action, array $state): void
    {
        $methodsState = $state;
        unset(
            $methodsState['payment_method_code'],
            $methodsState['usdt_authority_public_id'],
            $methodsState['payment_intent_public_id'],
            $methodsState['usdt_amount_quote_public_id'],
            $methodsState['usdt_network'],
            $methodsState['usdt_destination_address'],
            $methodsState['usdt_exact_amount'],
            $methodsState['usdt_expires_at'],
        );
        try {
            [$session, $decision] = $this->database->connection()->transaction(function () use ($action, $methodsState): array {
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
                    $action->sessionVersion,
                    self::STATE_PAYMENT_METHODS,
                    $methodsState,
                    'tg-usdt-methods-back:'.hash('sha256', $action->requestKey),
                );
                $this->assertActor($action, $session->userId);

                return [$session, $decision];
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->renderPaymentMethods($action, $session->version, $decision);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
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

    /**
     * @param  array{usdt_network:string,usdt_destination_address:string,usdt_exact_amount:string,usdt_expires_at:int}  $state
     */
    private function renderInstructions(TelegramInteractionAction $action, int $sessionVersion, array $state, bool $invalid): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, $invalid ? 'txid-invalid' : 'instructions');
        $locale = $this->locale($action->userId);
        $this->queueConfidential(
            $action,
            $this->translation(
                'telegram.navigation.purchase.payment_methods.usdt_payment.'.($invalid ? 'txid_invalid' : 'instructions'),
                $locale,
                [
                    'network' => $state['usdt_network'],
                    'amount' => $state['usdt_exact_amount'],
                    'address' => $state['usdt_destination_address'],
                    'expires_at' => (new DateTimeImmutable('@'.$state['usdt_expires_at']))
                        ->setTimezone(new DateTimeZone('Asia/Tehran'))
                        ->format('Y-m-d H:i:s'),
                ],
            ),
            $invalid ? 'txid-invalid' : 'instructions',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderSubmitted(TelegramInteractionAction $action, int $sessionVersion, TelegramCustomerPurchaseUsdtSubmission $submission): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, 'submitted');
        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.purchase.payment_methods.usdt_payment.pending_verification', $this->locale($action->userId), [
                'submission_id' => $submission->submissionPublicId,
                'txid' => $submission->txid,
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
            $number = $offset + 1;
            $label = $this->methodLabel($methodCode, $locale, $number);
            $rows[] = [new TelegramInlineCallbackButton($label, $callback->publicId, TelegramInlineButtonStyle::Primary)];
            $items[] = $this->translation('telegram.navigation.purchase.payment_methods.item', $locale, [
                'number' => $number,
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
        $source = new class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private readonly string $text) {}

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

    /**
     * @param  array<string,mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id?:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function paymentMethodsStateFromPayload(array $payload): array
    {
        if (! is_string($payload['payment_decision_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_decision_public_id']) !== 1
            || ! is_string($payload['payment_decision_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['payment_decision_configuration_hash']) !== 1
            || (isset($payload['order_public_id']) && (! is_string($payload['order_public_id']) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['order_public_id']) !== 1))) {
            throw new RuntimeException('Telegram USDT payment-method state is invalid.');
        }
        $quotePayload = $payload;
        $decisionPublicId = $quotePayload['payment_decision_public_id'];
        $decisionConfigurationHash = $quotePayload['payment_decision_configuration_hash'];
        $orderPublicId = $quotePayload['order_public_id'] ?? null;
        unset($quotePayload['payment_decision_public_id'], $quotePayload['payment_decision_configuration_hash'], $quotePayload['order_public_id']);
        $state = $this->quoteStateFromPayload($quotePayload);
        $state['payment_decision_public_id'] = $decisionPublicId;
        $state['payment_decision_configuration_hash'] = $decisionConfigurationHash;
        if (is_string($orderPublicId)) {
            $state['order_public_id'] = $orderPublicId;
        }

        return $state;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,usdt_authority_public_id:string,payment_intent_public_id:string,usdt_amount_quote_public_id:string,usdt_network:string,usdt_destination_address:string,usdt_exact_amount:string,usdt_expires_at:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function inputStateFromPayload(array $payload): array
    {
        if (($payload['payment_method_code'] ?? null) !== self::METHOD_CODE
            || ! is_string($payload['usdt_authority_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['usdt_authority_public_id']) !== 1
            || ! is_string($payload['payment_intent_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_intent_public_id']) !== 1
            || ! is_string($payload['usdt_amount_quote_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['usdt_amount_quote_public_id']) !== 1
            || ($payload['usdt_network'] ?? null) !== 'BEP20'
            || ! is_string($payload['usdt_destination_address'] ?? null)
            || preg_match('/\A0x[a-f0-9]{40}\z/', $payload['usdt_destination_address']) !== 1
            || ! is_string($payload['usdt_exact_amount'] ?? null)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?\z/', $payload['usdt_exact_amount']) !== 1
            || ! is_int($payload['usdt_expires_at'] ?? null)
            || $payload['usdt_expires_at'] < 1) {
            throw new RuntimeException('Telegram USDT input state is invalid.');
        }
        $methodsPayload = $payload;
        $values = [
            'payment_method_code' => $methodsPayload['payment_method_code'],
            'usdt_authority_public_id' => $methodsPayload['usdt_authority_public_id'],
            'payment_intent_public_id' => $methodsPayload['payment_intent_public_id'],
            'usdt_amount_quote_public_id' => $methodsPayload['usdt_amount_quote_public_id'],
            'usdt_network' => $methodsPayload['usdt_network'],
            'usdt_destination_address' => $methodsPayload['usdt_destination_address'],
            'usdt_exact_amount' => $methodsPayload['usdt_exact_amount'],
            'usdt_expires_at' => $methodsPayload['usdt_expires_at'],
        ];
        unset(
            $methodsPayload['payment_method_code'],
            $methodsPayload['usdt_authority_public_id'],
            $methodsPayload['payment_intent_public_id'],
            $methodsPayload['usdt_amount_quote_public_id'],
            $methodsPayload['usdt_network'],
            $methodsPayload['usdt_destination_address'],
            $methodsPayload['usdt_exact_amount'],
            $methodsPayload['usdt_expires_at'],
        );
        $state = $this->paymentMethodsStateFromPayload($methodsPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram USDT input Order is unavailable.');
        }

        return [...$state, ...$values];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{submission_public_id:string,usdt_authority_public_id:string,payment_intent_public_id:string,txid:string}
     */
    private function submittedStateFromPayload(array $payload): array
    {
        if (count($payload) !== 4
            || ! is_string($payload['submission_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['submission_public_id']) !== 1
            || ! is_string($payload['usdt_authority_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['usdt_authority_public_id']) !== 1
            || ! is_string($payload['payment_intent_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_intent_public_id']) !== 1
            || ! is_string($payload['txid'] ?? null)
            || preg_match('/\A0x[a-f0-9]{64}\z/', $payload['txid']) !== 1) {
            throw new RuntimeException('Telegram submitted USDT state is invalid.');
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function quoteStateFromPayload(array $payload): array
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
            throw new RuntimeException('Telegram USDT Quote state is invalid.');
        }
        if ($keys === $discountedKeys
            && (! is_string($payload['discount_consumption_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['discount_consumption_public_id']) !== 1
                || ! is_string($payload['discount_consumption_configuration_hash'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['discount_consumption_configuration_hash']) !== 1
                || ! is_string($payload['promotion_resolution_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['promotion_resolution_public_id']) !== 1)) {
            throw new RuntimeException('Telegram USDT discounted Quote state is invalid.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function paymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code']
            || ! is_string($payload['method_code'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $payload['method_code']) !== 1) {
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
