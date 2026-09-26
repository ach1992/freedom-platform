<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Identity\Application\Exceptions\InvalidOtpCode;
use App\Modules\Identity\Application\Exceptions\OtpChallengeExpired;
use App\Modules\Identity\Application\Exceptions\OtpChallengeInactive;
use App\Modules\Identity\Application\Exceptions\OtpChallengeNotFound;
use App\Modules\Identity\Application\Exceptions\OtpRateLimitExceeded;
use App\Modules\Identity\Application\Exceptions\OtpResendCooldownActive;
use App\Modules\Identity\Application\Exceptions\PhoneAlreadyAssigned;
use App\Modules\Identity\Application\Exceptions\PhoneVerificationMethodNotAllowed;
use App\Modules\Identity\Application\Exceptions\TelegramContactOwnershipMismatch;
use App\Modules\Identity\Application\Exceptions\TelegramIdentityNotFound;
use App\Modules\Identity\Application\OtpChallengeIssuer;
use App\Modules\Identity\Application\OtpChallengeVerifier;
use App\Modules\Identity\Application\OtpIssueRequest;
use App\Modules\Identity\Application\TelegramContactVerifier;
use App\Modules\Identity\Domain\IranianMobileNumber;
use App\Modules\Identity\Domain\PhoneVerificationPolicy;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramPhoneVerificationNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.phone_verification';

    private const STATE_METHOD = 'phone_verification_method';

    private const STATE_CONTACT = 'phone_verification_contact';

    private const STATE_SMS_PHONE = 'phone_verification_sms_phone';

    private const STATE_SMS_CODE = 'phone_verification_sms_code';

    private const ACTION_CONTACT = 'navigation.phone_verification.contact';

    private const ACTION_SMS = 'navigation.phone_verification.sms';

    private const ACTION_BACK = 'navigation.back';

    private const POLICY_VERSION = 1;

    public function __construct(
        private LocalizationResolver $localization,
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private CustomerAccountSummaryService $customers,
        private TelegramContactVerifier $contactVerifier,
        private OtpChallengeIssuer $otpIssuer,
        private OtpChallengeVerifier $otpVerifier,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if (in_array($action->sessionState, [
            self::STATE_METHOD,
            self::STATE_CONTACT,
            self::STATE_SMS_PHONE,
            self::STATE_SMS_CODE,
        ], true)) {
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

        match ($action->sessionState) {
            self::STATE_METHOD => $this->handleMethod($action),
            self::STATE_CONTACT => $this->handleContact($action),
            self::STATE_SMS_PHONE => $this->handleSmsPhone($action),
            self::STATE_SMS_CODE => $this->handleSmsCode($action),
            default => throw new RuntimeException('Telegram phone-verification navigation state is unsupported.'),
        };
    }

    private function enter(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, self::STATE_METHOD, [], 'entry');
        if ($session === null) {
            return;
        }

        $this->renderMethod($action, $session->version);
    }

    private function handleMethod(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        if ($action->callbackAction === self::ACTION_CONTACT) {
            $session = $this->transition($action, self::STATE_CONTACT, [], 'contact');
            if ($session !== null) {
                $this->renderContactPrompt($action);
            }

            return;
        }

        if ($action->callbackAction === self::ACTION_SMS) {
            $session = $this->transition($action, self::STATE_SMS_PHONE, [], 'sms');
            if ($session !== null) {
                $this->renderSmsPhonePrompt($action, $session->version, null);
            }

            return;
        }
    }

    private function handleContact(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageContact === null) {
            $this->renderContactPrompt($action, 'contact_invalid');

            return;
        }

        try {
            $this->contactVerifier->verify(
                $action->userId,
                $action->botId,
                $action->telegramUserId,
                $action->messageContact,
                PhoneVerificationPolicy::TelegramContactOnly,
                self::POLICY_VERSION,
                $this->correlation($action, 'contact'),
            );
        } catch (
            PhoneAlreadyAssigned|
            PhoneVerificationMethodNotAllowed|
            TelegramContactOwnershipMismatch|
            TelegramIdentityNotFound|
            InvalidArgumentException
        ) {
            $this->renderContactPrompt($action, 'contact_invalid');

            return;
        }

        $this->finish($action);
    }

    private function handleSmsPhone(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }
        if ($action->requestIpHash === null) {
            $this->renderSmsPhonePrompt($action, $action->sessionVersion, 'otp_unavailable');

            return;
        }

        try {
            $number = IranianMobileNumber::fromString($action->messageText);
            $issued = $this->otpIssuer->issue(new OtpIssueRequest(
                $action->userId,
                $action->telegramAccountId,
                $number,
                PhoneVerificationPolicy::SmsOtpOnly,
                self::POLICY_VERSION,
                'phone_verification',
                null,
                'tg-phone-otp-'.substr(hash('sha256', $action->requestKey), 0, 48),
                $this->correlation($action, 'otp'),
                $action->requestIpHash,
            ));
        } catch (OtpRateLimitExceeded|OtpResendCooldownActive) {
            $this->renderSmsPhonePrompt($action, $action->sessionVersion, 'otp_rate_limited');

            return;
        } catch (
            PhoneAlreadyAssigned|
            PhoneVerificationMethodNotAllowed|
            TelegramIdentityNotFound|
            InvalidArgumentException
        ) {
            $this->renderSmsPhonePrompt($action, $action->sessionVersion, 'invalid_phone');

            return;
        }

        try {
            $session = $this->sessions->transitionUntil(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SMS_CODE,
                ['challenge_id' => $issued->challengeId],
                'tg-phone-otp-code:'.hash('sha256', $action->requestKey.':'.$issued->challengeId),
                $issued->expiresAt,
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderSmsCodePrompt($action, $session->version, null);
    }

    private function handleSmsCode(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $challengeId = $action->sessionPayload['challenge_id'] ?? null;
        if (! is_string($challengeId)
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $challengeId) !== 1) {
            throw new RuntimeException('Telegram phone-verification OTP session state is invalid.');
        }

        try {
            $this->otpVerifier->verify(
                $action->userId,
                $challengeId,
                trim($action->messageText),
                $this->correlation($action, 'verify'),
            );
        } catch (InvalidOtpCode|InvalidArgumentException) {
            $this->renderSmsCodePrompt($action, $action->sessionVersion, 'otp_invalid');

            return;
        } catch (OtpChallengeExpired|OtpChallengeInactive|OtpChallengeNotFound) {
            $session = $this->transition($action, self::STATE_SMS_PHONE, [], 'otp-reset');
            if ($session !== null) {
                $this->renderSmsPhonePrompt($action, $session->version, 'otp_expired');
            }

            return;
        }

        $this->finish($action);
    }

    private function renderMethod(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $contact = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CONTACT,
            [],
            'tg-phone-method-contact:'.hash('sha256', $action->requestKey),
        );
        $sms = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_SMS,
            [],
            'tg-phone-method-sms:'.hash('sha256', $action->requestKey),
        );
        $back = $this->backCallback($action, $sessionVersion, 'method');

        $this->queue(
            $action,
            $this->translation('telegram_phone_verification.method_prompt', $locale),
            'method',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram_phone_verification.contact_button', $locale),
                    $contact->publicId,
                )],
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram_phone_verification.sms_button', $locale),
                    $sms->publicId,
                )],
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                )],
            ]),
        );
    }

    private function renderContactPrompt(
        TelegramInteractionAction $action,
        ?string $messageKey = null,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $messageKey === null
            ? $this->translation('telegram_phone_verification.contact_prompt', $locale)
            : $this->translation('telegram_phone_verification.'.$messageKey, $locale)
                ."\n\n".$this->translation('telegram_phone_verification.contact_prompt', $locale);

        $this->queue(
            $action,
            $text,
            'contact',
            new TelegramContactRequestKeyboardSnapshot(
                $this->translation('telegram_phone_verification.contact_request_button', $locale),
            ),
        );
    }

    private function renderSmsPhonePrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $messageKey,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram_phone_verification.sms_phone_prompt', $locale);
        if ($messageKey !== null) {
            $text = $this->translation('telegram_phone_verification.'.$messageKey, $locale)."\n\n".$text;
        }

        $back = $this->backCallback($action, $sessionVersion, 'sms-phone');
        $this->queue(
            $action,
            $text,
            'sms-phone',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function renderSmsCodePrompt(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $messageKey,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram_phone_verification.sms_code_prompt', $locale);
        if ($messageKey !== null) {
            $text = $this->translation('telegram_phone_verification.'.$messageKey, $locale)."\n\n".$text;
        }

        $back = $this->backCallback($action, $sessionVersion, 'sms-code');
        $this->queue(
            $action,
            $text,
            'sms-code',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function finish(TelegramInteractionAction $action): void
    {
        $this->queue(
            $action,
            $this->translation('telegram_phone_verification.success', $this->locale($action->userId)),
            'success',
        );
        $this->returnHome($action);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-phone-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':phone-home',
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

    /** @param array<string,mixed> $payload */
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
                'tg-phone-'.$surface.':'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return null;
        }

        $this->assertActor($action, $session->userId);

        return $session;
    }

    private function backCallback(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): TelegramInteractionCallbackReceipt {
        return $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-phone-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );
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

    private function assertActor(TelegramInteractionAction $action, int $userId): void
    {
        if ($userId !== $action->userId) {
            throw new RuntimeException('Telegram phone-verification actor binding changed.');
        }
    }

    private function correlation(TelegramInteractionAction $action, string $surface): string
    {
        return 'tgphone-'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48);
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    private function queue(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot|TelegramContactRequestKeyboardSnapshot|null $keyboard = null,
    ): void {
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };

        $this->delivery->queue(
            TelegramDeliveryAction::Send,
            $action->telegramUserId,
            null,
            $this->presentations->fromSource($source),
            'tg-phone-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tgphone-'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
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
            throw new RuntimeException('Telegram phone-verification translation is unavailable.');
        }

        return $value;
    }
}
