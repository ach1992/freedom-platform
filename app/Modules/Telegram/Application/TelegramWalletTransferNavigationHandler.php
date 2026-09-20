<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Customers\Application\CustomerWalletTransferRecipient;
use App\Modules\Customers\Application\CustomerWalletTransferRecipientDiscoveryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramWalletTransferNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.wallet.transfer';

    private const STATE_RECIPIENT = 'wallet_transfer_recipient';
    private const STATE_AMOUNT = 'wallet_transfer_amount';
    private const STATE_PREPARING = 'wallet_transfer_preparing';
    private const STATE_CONFIRM = 'wallet_transfer_confirm';
    private const STATE_SUBMITTING = 'wallet_transfer_submitting';
    private const STATE_COMPLETED = 'wallet_transfer_completed';

    private const ACTION_CONFIRM = 'navigation.wallet.transfer.confirm';
    private const ACTION_CANCEL = 'navigation.wallet.transfer.cancel';
    private const ACTION_BACK = 'navigation.back';

    private const SUBMIT_CONFIRM = 'confirm';
    private const SUBMIT_CANCEL_HOME = 'cancel_home';
    private const SUBMIT_CANCEL_AMOUNT = 'cancel_amount';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private CustomerWalletTransferRecipientDiscoveryService $recipients,
        private TelegramCustomerWalletTransferService $transfers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_RECIPIENT,
            self::STATE_AMOUNT,
            self::STATE_PREPARING,
            self::STATE_CONFIRM,
            self::STATE_SUBMITTING,
            self::STATE_COMPLETED,
        ], true)) {
            return true;
        }

        return $action->sessionState === TelegramNavigationEntryGateway::STATE
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_ENTRY
            && $action->callbackPayload === [];
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            $this->enter($action);

            return;
        }
        if ($action->sessionState === self::STATE_RECIPIENT) {
            $this->handleRecipient($action);

            return;
        }
        if ($action->sessionState === self::STATE_AMOUNT) {
            $this->handleAmount($action);

            return;
        }
        if ($action->sessionState === self::STATE_PREPARING) {
            $this->resumePreparation($action);

            return;
        }
        if ($action->sessionState === self::STATE_CONFIRM) {
            $this->handleConfirm($action);

            return;
        }
        if ($action->sessionState === self::STATE_SUBMITTING) {
            $this->resumeSubmission($action);

            return;
        }
        if ($action->sessionState === self::STATE_COMPLETED) {
            $this->handleCompleted($action);

            return;
        }

        throw new RuntimeException('Telegram wallet transfer navigation state is unsupported.');
    }

    private function enter(TelegramInteractionAction $action): void
    {
        if (! $this->transfers->availableForSelf($action->userId, $action->userId)) {
            $this->returnHome($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_RECIPIENT,
                [],
                'tg-wallet-transfer-entry:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderRecipientPrompt($action, $session->version, null);
    }

    private function handleRecipient(TelegramInteractionAction $action): void
    {
        if ($this->isBackAction($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        try {
            $result = $this->recipients->searchForSelf(
                $action->userId,
                $action->userId,
                $action->botId,
                $action->messageText,
            );
        } catch (AuthorizationException|InvalidArgumentException) {
            $this->returnHome($action);

            return;
        }
        if (! $result->isMatched() || $result->recipient === null) {
            $this->renderRecipientPrompt(
                $action,
                $action->sessionVersion,
                $result->isAmbiguous() ? 'ambiguous' : 'not_found',
            );

            return;
        }

        $recipient = $result->recipient;
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AMOUNT,
                $this->recipientPayload($recipient),
                'tg-wallet-transfer-recipient:'.hash('sha256', $action->requestKey.':'.$recipient->accountPublicId),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderAmountPrompt($action, $session->version, $session->payload, null);
    }

    private function handleAmount(TelegramInteractionAction $action): void
    {
        $state = $this->recipientState($action->sessionPayload);
        if ($this->isBackAction($action)) {
            $this->returnRecipient($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $amount = $this->normalizeAmount($action->messageText);
        if ($amount === null) {
            $this->renderAmountPrompt($action, $action->sessionVersion, $state, 'invalid');

            return;
        }
        $state['amount_irr'] = $amount;
        $state['transfer_key'] = $this->transferKey($action, $state);

        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREPARING,
                $state,
                'tg-wallet-transfer-prepare-claim:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $claim->userId);
        $this->completePreparation($action, $claim->version, $state);
    }

    private function resumePreparation(TelegramInteractionAction $action): void
    {
        $state = $this->preparingState($action->sessionPayload);
        if ($this->isEntryCommand($action->messageText)) {
            $this->cancelPreparedIfPresent($action, $state);
            $this->returnHome($action);

            return;
        }
        $this->completePreparation($action, $action->sessionVersion, $state);
    }

    /** @param array<string,mixed> $state */
    private function completePreparation(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        try {
            $receipt = $this->transfers->prepareForSelf(
                $action->userId,
                $action->userId,
                $state['recipient_public_id'],
                $state['amount_irr'],
                $state['transfer_key'],
            );
        } catch (AuthorizationException) {
            $this->returnHomeFromVersion($action, $sessionVersion);

            return;
        } catch (DomainException|RuntimeException) {
            $this->returnAmountFromPreparation($action, $sessionVersion, $state, 'unavailable');

            return;
        }

        if ($receipt->status !== 'pending_confirmation'
            || ! hash_equals($receipt->recipientPublicId, $state['recipient_public_id'])
            || $receipt->amountIrr !== $state['amount_irr']) {
            throw new RuntimeException('Telegram wallet transfer preparation identity changed.');
        }

        $state['fee_irr'] = $receipt->feeIrr;
        $state['total_debit_irr'] = $receipt->totalDebitIrr;
        $state['confirmation_expires_at'] = $receipt->confirmationExpiresAt;
        $state['available_balance_irr'] = $receipt->availableBalanceAfterHoldIrr;

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_CONFIRM,
                $state,
                'tg-wallet-transfer-confirm-state:'.hash('sha256', $state['transfer_key']),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderConfirm($action, $session->version, $state);
    }

    private function handleConfirm(TelegramInteractionAction $action): void
    {
        $state = $this->confirmState($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])) {
            $this->beginSubmission($action, $state, self::SUBMIT_CANCEL_AMOUNT);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->beginSubmission($action, $state, self::SUBMIT_CANCEL_HOME);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }
        if ($action->callbackAction === self::ACTION_CONFIRM) {
            $this->beginSubmission($action, $state, self::SUBMIT_CONFIRM);

            return;
        }
        if ($action->callbackAction === self::ACTION_CANCEL) {
            $this->beginSubmission($action, $state, self::SUBMIT_CANCEL_HOME);

            return;
        }

        throw new RuntimeException('Telegram wallet transfer confirmation callback is unsupported.');
    }

    /** @param array<string,mixed> $state */
    private function beginSubmission(TelegramInteractionAction $action, array $state, string $submitAction): void
    {
        $state['submit_action'] = $submitAction;
        try {
            $claim = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SUBMITTING,
                $state,
                'tg-wallet-transfer-submit-claim:'.hash('sha256', $action->requestKey.':'.$submitAction),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $claim->userId);
        $this->completeSubmission($action, $claim->version, $state);
    }

    private function resumeSubmission(TelegramInteractionAction $action): void
    {
        $state = $this->submittingState($action->sessionPayload);
        $this->completeSubmission($action, $action->sessionVersion, $state);
    }

    /** @param array<string,mixed> $state */
    private function completeSubmission(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        if ($state['submit_action'] === self::SUBMIT_CONFIRM) {
            try {
                $receipt = $this->transfers->confirmForSelf(
                    $action->userId,
                    $action->userId,
                    $state['transfer_key'],
                    $this->confirmationKey($state['transfer_key']),
                    $this->correlationId($state['transfer_key']),
                );
            } catch (AuthorizationException) {
                $this->returnHomeFromVersion($action, $sessionVersion);

                return;
            }

            if ($receipt->status !== 'completed') {
                $this->returnAmountFromSubmission($action, $sessionVersion, $state, 'expired');

                return;
            }
            if ($receipt->amountIrr !== $state['amount_irr']
                || ! hash_equals($receipt->recipientPublicId, $state['recipient_public_id'])) {
                throw new RuntimeException('Telegram wallet transfer completion identity changed.');
            }
            unset($state['submit_action']);
            try {
                $session = $this->sessions->transition(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::STATE_COMPLETED,
                    $state,
                    'tg-wallet-transfer-completed-state:'.hash('sha256', $state['transfer_key']),
                );
            } catch (DomainException) {
                return;
            }
            $this->assertActor($action, $session->userId);
            $this->sendCompletedReceipts($action, $session->version, $state);

            return;
        }

        $this->transfers->cancelForSelf(
            $action->userId,
            $action->userId,
            $state['transfer_key'],
            'telegram user cancelled transfer',
        );

        if ($state['submit_action'] === self::SUBMIT_CANCEL_AMOUNT) {
            $this->returnAmountFromSubmission($action, $sessionVersion, $state, null);

            return;
        }
        $this->returnHomeFromVersion($action, $sessionVersion);
    }

    private function handleCompleted(TelegramInteractionAction $action): void
    {
        $state = $this->confirmState($action->sessionPayload);
        $this->sendCompletedReceipts($action, $action->sessionVersion, $state);

        if ($this->isBackAction($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            throw new RuntimeException('Telegram wallet transfer completed callback is unsupported.');
        }
    }

    /** @param array<string,mixed> $state */
    private function sendCompletedReceipts(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        $locale = $this->locale($action->userId);
        $recipient = $this->recipientLabel($state);
        $senderText = $this->translation('telegram_wallet_transfer.completed_sender', $locale, [
            'amount' => $this->formatIrr($state['amount_irr']),
            'fee' => $this->formatIrr($state['fee_irr']),
            'recipient' => $recipient,
        ]);
        $home = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-wallet-transfer-completed-home:'.hash('sha256', $state['transfer_key']),
        );
        $this->queueText(
            $action->telegramUserId,
            $senderText,
            'tg-wallet-transfer-receipt:sender:'.hash('sha256', $state['transfer_key']),
            'tg-wallet-transfer:sender:'.substr(hash('sha256', $state['transfer_key']), 0, 40),
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram_wallet_transfer.home', $locale),
                    $home->publicId,
                    TelegramInlineButtonStyle::Primary,
                ),
            ]]),
        );

        $sender = $this->customers->forSelf($action->userId, $action->userId);
        $recipientTelegramUserId = $this->telegramUserId($state['recipient_telegram_user_id']);
        $recipientText = $this->translation('telegram_wallet_transfer.completed_recipient', 'fa', [
            'amount' => $this->formatIrr($state['amount_irr']),
            'sender' => substr($sender->publicId, -6),
        ]);
        $this->queueText(
            $recipientTelegramUserId,
            $recipientText,
            'tg-wallet-transfer-receipt:recipient:'.hash('sha256', $state['transfer_key']),
            'tg-wallet-transfer:recipient:'.substr(hash('sha256', $state['transfer_key']), 0, 37),
        );
    }

    private function renderRecipientPrompt(TelegramInteractionAction $action, int $sessionVersion, ?string $error): void
    {
        $rows = [[$this->backButton($action, $sessionVersion, 'recipient')]];
        $key = $error === null ? 'recipient_prompt' : 'recipient_'.$error;
        $this->queueText(
            $action->telegramUserId,
            $this->translation('telegram_wallet_transfer.'.$key, $this->locale($action->userId)),
            'tg-wallet-transfer-delivery:'.hash('sha256', $action->requestKey.':recipient:'.($error ?? 'prompt')),
            'tg-wallet-transfer:recipient:'.substr(hash('sha256', $action->requestKey.':'.($error ?? 'prompt')), 0, 36),
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param array<string,mixed> $state */
    private function renderAmountPrompt(TelegramInteractionAction $action, int $sessionVersion, array $state, ?string $error): void
    {
        $rows = [[$this->backButton($action, $sessionVersion, 'amount')]];
        $key = $error === null ? 'amount_prompt' : 'amount_'.$error;
        $this->queueText(
            $action->telegramUserId,
            $this->translation('telegram_wallet_transfer.'.$key, $this->locale($action->userId), [
                'recipient' => $this->recipientLabel($state),
            ]),
            'tg-wallet-transfer-delivery:'.hash('sha256', $action->requestKey.':amount:'.($error ?? 'prompt')),
            'tg-wallet-transfer:amount:'.substr(hash('sha256', $action->requestKey.':'.($error ?? 'prompt')), 0, 39),
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /** @param array<string,mixed> $state */
    private function renderConfirm(TelegramInteractionAction $action, int $sessionVersion, array $state): void
    {
        $confirm = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CONFIRM,
            [],
            'tg-wallet-transfer-confirm-button:'.hash('sha256', $state['transfer_key']),
        );
        $cancel = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CANCEL,
            [],
            'tg-wallet-transfer-cancel-button:'.hash('sha256', $state['transfer_key']),
        );
        $rows = [
            [new TelegramInlineCallbackButton(
                $this->translation('telegram_wallet_transfer.confirm_button', $this->locale($action->userId)),
                $confirm->publicId,
                TelegramInlineButtonStyle::Primary,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram_wallet_transfer.cancel_button', $this->locale($action->userId)),
                $cancel->publicId,
            )],
            [$this->backButton($action, $sessionVersion, 'confirm')],
        ];
        $this->queueText(
            $action->telegramUserId,
            $this->translation('telegram_wallet_transfer.confirm', $this->locale($action->userId), [
                'recipient' => $this->recipientLabel($state),
                'amount' => $this->formatIrr($state['amount_irr']),
                'fee' => $this->formatIrr($state['fee_irr']),
                'total' => $this->formatIrr($state['total_debit_irr']),
                'available' => $this->formatIrr($state['available_balance_irr']),
                'expires_at' => $state['confirmation_expires_at'],
            ]),
            'tg-wallet-transfer-delivery:confirm:'.hash('sha256', $state['transfer_key']),
            'tg-wallet-transfer:confirm:'.substr(hash('sha256', $state['transfer_key']), 0, 40),
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function returnRecipient(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_RECIPIENT,
                [],
                'tg-wallet-transfer-recipient-back:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderRecipientPrompt($action, $session->version, null);
    }

    /** @param array<string,mixed> $state */
    private function returnAmountFromPreparation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        ?string $error,
    ): void {
        unset($state['amount_irr'], $state['transfer_key']);
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_AMOUNT,
                $this->recipientState($state),
                'tg-wallet-transfer-amount-after-prepare:'.hash('sha256', $action->requestKey.':'.($error ?? 'none')),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderAmountPrompt($action, $session->version, $session->payload, $error);
    }

    /** @param array<string,mixed> $state */
    private function returnAmountFromSubmission(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $state,
        ?string $error,
    ): void {
        foreach ([
            'amount_irr', 'transfer_key', 'fee_irr', 'total_debit_irr',
            'confirmation_expires_at', 'available_balance_irr', 'submit_action',
        ] as $key) {
            unset($state[$key]);
        }
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_AMOUNT,
                $this->recipientState($state),
                'tg-wallet-transfer-amount-after-submit:'.hash('sha256', $action->requestKey.':'.($error ?? 'none')),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderAmountPrompt($action, $session->version, $session->payload, $error);
    }

    /** @param array<string,mixed> $state */
    private function cancelPreparedIfPresent(TelegramInteractionAction $action, array $state): void
    {
        try {
            $this->transfers->cancelForSelf(
                $action->userId,
                $action->userId,
                $state['transfer_key'],
                'telegram user cancelled transfer',
            );
        } catch (DomainException|AuthorizationException|RuntimeException) {
            // No accepted prepared transfer is available to cancel.
        }
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $this->returnHomeFromVersion($action, $action->sessionVersion);
    }

    private function returnHomeFromVersion(TelegramInteractionAction $action, int $expectedVersion): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $expectedVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-wallet-transfer-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':wallet-transfer-home',
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

    private function backButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-wallet-transfer-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        );
    }

    private function queueText(
        int $telegramUserId,
        string $text,
        string $requestKey,
        string $correlationId,
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
            $telegramUserId,
            $this->presentations->fromSource($source),
            $requestKey,
            $correlationId,
            $keyboard,
        );
    }

    /** @return array<string,mixed> */
    private function recipientPayload(CustomerWalletTransferRecipient $recipient): array
    {
        return [
            'recipient_public_id' => $recipient->accountPublicId,
            'recipient_telegram_user_id' => $recipient->telegramUserId,
            'recipient_masked_username' => $recipient->maskedUsername,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function recipientState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['recipient_masked_username', 'recipient_public_id', 'recipient_telegram_user_id']
            || ! is_string($payload['recipient_public_id'] ?? null)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['recipient_public_id']) !== 1
            || ! is_string($payload['recipient_telegram_user_id'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $payload['recipient_telegram_user_id']) !== 1
            || (($payload['recipient_masked_username'] ?? null) !== null
                && ! is_string($payload['recipient_masked_username']))) {
            throw new RuntimeException('Telegram wallet transfer recipient state is invalid.');
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function preparingState(array $payload): array
    {
        if (! is_int($payload['amount_irr'] ?? null) || $payload['amount_irr'] < 1
            || ! is_string($payload['transfer_key'] ?? null)) {
            throw new RuntimeException('Telegram wallet transfer preparing state is invalid.');
        }
        $recipient = $payload;
        unset($recipient['amount_irr'], $recipient['transfer_key']);
        $this->recipientState($recipient);

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function confirmState(array $payload): array
    {
        foreach (['fee_irr', 'total_debit_irr', 'available_balance_irr'] as $key) {
            if (! is_int($payload[$key] ?? null) || $payload[$key] < 0) {
                throw new RuntimeException('Telegram wallet transfer confirmation state is invalid.');
            }
        }
        if (! is_string($payload['confirmation_expires_at'] ?? null)
            || trim($payload['confirmation_expires_at']) === '') {
            throw new RuntimeException('Telegram wallet transfer confirmation expiry is invalid.');
        }
        $preparing = $payload;
        unset($preparing['fee_irr'], $preparing['total_debit_irr'], $preparing['confirmation_expires_at'], $preparing['available_balance_irr']);
        $this->preparingState($preparing);

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function submittingState(array $payload): array
    {
        if (! is_string($payload['submit_action'] ?? null)
            || ! in_array($payload['submit_action'], [
                self::SUBMIT_CONFIRM,
                self::SUBMIT_CANCEL_HOME,
                self::SUBMIT_CANCEL_AMOUNT,
            ], true)) {
            throw new RuntimeException('Telegram wallet transfer submitting state is invalid.');
        }
        $confirm = $payload;
        unset($confirm['submit_action']);
        $this->confirmState($confirm);

        return $payload;
    }

    /** @param array<string,mixed> $state */
    private function transferKey(TelegramInteractionAction $action, array $state): string
    {
        return 'tg-wallet-transfer:'.substr(hash(
            'sha256',
            $action->sessionPublicId."\0".$state['recipient_public_id']."\0".(string) $state['amount_irr'],
        ), 0, 48);
    }

    private function confirmationKey(string $transferKey): string
    {
        return 'tg-wallet-confirm:'.substr(hash('sha256', $transferKey), 0, 48);
    }

    private function correlationId(string $transferKey): string
    {
        return 'tg-wallet:'.substr(hash('sha256', $transferKey), 0, 48);
    }

    private function normalizeAmount(string $input): ?int
    {
        $normalized = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ',' => '', '٬' => '',
        ]);
        if (preg_match('/\A[1-9][0-9]{0,18}\z/', $normalized) !== 1) {
            return null;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    /** @param array<string,mixed> $state */
    private function recipientLabel(array $state): string
    {
        $masked = $state['recipient_masked_username'] ?? null;

        return is_string($masked) && $masked !== ''
            ? '@'.$masked
            : '…'.substr((string) $state['recipient_public_id'], -6);
    }

    private function telegramUserId(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,18}\z/', $value) !== 1) {
            throw new RuntimeException('Telegram wallet transfer recipient chat identity is invalid.');
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new RuntimeException('Telegram wallet transfer recipient chat identity exceeds platform range.');
        }

        return (int) $value;
    }

    private function formatIrr(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
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
            throw new RuntimeException('Telegram wallet transfer translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram wallet transfer translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram wallet transfer session actor binding is invalid.');
        }
    }

    private function isBackAction(TelegramInteractionAction $action): bool
    {
        return $action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []);
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
