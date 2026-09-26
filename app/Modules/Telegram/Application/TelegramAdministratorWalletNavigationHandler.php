<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Wallet\Application\AdministratorWalletCorrectionPreview;
use App\Modules\Wallet\Application\AdministratorWalletOperationsService;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramAdministratorWalletNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.customer.wallet';

    private const STATE_MENU = 'admin_customer_wallet_menu';

    private const STATE_REFUND_LIST = 'admin_customer_wallet_refund_list';

    private const STATE_REFUND_REASON = 'admin_customer_wallet_refund_reason';

    private const STATE_CORRECTION_DIRECTION = 'admin_customer_wallet_correction_direction';

    private const STATE_CORRECTION_AMOUNT = 'admin_customer_wallet_correction_amount';

    private const STATE_CORRECTION_REASON = 'admin_customer_wallet_correction_reason';

    private const STATE_CORRECTION_CONFIRM = 'admin_customer_wallet_correction_confirm';

    private const ACTION_REFUND = 'navigation.admin.customer.wallet.refund';

    private const ACTION_REFUND_SELECT = 'navigation.admin.customer.wallet.refund.select';

    private const ACTION_CORRECTION = 'navigation.admin.customer.wallet.correction';

    private const ACTION_CORRECTION_CREDIT = 'navigation.admin.customer.wallet.correction.credit';

    private const ACTION_CORRECTION_DEBIT = 'navigation.admin.customer.wallet.correction.debit';

    private const ACTION_CORRECTION_EXECUTE = 'navigation.admin.customer.wallet.correction.execute';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private AdministratorWalletOperationsService $wallet,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_MENU,
            self::STATE_REFUND_LIST,
            self::STATE_REFUND_REASON,
            self::STATE_CORRECTION_DIRECTION,
            self::STATE_CORRECTION_AMOUNT,
            self::STATE_CORRECTION_REASON,
            self::STATE_CORRECTION_CONFIRM,
        ], true)) {
            return true;
        }

        return $action->sessionState === 'admin_customer_preview'
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_ENTRY
            && $action->callbackPayload === [];
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_customer_preview') {
            $this->enter($action);

            return;
        }

        match ($action->sessionState) {
            self::STATE_MENU => $this->handleMenu($action),
            self::STATE_REFUND_LIST => $this->handleRefundList($action),
            self::STATE_REFUND_REASON => $this->handleRefundReason($action),
            self::STATE_CORRECTION_DIRECTION => $this->handleCorrectionDirection($action),
            self::STATE_CORRECTION_AMOUNT => $this->handleCorrectionAmount($action),
            self::STATE_CORRECTION_REASON => $this->handleCorrectionReason($action),
            self::STATE_CORRECTION_CONFIRM => $this->handleCorrectionConfirm($action),
            default => throw new RuntimeException('Telegram administrator wallet navigation state is unsupported.'),
        };
    }

    private function enter(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);
        $this->resolveTarget($action, $selection);
        if (! $this->wallet->availableFor($action->userId)) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_MENU,
            ['selection' => $selection],
            'entry',
        );
        if ($session !== null) {
            $this->renderMenu($action, $session->version, $selection);
        }
    }

    private function handleMenu(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        if ($action->callbackAction === self::ACTION_REFUND) {
            $this->showRefundList($action, $selection);

            return;
        }
        if ($action->callbackAction === self::ACTION_CORRECTION) {
            $session = $this->transition(
                $action,
                self::STATE_CORRECTION_DIRECTION,
                ['selection' => $selection],
                'correction-direction',
            );
            if ($session !== null) {
                $this->renderCorrectionDirection($action, $session->version, $selection);
            }

            return;
        }

        throw new RuntimeException('Telegram administrator wallet menu callback is unsupported.');
    }

    private function handleRefundList(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->showMenu($action, $selection, 'refund-list-back');

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_REFUND_SELECT) {
            return;
        }

        $source = $action->callbackPayload['source'] ?? null;
        if (! is_string($source) || preg_match('/\A[0-9a-f]{40}\z/', $source) !== 1) {
            throw new RuntimeException('Telegram administrator wallet refund selection is invalid.');
        }

        $target = $this->resolveTarget($action, $selection);
        $candidate = $this->candidateByToken($action, $target->accountPublicId, $source);
        if ($candidate === null) {
            $this->showRefundList($action, $selection, 'refund_unavailable');

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_REFUND_REASON,
            ['selection' => $selection, 'source' => $source],
            'refund-reason',
        );
        if ($session !== null) {
            $this->renderRefundReason(
                $action,
                $session->version,
                $selection,
                $candidate->remainingIrr,
            );
        }
    }

    private function handleRefundReason(TelegramInteractionAction $action): void
    {
        [$selection, $source] = $this->refundPayload($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->showRefundList($action, $selection);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $target = $this->resolveTarget($action, $selection);
        $operationKey = hash('sha256', implode("\0", [
            'admin-wallet-refund',
            $action->sessionPublicId,
            $source,
            $action->requestKey,
        ]));

        try {
            $receipt = $this->wallet->refundFullRemaining(
                $action->userId,
                $target->accountPublicId,
                $source,
                $action->messageText,
                $operationKey,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException|InvalidArgumentException|RuntimeException) {
            $this->showRefundList($action, $selection, 'refund_unavailable');

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_MENU,
            ['selection' => $selection],
            'refund-complete',
        );
        if ($session !== null) {
            $this->renderRefundCompleted(
                $action,
                $session->version,
                $selection,
                $receipt->amountIrr,
            );
        }
    }

    private function handleCorrectionDirection(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->showMenu($action, $selection, 'correction-direction-back');

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        $direction = match ($action->callbackAction) {
            self::ACTION_CORRECTION_CREDIT => WalletCorrectionDirection::Credit,
            self::ACTION_CORRECTION_DEBIT => WalletCorrectionDirection::Debit,
            default => null,
        };
        if ($direction === null) {
            throw new RuntimeException('Telegram administrator wallet correction direction is unsupported.');
        }

        $session = $this->transition(
            $action,
            self::STATE_CORRECTION_AMOUNT,
            ['selection' => $selection, 'direction' => $direction->value],
            'correction-amount',
        );
        if ($session !== null) {
            $this->renderCorrectionAmount(
                $action,
                $session->version,
                $selection,
                $direction,
                null,
            );
        }
    }

    private function handleCorrectionAmount(TelegramInteractionAction $action): void
    {
        [$selection, $direction] = $this->correctionDirectionPayload($action->sessionPayload);
        if ($this->isBack($action)) {
            $session = $this->transition(
                $action,
                self::STATE_CORRECTION_DIRECTION,
                ['selection' => $selection],
                'correction-amount-back',
            );
            if ($session !== null) {
                $this->renderCorrectionDirection($action, $session->version, $selection);
            }

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
            $this->renderCorrectionAmount(
                $action,
                $action->sessionVersion,
                $selection,
                $direction,
                'invalid_amount',
            );

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_CORRECTION_REASON,
            [
                'selection' => $selection,
                'direction' => $direction->value,
                'amount_irr' => $amount,
            ],
            'correction-reason',
        );
        if ($session !== null) {
            $this->renderCorrectionReason(
                $action,
                $session->version,
                $selection,
                $direction,
                $amount,
            );
        }
    }

    private function handleCorrectionReason(TelegramInteractionAction $action): void
    {
        [$selection, $direction, $amount] = $this->correctionAmountPayload($action->sessionPayload);
        if ($this->isBack($action)) {
            $session = $this->transition(
                $action,
                self::STATE_CORRECTION_AMOUNT,
                ['selection' => $selection, 'direction' => $direction->value],
                'correction-reason-back',
            );
            if ($session !== null) {
                $this->renderCorrectionAmount(
                    $action,
                    $session->version,
                    $selection,
                    $direction,
                    null,
                );
            }

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $target = $this->resolveTarget($action, $selection);
        $operationKey = hash('sha256', implode("\0", [
            'admin-wallet-correction',
            $action->sessionPublicId,
            $direction->value,
            (string) $amount,
            $action->requestKey,
        ]));

        try {
            $preview = $this->wallet->previewCorrection(
                $action->userId,
                $target->accountPublicId,
                $direction,
                $amount,
                $action->messageText,
                $operationKey,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException|InvalidArgumentException|RuntimeException) {
            $this->showMenu($action, $selection, 'correction_unavailable');

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_CORRECTION_CONFIRM,
            [
                'selection' => $selection,
                'preview_id' => $preview->previewId,
                'confirmation_token' => $preview->confirmationToken,
                'approval_id' => $preview->approvalId ?? '',
                'approval_required' => $preview->approvalRequired ? 1 : 0,
                'operation_key' => $operationKey,
            ],
            'correction-confirm',
        );
        if ($session !== null) {
            $this->renderCorrectionConfirmation($action, $session->version, $preview);
        }
    }

    private function handleCorrectionConfirm(TelegramInteractionAction $action): void
    {
        [$selection, $previewId, $confirmationToken, $approvalId, $approvalRequired, $operationKey]
            = $this->correctionConfirmPayload($action->sessionPayload);

        if ($this->isBack($action)) {
            $this->showMenu($action, $selection, 'correction-confirm-back');

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_CORRECTION_EXECUTE
            || $action->callbackPayload !== []) {
            return;
        }

        $target = $this->resolveTarget($action, $selection);
        try {
            $receipt = $this->wallet->executeCorrection(
                $action->userId,
                $target->accountPublicId,
                $previewId,
                $confirmationToken,
                $approvalId,
                $operationKey,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException $exception) {
            if ($approvalRequired) {
                $this->renderCorrectionApprovalPending($action, $action->sessionVersion);

                return;
            }

            $this->showMenu($action, $selection, 'correction_unavailable');

            return;
        } catch (RuntimeException|InvalidArgumentException) {
            $this->showMenu($action, $selection, 'correction_stale');

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_MENU,
            ['selection' => $selection],
            'correction-complete',
        );
        if ($session !== null) {
            $this->renderCorrectionCompleted(
                $action,
                $session->version,
                $selection,
                $receipt->direction,
                $receipt->amount->amount,
                $receipt->availableBalanceAfter->amount,
            );
        }
    }

    private function showMenu(
        TelegramInteractionAction $action,
        string $selection,
        string $surface,
        ?string $messageKey = null,
    ): void {
        $session = $this->transition(
            $action,
            self::STATE_MENU,
            ['selection' => $selection],
            $surface,
        );
        if ($session !== null) {
            $this->renderMenu($action, $session->version, $selection, $messageKey);
        }
    }

    private function showRefundList(
        TelegramInteractionAction $action,
        string $selection,
        ?string $messageKey = null,
    ): void {
        $target = $this->resolveTarget($action, $selection);
        try {
            $candidates = $this->wallet->refundablePurchases(
                $action->userId,
                $target->accountPublicId,
                8,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_REFUND_LIST,
            ['selection' => $selection],
            'refund-list',
        );
        if ($session !== null) {
            $this->renderRefundList(
                $action,
                $session->version,
                $selection,
                $candidates,
                $messageKey,
            );
        }
    }

    private function renderMenu(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        ?string $messageKey = null,
    ): void {
        $target = $this->resolveTarget($action, $selection);
        $capabilities = $this->wallet->capabilities($action->userId);
        $locale = $this->locale($action);
        $text = $this->translation('telegram_admin_wallet.menu', $locale, [
            'customer' => $target->accountPublicId,
        ]);
        if ($messageKey !== null) {
            $text = $this->translation('telegram_admin_wallet.'.$messageKey, $locale)."\n\n".$text;
        }

        $rows = [];
        if ($capabilities->canRefund) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_REFUND,
                [],
                'menu-refund',
                $this->translation('telegram_admin_wallet.refund_button', $locale),
            )];
        }
        if ($capabilities->canCorrect) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_CORRECTION,
                [],
                'menu-correction',
                $this->translation('telegram_admin_wallet.correction_button', $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $sessionVersion, 'menu', $locale)];

        $this->queue(
            $action,
            $text,
            'menu',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /**
     * @param list<\App\Modules\Wallet\Application\AdministratorWalletRefundCandidate> $candidates
     */
    private function renderRefundList(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        array $candidates,
        ?string $messageKey,
    ): void {
        $locale = $this->locale($action);
        if ($candidates === []) {
            $text = $this->translation('telegram_admin_wallet.refund_none', $locale);
        } else {
            $text = $this->translation('telegram_admin_wallet.refund_list', $locale);
        }
        if ($messageKey !== null) {
            $text = $this->translation('telegram_admin_wallet.'.$messageKey, $locale)."\n\n".$text;
        }

        $rows = [];
        foreach ($candidates as $index => $candidate) {
            $label = $this->translation('telegram_admin_wallet.refund_item', $locale, [
                'number' => (string) ($index + 1),
                'amount' => $this->formatIrr($candidate->remainingIrr),
                'date' => $candidate->settledAt->format('Y-m-d'),
            ]);
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_REFUND_SELECT,
                ['source' => $candidate->selectionToken],
                'refund-select-'.$candidate->selectionToken,
                $label,
            )];
        }
        $rows[] = [$this->backButton($action, $sessionVersion, 'refund-list', $locale)];

        $this->queue($action, $text, 'refund-list', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function renderRefundReason(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        int $remainingIrr,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.refund_reason', $locale, [
                'amount' => $this->formatIrr($remainingIrr),
            ]),
            'refund-reason',
            new TelegramInlineKeyboardSnapshot([[
                $this->backButton($action, $sessionVersion, 'refund-reason', $locale),
            ]]),
        );
    }

    private function renderRefundCompleted(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        int $amountIrr,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.refund_completed', $locale, [
                'amount' => $this->formatIrr($amountIrr),
            ]),
            'refund-completed',
            $this->menuKeyboard($action, $sessionVersion, $locale),
        );
    }

    private function renderCorrectionDirection(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.correction_direction', $locale),
            'correction-direction',
            new TelegramInlineKeyboardSnapshot([
                [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_CORRECTION_CREDIT,
                    [],
                    'correction-credit',
                    $this->translation('telegram_admin_wallet.credit_button', $locale),
                )],
                [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_CORRECTION_DEBIT,
                    [],
                    'correction-debit',
                    $this->translation('telegram_admin_wallet.debit_button', $locale),
                )],
                [$this->backButton($action, $sessionVersion, 'correction-direction', $locale)],
            ]),
        );
    }

    private function renderCorrectionAmount(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        WalletCorrectionDirection $direction,
        ?string $messageKey,
    ): void {
        $locale = $this->locale($action);
        $text = $this->translation('telegram_admin_wallet.correction_amount', $locale, [
            'direction' => $this->directionLabel($direction, $locale),
        ]);
        if ($messageKey !== null) {
            $text = $this->translation('telegram_admin_wallet.'.$messageKey, $locale)."\n\n".$text;
        }
        $this->queue(
            $action,
            $text,
            'correction-amount',
            new TelegramInlineKeyboardSnapshot([[
                $this->backButton($action, $sessionVersion, 'correction-amount', $locale),
            ]]),
        );
    }

    private function renderCorrectionReason(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        WalletCorrectionDirection $direction,
        int $amountIrr,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.correction_reason', $locale, [
                'direction' => $this->directionLabel($direction, $locale),
                'amount' => $this->formatIrr($amountIrr),
            ]),
            'correction-reason',
            new TelegramInlineKeyboardSnapshot([[
                $this->backButton($action, $sessionVersion, 'correction-reason', $locale),
            ]]),
        );
    }

    private function renderCorrectionConfirmation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        AdministratorWalletCorrectionPreview $preview,
    ): void {
        $locale = $this->locale($action);
        $approval = $preview->approvalRequired
            ? $this->translation('telegram_admin_wallet.approval_required', $locale)
            : $this->translation('telegram_admin_wallet.approval_not_required', $locale);

        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.correction_confirm', $locale, [
                'direction' => $this->directionLabel($preview->direction, $locale),
                'amount' => $this->formatIrr($preview->amountIrr),
                'before' => $this->formatIrr($preview->availableBalanceBeforeIrr),
                'after' => $this->formatIrr($preview->availableBalanceAfterIrr),
                'approval' => $approval,
            ]),
            'correction-confirm',
            new TelegramInlineKeyboardSnapshot([
                [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_CORRECTION_EXECUTE,
                    [],
                    'correction-execute',
                    $this->translation('telegram_admin_wallet.execute_button', $locale),
                )],
                [$this->backButton($action, $sessionVersion, 'correction-confirm', $locale)],
            ]),
        );
    }

    private function renderCorrectionApprovalPending(
        TelegramInteractionAction $action,
        int $sessionVersion,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.approval_pending', $locale),
            'correction-approval-pending',
            new TelegramInlineKeyboardSnapshot([
                [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_CORRECTION_EXECUTE,
                    [],
                    'correction-execute-retry',
                    $this->translation('telegram_admin_wallet.retry_button', $locale),
                )],
                [$this->backButton($action, $sessionVersion, 'correction-approval-pending', $locale)],
            ]),
        );
    }

    private function renderCorrectionCompleted(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selection,
        WalletCorrectionDirection $direction,
        int $amountIrr,
        int $availableAfterIrr,
    ): void {
        $locale = $this->locale($action);
        $this->queue(
            $action,
            $this->translation('telegram_admin_wallet.correction_completed', $locale, [
                'direction' => $this->directionLabel($direction, $locale),
                'amount' => $this->formatIrr($amountIrr),
                'available' => $this->formatIrr($availableAfterIrr),
            ]),
            'correction-completed',
            $this->menuKeyboard($action, $sessionVersion, $locale),
        );
    }

    private function menuKeyboard(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
    ): TelegramInlineKeyboardSnapshot {
        return new TelegramInlineKeyboardSnapshot([
            [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_REFUND,
                [],
                'menu-refund-after',
                $this->translation('telegram_admin_wallet.refund_button', $locale),
            )],
            [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_CORRECTION,
                [],
                'menu-correction-after',
                $this->translation('telegram_admin_wallet.correction_button', $locale),
            )],
            [$this->backButton($action, $sessionVersion, 'menu-after', $locale)],
        ]);
    }

    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-admin-wallet-'.$surface.':'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId);
    }

    private function backButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
        string $locale,
    ): TelegramInlineCallbackButton {
        return $this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'back-'.$surface,
            $this->translation('telegram.navigation.buttons.back', $locale),
        );
    }

    private function transition(
        TelegramInteractionAction $action,
        string $state,
        array $payload,
        string $surface,
    ): ?TelegramInteractionSessionReceipt {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-admin-wallet-'.$surface.':'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram administrator wallet actor binding changed.');
        }

        return $session;
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-admin-wallet-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram administrator wallet actor binding changed.');
        }

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-wallet-home',
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
            null,
            $action->requestIpHash,
        ));
    }

    private function resolveTarget(
        TelegramInteractionAction $action,
        string $selection,
    ): TelegramAdministratorCustomerTarget {
        return $this->targets->resolve(
            $action->userId,
            $action->botId,
            $selection,
        );
    }

    private function candidateByToken(
        TelegramInteractionAction $action,
        string $customerPublicId,
        string $source,
    ): ?\App\Modules\Wallet\Application\AdministratorWalletRefundCandidate {
        foreach ($this->wallet->refundablePurchases($action->userId, $customerPublicId, 20) as $candidate) {
            if (hash_equals($candidate->selectionToken, $source)) {
                return $candidate;
            }
        }

        return null;
    }

    private function selectionFromPayload(array $payload): string
    {
        $selection = $payload['selection'] ?? null;
        if (! is_string($selection) || preg_match('/\A[0-9a-f]{40}\z/', $selection) !== 1) {
            throw new RuntimeException('Telegram administrator wallet target selection is invalid.');
        }

        return $selection;
    }

    /** @return array{0:string,1:string} */
    private function refundPayload(array $payload): array
    {
        $selection = $this->selectionFromPayload($payload);
        $source = $payload['source'] ?? null;
        if (! is_string($source) || preg_match('/\A[0-9a-f]{40}\z/', $source) !== 1) {
            throw new RuntimeException('Telegram administrator wallet refund session state is invalid.');
        }

        return [$selection, $source];
    }

    /** @return array{0:string,1:WalletCorrectionDirection} */
    private function correctionDirectionPayload(array $payload): array
    {
        $selection = $this->selectionFromPayload($payload);
        $direction = WalletCorrectionDirection::tryFrom((string) ($payload['direction'] ?? ''));
        if ($direction === null) {
            throw new RuntimeException('Telegram administrator wallet correction direction state is invalid.');
        }

        return [$selection, $direction];
    }

    /** @return array{0:string,1:WalletCorrectionDirection,2:int} */
    private function correctionAmountPayload(array $payload): array
    {
        [$selection, $direction] = $this->correctionDirectionPayload($payload);
        $amount = $payload['amount_irr'] ?? null;
        if (! is_int($amount) || $amount < 1) {
            throw new RuntimeException('Telegram administrator wallet correction amount state is invalid.');
        }

        return [$selection, $direction, $amount];
    }

    /** @return array{0:string,1:int,2:string,3:string|null,4:bool,5:string} */
    private function correctionConfirmPayload(array $payload): array
    {
        $selection = $this->selectionFromPayload($payload);
        $previewId = $payload['preview_id'] ?? null;
        $confirmationToken = $payload['confirmation_token'] ?? null;
        $approvalId = $payload['approval_id'] ?? null;
        $approvalRequired = $payload['approval_required'] ?? null;
        $operationKey = $payload['operation_key'] ?? null;

        if (! is_int($previewId)
            || $previewId < 1
            || ! is_string($confirmationToken)
            || preg_match('/\A[0-9a-f]{64}\z/', $confirmationToken) !== 1
            || ! is_string($approvalId)
            || ($approvalId !== '' && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $approvalId) !== 1)
            || ! is_int($approvalRequired)
            || ! in_array($approvalRequired, [0, 1], true)
            || ! is_string($operationKey)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1
            || (($approvalRequired === 1) !== ($approvalId !== ''))) {
            throw new RuntimeException('Telegram administrator wallet correction confirmation state is invalid.');
        }

        return [
            $selection,
            $previewId,
            $confirmationToken,
            $approvalId === '' ? null : $approvalId,
            $approvalRequired === 1,
            $operationKey,
        ];
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

    private function isBack(TelegramInteractionAction $action): bool
    {
        return ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])
            || $action->kind === TelegramInteractionActionKind::Back
            || preg_match('/\A\/back(?:@[A-Za-z0-9_]+)?\z/u', trim((string) $action->messageText)) === 1;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
    }

    private function locale(TelegramInteractionAction $action): string
    {
        return $this->resolveTarget(
            $action,
            $this->selectionFromPayload($action->sessionPayload),
        )->locale;
    }

    private function directionLabel(WalletCorrectionDirection $direction, string $locale): string
    {
        return $this->translation(
            $direction === WalletCorrectionDirection::Credit
                ? 'telegram_admin_wallet.direction_credit'
                : 'telegram_admin_wallet.direction_debit',
            $locale,
        );
    }

    private function formatIrr(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }

    private function queue(
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

        $this->delivery->send(
            $action->telegramUserId,
            $this->presentations->fromSource($source),
            'tg-admin-wallet-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-admin-wallet:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
            $keyboard,
        );
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === ''
            || $value === '['.$key.']'
            || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator wallet translation is unavailable.');
        }

        return $value;
    }
}
