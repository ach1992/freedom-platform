<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerWalletTopUpPayment;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramWalletTopUpNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.wallet.top_up';

    private const STATE_AMOUNT = 'wallet_top_up_amount';

    private const STATE_ACTIVE = 'wallet_top_up_active';

    private const ACTION_REFRESH = 'navigation.wallet.top_up.refresh';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramCustomerWalletTopUpPayment $topUps,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [self::STATE_AMOUNT, self::STATE_ACTIVE], true)) {
            return true;
        }

        return $action->sessionState === 'my_account'
            && $action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_ENTRY
            && $action->callbackPayload === [];
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'my_account') {
            $this->enter($action);

            return;
        }
        if ($action->sessionState === self::STATE_AMOUNT) {
            $this->handleAmount($action);

            return;
        }
        if ($action->sessionState === self::STATE_ACTIVE) {
            $this->handleActive($action);

            return;
        }

        throw new RuntimeException('Telegram wallet top-up navigation state is unsupported.');
    }

    private function enter(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_AMOUNT,
                [],
                'tg-wallet-topup-entry:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderAmountPrompt($action, $session->version, null);
    }

    private function handleAmount(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $amount = $this->normalizeAmount($action->messageText);
        if ($amount === null) {
            $this->renderAmountPrompt($action, $action->sessionVersion, 'invalid_amount');

            return;
        }

        $operationKey = hash('sha256', implode("\0", [
            $action->botId,
            (string) $action->updateId,
            $action->sessionPublicId,
            (string) $amount,
        ]));

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_ACTIVE,
                [
                    'amount_irr' => $amount,
                    'operation_key' => $operationKey,
                ],
                'tg-wallet-topup-active:'.$operationKey,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->prepareAndRender($action, $session->version, $session->payload);
    }

    private function handleActive(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_REFRESH
            || $action->callbackPayload !== []) {
            return;
        }

        $this->prepareAndRender($action, $action->sessionVersion, $action->sessionPayload);
    }

    /** @param array<string,mixed> $payload */
    private function prepareAndRender(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $payload,
    ): void {
        [$amount, $operationKey] = $this->activePayload($payload);

        try {
            $receipt = $this->topUps->prepareForSelf(
                $action->userId,
                $action->userId,
                $amount,
                $operationKey,
            );
        } catch (AuthorizationException|InvalidArgumentException|DomainException) {
            $this->resetToAmount($action, $sessionVersion, 'unavailable');

            return;
        } catch (RuntimeException) {
            $this->renderPending($action, $sessionVersion, $amount);

            return;
        }

        if ($receipt->amountIrr !== $amount) {
            throw new RuntimeException('Telegram wallet top-up amount identity changed.');
        }

        if ($receipt->state === 'verified') {
            $this->renderCompleted($action, $sessionVersion, $receipt->amountIrr);

            return;
        }
        if ($receipt->state === 'failed') {
            $this->resetToAmount($action, $sessionVersion, 'failed');

            return;
        }
        if ($receipt->redirectUrl !== null) {
            $this->renderRedirect($action, $sessionVersion, $receipt);

            return;
        }

        $this->renderPending($action, $sessionVersion, $receipt->amountIrr);
    }

    private function resetToAmount(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $messageKey,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $sessionVersion,
                self::STATE_AMOUNT,
                [],
                'tg-wallet-topup-reset:'.hash('sha256', $action->requestKey.':'.$messageKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderAmountPrompt($action, $session->version, $messageKey);
    }

    private function renderAmountPrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $messageKey,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram_wallet_top_up.amount_prompt', $locale);
        if ($messageKey !== null) {
            $text = $this->translation('telegram_wallet_top_up.'.$messageKey, $locale)."\n\n".$text;
        }

        $this->queue(
            $action,
            $text,
            'amount',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, 'amount')]]),
        );
    }

    private function renderRedirect(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramCustomerWalletTopUpRedirect $receipt,
    ): void {
        if ($receipt->redirectUrl === null) {
            throw new RuntimeException('Wallet top-up redirect URL is unavailable.');
        }
        $locale = $this->locale($action->userId);
        $refresh = $this->refreshButton($action, $sessionVersion, 'redirect');

        $this->queue(
            $action,
            $this->translation('telegram_wallet_top_up.redirect', $locale, [
                'amount' => $this->formatIrr($receipt->amountIrr),
            ]),
            'redirect',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineHttpsUrlButton(
                    $this->translation('telegram_wallet_top_up.pay_button', $locale),
                    $receipt->redirectUrl,
                    TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
                )],
                [$refresh],
                [$this->backButton($action, $sessionVersion, 'redirect')],
            ]),
        );
    }

    private function renderPending(
        TelegramInteractionAction $action,
        int $sessionVersion,
        int $amountIrr,
    ): void {
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation('telegram_wallet_top_up.pending', $locale, [
                'amount' => $this->formatIrr($amountIrr),
            ]),
            'pending',
            new TelegramInlineKeyboardSnapshot([
                [$this->refreshButton($action, $sessionVersion, 'pending')],
                [$this->backButton($action, $sessionVersion, 'pending')],
            ]),
        );
    }

    private function renderCompleted(
        TelegramInteractionAction $action,
        int $sessionVersion,
        int $amountIrr,
    ): void {
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation('telegram_wallet_top_up.completed', $locale, [
                'amount' => $this->formatIrr($amountIrr),
            ]),
            'completed',
            new TelegramInlineKeyboardSnapshot([[$this->backButton($action, $sessionVersion, 'completed')]]),
        );
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
            'tg-wallet-topup-refresh:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton(
            $this->translation('telegram_wallet_top_up.refresh_button', $this->locale($action->userId)),
            $callback->publicId,
        );
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
            'tg-wallet-topup-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $this->locale($action->userId)),
            $callback->publicId,
        );
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-wallet-topup-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':wallet-topup-home',
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

    private function isBack(TelegramInteractionAction $action): bool
    {
        return ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])
            || preg_match('/\A\/back(?:@[A-Za-z0-9_]+)?\z/u', trim((string) $action->messageText)) === 1;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
    }

    /** @return array{0:int,1:string} */
    private function activePayload(array $payload): array
    {
        $amount = $payload['amount_irr'] ?? null;
        $operationKey = $payload['operation_key'] ?? null;
        if (! is_int($amount)
            || $amount < 1
            || ! is_string($operationKey)
            || preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new RuntimeException('Telegram wallet top-up session state is invalid.');
        }

        return [$amount, $operationKey];
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

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    private function assertActor(TelegramInteractionAction $action, int $userId): void
    {
        if ($userId !== $action->userId) {
            throw new RuntimeException('Telegram wallet top-up actor binding changed.');
        }
    }

    private function formatIrr(int $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }

    private function queue(
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
            $this->presentations->fromSource($source),
            'tg-wallet-topup-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-wallet-topup:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
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
            throw new RuntimeException('Telegram wallet top-up translation is unavailable.');
        }

        return $value;
    }
}
