<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Promotions\Application\ReferralSelfSummary;
use App\Modules\Promotions\Application\ReferralSelfSummaryService;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Modules\Wallet\Application\WalletSelfBalanceSummary;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

final readonly class TelegramNavigationHandler implements TelegramInteractionHandler
{
    private const STATE_MY_ACCOUNT = 'my_account';

    private const ACTION_MY_ACCOUNT = 'navigation.my_account';

    private const ACTION_BACK = 'navigation.back';

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
    ) {}

    public function flow(): string
    {
        return TelegramNavigationEntryGateway::FLOW;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->kind === TelegramInteractionActionKind::Callback) {
                if ($action->callbackAction !== self::ACTION_MY_ACCOUNT || $action->callbackPayload !== []) {
                    throw new RuntimeException('Telegram home callback action is unsupported.');
                }

                $this->showMyAccount($action);

                return;
            }

            $this->renderHome($action, $action->sessionVersion, $action->requestKey);

            return;
        }

        if ($action->sessionState !== self::STATE_MY_ACCOUNT) {
            throw new RuntimeException('Telegram navigation session state is unsupported.');
        }

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

        $text = $this->accountText($customer, $wallet, $referral, $locale);
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
            'nav-account-delivery:'.$action->requestKey,
            $this->correlationId($action, 'account'),
            $keyboard,
        );
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
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_MY_ACCOUNT,
            [],
            'nav-home-account:'.$requestKey,
        );
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.my_account', $locale),
                $callback->publicId,
                TelegramInlineButtonStyle::Primary,
            ),
        ]]);
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
