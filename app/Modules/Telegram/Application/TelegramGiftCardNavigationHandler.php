<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseGiftCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded customer Gift Card navigation gateway.
 *
 * Raw Gift Card code is accepted only as the transient messageText argument to
 * submitCodeForSelf(). It is never copied into session/callback/presentation
 * state. All durable presentation fields are masked/non-secret projections.
 */
final readonly class TelegramGiftCardNavigationHandler
{
    private const STATE_PAYMENT_METHODS = 'purchase_payment_methods';

    private const STATE_PAYMENT_METHOD_SELECTING = 'purchase_payment_method_selecting';

    private const STATE_GIFT_CARD_TYPE = 'purchase_gift_card_type';

    private const STATE_GIFT_CARD_FACE_VALUE = 'purchase_gift_card_face_value';

    private const STATE_GIFT_CARD_CODE_INPUT = 'purchase_gift_card_code_input';

    private const STATE_GIFT_CARD_SUBMITTED = 'purchase_gift_card_submitted';

    private const STATE_GIFT_CARD_SUBMITTING = 'purchase_gift_card_submitting';

    private const ACTION_PAYMENT_METHOD_SELECT = 'navigation.purchase.payment_method.select';

    private const ACTION_GIFT_CARD_TYPE_SELECT = 'navigation.purchase.gift_card.type.select';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private Translator $translator,
        private ConfidentialTelegramPresentationFactory $confidentialPresentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerPurchasePaymentMethods $paymentMethods,
        private TelegramCustomerPurchaseOrder $orders,
        private TelegramCustomerPurchaseGiftCardPayment $giftCards,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_GIFT_CARD_TYPE,
            self::STATE_GIFT_CARD_FACE_VALUE,
            self::STATE_GIFT_CARD_CODE_INPUT,
            self::STATE_GIFT_CARD_SUBMITTED,
        ], true)) {
            return true;
        }

        return $action->sessionState === self::STATE_PAYMENT_METHODS
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_PAYMENT_METHOD_SELECT
            && ($action->callbackPayload['method_code'] ?? null) === 'gift_card';
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === self::STATE_PAYMENT_METHODS) {
            $method = $this->paymentMethodFromPayload($action->callbackPayload);
            if ($method !== 'gift_card') {
                throw new RuntimeException('Telegram Gift Card router received a non-Gift-Card selection.');
            }
            $this->selectGiftCardAndShowTypes($action);

            return;
        }
        if ($action->sessionState === self::STATE_GIFT_CARD_TYPE) {
            $this->handleType($action);

            return;
        }
        if ($action->sessionState === self::STATE_GIFT_CARD_FACE_VALUE) {
            $this->handleFaceValue($action);

            return;
        }
        if ($action->sessionState === self::STATE_GIFT_CARD_CODE_INPUT) {
            $this->handleCodeInput($action);

            return;
        }
        if ($action->sessionState === self::STATE_GIFT_CARD_SUBMITTED) {
            $this->handleSubmitted($action);

            return;
        }

        throw new RuntimeException('Telegram Gift Card navigation state is unsupported.');
    }

    private function handleType(TelegramInteractionAction $action): void
    {
        $state = $this->selectedStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_GIFT_CARD_TYPE_SELECT) {
                [$typeCode, $configurationHash] = $this->giftCardTypeFromPayload($action->callbackPayload);
                $this->selectType($action, $state, $typeCode, $configurationHash);

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnPaymentMethods($action, $state);

                return;
            }

            throw new RuntimeException('Telegram Gift Card type callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnPaymentMethods($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleFaceValue(TelegramInteractionAction $action): void
    {
        $state = $this->typeStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnTypes($action, $state);

                return;
            }
            throw new RuntimeException('Telegram Gift Card face-value callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnTypes($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $faceValue = $this->positiveFaceValue($action->messageText);
        if ($faceValue === null) {
            $this->renderFaceValue($action, $action->sessionVersion, $state, true);

            return;
        }
        $codeState = $state;
        $codeState['gift_card_claimed_face_value'] = $faceValue;
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_GIFT_CARD_CODE_INPUT,
                $codeState,
                'tg-gift-code-state:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderCode($action, $session->version, $codeState, false);
    }

    private function handleCodeInput(TelegramInteractionAction $action): void
    {
        $state = $this->codeStateFromPayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnFaceValue($action, $state);

                return;
            }
            throw new RuntimeException('Telegram Gift Card code callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnFaceValue($action, $state);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText !== null) {
            $this->submitCode($action, $state, $action->messageText);
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
            throw new RuntimeException('Telegram submitted Gift Card callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function selectGiftCardAndShowTypes(TelegramInteractionAction $action): void
    {
        $state = $this->paymentMethodsStateFromPayload($action->sessionPayload);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram Gift Card selection requires a pre-payment Order.');
        }
        $operationKey = hash('sha256', $action->requestKey);

        try {
            [$session, $types] = $this->database->connection()->transaction(function () use ($action, $state, $operationKey): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_PAYMENT_METHOD_SELECTING,
                    $state,
                    'tg-gift-select-claim:'.$operationKey,
                );
                $this->assertActor($action, $claim->userId);
                $this->orders->currentForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                );
                $selection = $this->paymentMethods->selectForSelf(
                    $action->userId,
                    $action->userId,
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    'gift_card',
                );
                if ($selection->methodCode !== 'gift_card') {
                    throw new AuthorizationException('Telegram Gift Card payment selection is unavailable.');
                }
                $selectedState = $state;
                $selectedState['payment_method_code'] = 'gift_card';
                $types = $this->giftCards->availableTypesForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                );
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_GIFT_CARD_TYPE,
                    $selectedState,
                    'tg-gift-type-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);

                return [$session, $types];
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->renderTypes($action, $session->version, $types);
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function selectType(TelegramInteractionAction $action, array $state, string $typeCode, string $configurationHash): void
    {
        $operationKey = hash('sha256', $action->requestKey);
        try {
            [$session, $selected] = $this->database->connection()->transaction(function () use ($action, $state, $typeCode, $configurationHash, $operationKey): array {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_GIFT_CARD_SUBMITTING,
                    $state,
                    'tg-gift-type-claim:'.$operationKey,
                );
                $this->assertActor($action, $claim->userId);
                $types = $this->giftCards->availableTypesForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                );
                $selected = null;
                foreach ($types as $type) {
                    if (hash_equals($type->typeCode, $typeCode) && hash_equals($type->configurationHash, $configurationHash)) {
                        $selected = $type;
                        break;
                    }
                }
                if (! $selected instanceof TelegramCustomerPurchaseGiftCardType) {
                    throw new AuthorizationException('Telegram Gift Card type selection is stale.');
                }
                $faceState = $state;
                $faceState['gift_card_type_code'] = $selected->typeCode;
                $faceState['gift_card_type_configuration_hash'] = $selected->configurationHash;
                $faceState['gift_card_face_currency'] = $selected->faceCurrency;
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_GIFT_CARD_FACE_VALUE,
                    $faceState,
                    'tg-gift-face-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);

                return [$session, $selected];
            }, 3);
        } catch (AuthorizationException) {
            $this->returnPaymentMethods($action, $state);

            return;
        } catch (DomainException) {
            return;
        }

        $this->renderFaceValue($action, $session->version, [
            ...$state,
            'gift_card_type_code' => $selected->typeCode,
            'gift_card_type_configuration_hash' => $selected->configurationHash,
            'gift_card_face_currency' => $selected->faceCurrency,
        ], false);
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_face_currency:string,gift_card_claimed_face_value:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function submitCode(TelegramInteractionAction $action, array $state, string $code): void
    {
        $operationKey = hash('sha256', $action->requestKey);
        try {
            $this->database->connection()->transaction(function () use ($action, $state, $code, $operationKey): void {
                $claim = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_GIFT_CARD_SUBMITTING,
                    $state,
                    'tg-gift-submit-claim:'.$operationKey,
                );
                $this->assertActor($action, $claim->userId);
                $submission = $this->giftCards->submitCodeForSelf(
                    $action->userId,
                    $action->userId,
                    $state['order_public_id'],
                    $state['quote_public_id'],
                    $state['quote_configuration_hash'],
                    $state['payment_decision_public_id'],
                    $state['payment_decision_configuration_hash'],
                    $state['gift_card_type_code'],
                    $state['gift_card_type_configuration_hash'],
                    $state['gift_card_claimed_face_value'],
                    $code,
                    $operationKey,
                );
                $safeState = [
                    'submission_public_id' => $submission->submissionPublicId,
                    'payment_intent_public_id' => $submission->paymentIntentPublicId,
                    'review_public_id' => $submission->reviewPublicId,
                    'gift_card_type_code' => $submission->typeCode,
                    'masked_code' => $submission->maskedCode,
                    'claimed_face_value' => $submission->claimedFaceValue,
                    'claimed_currency' => $submission->claimedCurrency,
                ];
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $claim->version,
                    self::STATE_GIFT_CARD_SUBMITTED,
                    $safeState,
                    'tg-gift-submitted-state:'.$operationKey,
                );
                $this->assertActor($action, $session->userId);
                $this->renderSubmitted($action, $session->version, $submission);
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);
        } catch (DomainException|InvalidArgumentException) {
            try {
                $this->renderCode($action, $action->sessionVersion, $state, true);
            } catch (DomainException) {
                // A concurrent accepted interaction already advanced the secret-input session.
            }
        }
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function returnPaymentMethods(TelegramInteractionAction $action, array $state): void
    {
        $methodsState = $state;
        unset($methodsState['payment_method_code']);
        try {
            [$session, $decision] = $this->database->connection()->transaction(function () use ($action, $methodsState): array {
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
                    'tg-gift-methods-back:'.hash('sha256', $action->requestKey),
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

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_face_currency:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function returnTypes(TelegramInteractionAction $action, array $state): void
    {
        $selectedState = $state;
        unset($selectedState['gift_card_type_code'], $selectedState['gift_card_type_configuration_hash'], $selectedState['gift_card_face_currency']);
        try {
            [$session, $types] = $this->database->connection()->transaction(function () use ($action, $selectedState): array {
                $types = $this->giftCards->availableTypesForSelf(
                    $action->userId,
                    $action->userId,
                    $selectedState['order_public_id'],
                    $selectedState['quote_public_id'],
                    $selectedState['quote_configuration_hash'],
                    $selectedState['payment_decision_public_id'],
                    $selectedState['payment_decision_configuration_hash'],
                );
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $action->sessionVersion,
                    self::STATE_GIFT_CARD_TYPE,
                    $selectedState,
                    'tg-gift-types-back:'.hash('sha256', $action->requestKey),
                );
                $this->assertActor($action, $session->userId);

                return [$session, $types];
            }, 3);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException) {
            return;
        }
        $this->renderTypes($action, $session->version, $types);
    }

    /**
     * @param  array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_face_currency:string,gift_card_claimed_face_value:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}  $state
     */
    private function returnFaceValue(TelegramInteractionAction $action, array $state): void
    {
        $faceState = $state;
        unset($faceState['gift_card_claimed_face_value']);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_GIFT_CARD_FACE_VALUE,
                $faceState,
                'tg-gift-face-back:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderFaceValue($action, $session->version, $faceState, false);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-gift-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':gift-home',
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

    /** @param list<TelegramCustomerPurchaseGiftCardType> $types */
    private function renderTypes(TelegramInteractionAction $action, int $sessionVersion, array $types): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($types as $offset => $type) {
            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_GIFT_CARD_TYPE_SELECT,
                ['type_code' => $type->typeCode, 'type_configuration_hash' => $type->configurationHash],
                'tg-gift-type-button:'.hash('sha256', $action->requestKey.':'.$type->typeCode.':'.$type->configurationHash),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.type_button', $locale, [
                    'name' => $type->displayName,
                    'currency' => $type->faceCurrency,
                ]),
                $callback->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
            $items[] = $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.type_item', $locale, [
                'number' => $offset + 1,
                'name' => $type->displayName,
                'brand' => $type->brand,
                'region' => $type->region ?? $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.region_any', $locale),
                'currency' => $type->faceCurrency,
            ]);
        }
        $this->appendBack($action, $sessionVersion, $rows, 'types');
        $text = $types === []
            ? $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.no_types', $locale)
            : $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.types', $locale, ['items' => implode("\n\n", $items)]);
        $this->queueConfidential($action, $text, 'types', new TelegramInlineKeyboardSnapshot($rows));
    }

    /**
     * @param  array{gift_card_type_code:string,gift_card_face_currency:string}  $state
     */
    private function renderFaceValue(TelegramInteractionAction $action, int $sessionVersion, array $state, bool $invalid): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, $invalid ? 'face-invalid' : 'face');
        $locale = $this->locale($action->userId);
        $this->queueConfidential(
            $action,
            $this->translation(
                'telegram.navigation.purchase.payment_methods.gift_card_payment.'.($invalid ? 'face_value_invalid' : 'face_value_prompt'),
                $locale,
                ['type' => $state['gift_card_type_code'], 'currency' => $state['gift_card_face_currency']],
            ),
            $invalid ? 'face-invalid' : 'face',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /**
     * @param  array{gift_card_type_code:string,gift_card_face_currency:string,gift_card_claimed_face_value:int}  $state
     */
    private function renderCode(TelegramInteractionAction $action, int $sessionVersion, array $state, bool $invalid): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, $invalid ? 'code-invalid' : 'code');
        $locale = $this->locale($action->userId);
        $this->queueConfidential(
            $action,
            $this->translation(
                'telegram.navigation.purchase.payment_methods.gift_card_payment.'.($invalid ? 'code_invalid' : 'code_prompt'),
                $locale,
                [
                    'type' => $state['gift_card_type_code'],
                    'face_value' => number_format($state['gift_card_claimed_face_value']),
                    'currency' => $state['gift_card_face_currency'],
                ],
            ),
            $invalid ? 'code-invalid' : 'code',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderSubmitted(TelegramInteractionAction $action, int $sessionVersion, TelegramCustomerPurchaseGiftCardSubmission $submission): void
    {
        $rows = [];
        $this->appendBack($action, $sessionVersion, $rows, 'submitted');
        $locale = $this->locale($action->userId);
        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.purchase.payment_methods.gift_card_payment.pending_manual_review', $locale, [
                'submission_id' => $submission->submissionPublicId,
                'type' => $submission->typeCode,
                'masked_code' => $submission->maskedCode,
                'face_value' => number_format($submission->claimedFaceValue),
                'currency' => $submission->claimedCurrency,
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
                'tg-gift-method-button:'.hash('sha256', $action->requestKey.':'.$methodCode),
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
            'tg-gift-back:'.hash('sha256', $action->requestKey.':'.$surface),
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
            'tg-gift-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-gift:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id?:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function paymentMethodsStateFromPayload(array $payload): array
    {
        if (! is_string($payload['payment_decision_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_decision_public_id']) !== 1
            || ! is_string($payload['payment_decision_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['payment_decision_configuration_hash']) !== 1
            || (isset($payload['order_public_id']) && (! is_string($payload['order_public_id']) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['order_public_id']) !== 1))) {
            throw new RuntimeException('Telegram Gift Card payment-method state is invalid.');
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
     * @param array<string,mixed> $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function selectedStateFromPayload(array $payload): array
    {
        if (($payload['payment_method_code'] ?? null) !== 'gift_card') {
            throw new RuntimeException('Telegram Gift Card selected-method state is invalid.');
        }
        $methods = $payload;
        unset($methods['payment_method_code']);
        $state = $this->paymentMethodsStateFromPayload($methods);
        if (! isset($state['order_public_id'])) {
            throw new RuntimeException('Telegram Gift Card selected-method Order is unavailable.');
        }
        $state['payment_method_code'] = 'gift_card';

        return $state;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_face_currency:string,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function typeStateFromPayload(array $payload): array
    {
        if (! is_string($payload['gift_card_type_code'] ?? null)
            || preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $payload['gift_card_type_code']) !== 1
            || ! is_string($payload['gift_card_type_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['gift_card_type_configuration_hash']) !== 1
            || ! is_string($payload['gift_card_face_currency'] ?? null)
            || preg_match('/\A[A-Z]{3}\z/', $payload['gift_card_face_currency']) !== 1) {
            throw new RuntimeException('Telegram Gift Card type state is invalid.');
        }
        $selectedPayload = $payload;
        $typeCode = $selectedPayload['gift_card_type_code'];
        $hash = $selectedPayload['gift_card_type_configuration_hash'];
        $currency = $selectedPayload['gift_card_face_currency'];
        unset($selectedPayload['gift_card_type_code'], $selectedPayload['gift_card_type_configuration_hash'], $selectedPayload['gift_card_face_currency']);
        $state = $this->selectedStateFromPayload($selectedPayload);
        $state['gift_card_type_code'] = $typeCode;
        $state['gift_card_type_configuration_hash'] = $hash;
        $state['gift_card_face_currency'] = $currency;

        return $state;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{page:int,offering_selection:string,quote_public_id:string,quote_configuration_hash:string,payment_decision_public_id:string,payment_decision_configuration_hash:string,order_public_id:string,payment_method_code:string,gift_card_type_code:string,gift_card_type_configuration_hash:string,gift_card_face_currency:string,gift_card_claimed_face_value:int,discount_consumption_public_id?:string,discount_consumption_configuration_hash?:string,promotion_resolution_public_id?:string}
     */
    private function codeStateFromPayload(array $payload): array
    {
        if (! is_int($payload['gift_card_claimed_face_value'] ?? null) || $payload['gift_card_claimed_face_value'] < 1) {
            throw new RuntimeException('Telegram Gift Card code state is invalid.');
        }
        $typePayload = $payload;
        $faceValue = $typePayload['gift_card_claimed_face_value'];
        unset($typePayload['gift_card_claimed_face_value']);
        $state = $this->typeStateFromPayload($typePayload);
        $state['gift_card_claimed_face_value'] = $faceValue;

        return $state;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{submission_public_id:string,payment_intent_public_id:string,review_public_id:string,gift_card_type_code:string,masked_code:string,claimed_face_value:int,claimed_currency:string}
     */
    private function submittedStateFromPayload(array $payload): array
    {
        if (count($payload) !== 7
            || ! is_string($payload['submission_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['submission_public_id']) !== 1
            || ! is_string($payload['payment_intent_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['payment_intent_public_id']) !== 1
            || ! is_string($payload['review_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['review_public_id']) !== 1
            || ! is_string($payload['gift_card_type_code'] ?? null)
            || preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $payload['gift_card_type_code']) !== 1
            || ! is_string($payload['masked_code'] ?? null)
            || $payload['masked_code'] === ''
            || mb_strlen($payload['masked_code']) > 32
            || preg_match('/[\x00-\x1F\x7F]/', $payload['masked_code']) === 1
            || ! is_int($payload['claimed_face_value'] ?? null)
            || $payload['claimed_face_value'] < 1
            || ! is_string($payload['claimed_currency'] ?? null)
            || preg_match('/\A[A-Z]{3}\z/', $payload['claimed_currency']) !== 1) {
            throw new RuntimeException('Telegram submitted Gift Card state is invalid.');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
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
            throw new RuntimeException('Telegram Gift Card Quote state is invalid.');
        }
        if ($keys === $discountedKeys
            && (! is_string($payload['discount_consumption_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['discount_consumption_public_id']) !== 1
                || ! is_string($payload['discount_consumption_configuration_hash'] ?? null)
                || preg_match('/\A[0-9a-f]{64}\z/', $payload['discount_consumption_configuration_hash']) !== 1
                || ! is_string($payload['promotion_resolution_public_id'] ?? null)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $payload['promotion_resolution_public_id']) !== 1)) {
            throw new RuntimeException('Telegram Gift Card discounted Quote state is invalid.');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:string,1:string}
     */
    private function giftCardTypeFromPayload(array $payload): array
    {
        if (array_keys($payload) !== ['type_code', 'type_configuration_hash']
            || ! is_string($payload['type_code'] ?? null)
            || preg_match('/\A[A-Za-z0-9:_.-]{2,64}\z/', $payload['type_code']) !== 1
            || ! is_string($payload['type_configuration_hash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/', $payload['type_configuration_hash']) !== 1) {
            throw new RuntimeException('Telegram Gift Card type callback payload is invalid.');
        }

        return [$payload['type_code'], $payload['type_configuration_hash']];
    }

    /** @param array<string,mixed> $payload */
    private function paymentMethodFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['method_code']
            || ! is_string($payload['method_code'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_.-]{1,63}\z/', $payload['method_code']) !== 1) {
            throw new RuntimeException('Telegram Gift Card method callback payload is invalid.');
        }

        return $payload['method_code'];
    }

    private function positiveFaceValue(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/\A[1-9][0-9]{0,17}\z/', $value) !== 1) {
            return null;
        }
        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
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
            throw new RuntimeException('Telegram Gift Card translation is invalid.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram Gift Card session actor binding is invalid.');
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
