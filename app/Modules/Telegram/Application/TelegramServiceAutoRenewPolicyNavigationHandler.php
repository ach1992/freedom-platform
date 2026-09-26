<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramServiceAutoRenewPolicyManager;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** @requirement SVC-007 ACL-002 DAT-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class TelegramServiceAutoRenewPolicyNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.service.auto_renew_policy';

    private const STATE_INPUT = 'admin_service_auto_renew_policy';

    private const STATE_CONFIRM = 'admin_service_auto_renew_policy_confirm';

    private const STATE_RESULT = 'admin_service_auto_renew_policy_result';

    private const ACTION_CONFIRM = 'navigation.admin.service.auto_renew_policy.confirm';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramServiceAutoRenewPolicyManager $policies,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [self::STATE_INPUT, self::STATE_CONFIRM, self::STATE_RESULT], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram Service auto-renew policy entry payload is invalid.');
            }
            $this->showInput($action, false);

            return;
        }

        match ($action->sessionState) {
            self::STATE_INPUT => $this->handleInput($action),
            self::STATE_CONFIRM => $this->handleConfirm($action),
            self::STATE_RESULT => $this->handleResult($action),
            default => throw new RuntimeException('Telegram Service auto-renew policy state is unsupported.'),
        };
    }

    private function handleInput(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->returnAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null) {
            return;
        }

        try {
            [$offeringCode, $mode, $absolute, $percentage] = $this->parse($action->messageText);
            $current = $this->policies->snapshotForUser($action->userId, $offeringCode);
        } catch (AuthorizationException) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        } catch (DomainException) {
            $this->showInput($action, true);

            return;
        }

        $session = $this->transition($action, self::STATE_CONFIRM, [
            'offering' => $offeringCode,
            'mode' => $mode,
            'absolute' => $absolute,
            'percentage' => $percentage,
        ], 'confirm');
        if ($session === null) {
            return;
        }
        $confirm = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_CONFIRM,
            [],
            'tg-admin-service-auto-policy-confirm:'.hash('sha256', $action->requestKey.':'.$offeringCode.':'.$mode),
        );
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-admin-service-auto-policy-confirm-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation('telegram.navigation.admin.auto_renew_policy.confirm', $locale, [
                'offering' => $offeringCode,
                'current' => $this->policyText($current->mode, $current->absoluteIncreaseLimitIrr, $current->percentageIncreaseLimitBps, $locale),
                'proposed' => $this->policyText($mode, $absolute, $percentage, $locale),
            ]),
            'confirm',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.auto_renew_policy.confirm_button', $locale),
                    $confirm->publicId,
                    TelegramInlineButtonStyle::Danger,
                )],
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                )],
            ]),
        );
    }

    private function handleConfirm(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showInput($action, false);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_CONFIRM
            || $action->callbackPayload !== []) {
            throw new RuntimeException('Telegram Service auto-renew policy confirmation is unsupported.');
        }
        [$offering, $mode, $absolute, $percentage] = $this->storedPolicy($action->sessionPayload);
        $callbackId = $this->callbackPublicId($action);
        try {
            $result = $this->policies->configureForUser(
                $action->userId,
                $offering,
                $mode,
                $absolute,
                $percentage,
                'tg-admin-auto-'.substr(hash('sha256', $callbackId), 0, 48),
                'tg-admin-auto-'.substr(hash('sha256', $callbackId.':correlation'), 0, 40),
            );
        } catch (AuthorizationException) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        } catch (DomainException) {
            $this->showInput($action, true);

            return;
        }

        $session = $this->transition($action, self::STATE_RESULT, [], 'result');
        if ($session === null) {
            return;
        }
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-admin-service-auto-policy-result-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation('telegram.navigation.admin.auto_renew_policy.result', $locale, [
                'offering' => $result->offeringCode,
                'policy' => $this->policyText($result->mode, $result->absoluteIncreaseLimitIrr, $result->percentageIncreaseLimitBps, $locale),
                'version' => $result->version,
            ]),
            'result',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function handleResult(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->returnAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);
        }
    }

    private function showInput(TelegramInteractionAction $action, bool $invalid): void
    {
        if (! $this->policies->availableFor($action->userId)) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        $session = $this->transition($action, self::STATE_INPUT, [], 'input');
        if ($session === null) {
            return;
        }
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-admin-service-auto-policy-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $key = $invalid
            ? 'telegram.navigation.admin.auto_renew_policy.invalid'
            : 'telegram.navigation.admin.auto_renew_policy.prompt';
        $this->queue(
            $action,
            $this->translation($key, $locale),
            'input',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function returnAdminControl(TelegramInteractionAction $action): void
    {
        if (! $this->policies->availableFor($action->userId)) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        $session = $this->transition($action, 'admin_control', [], 'admin-control');
        if ($session !== null) {
            $this->navigation->renderCurrentAdminControl($action, $session->version);
        }
    }

    /** @return array{string,string,?int,?int} */
    private function parse(string $input): array
    {
        $parts = preg_split('/\s+/', trim($input));
        if (! is_array($parts) || count($parts) < 2 || count($parts) > 4) {
            throw new DomainException('Telegram Service auto-renew policy command is invalid.');
        }
        $offering = $parts[0];
        $mode = $parts[1];
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $offering) !== 1
            || ! in_array($mode, ['stop', 'continue', 'within_limit'], true)) {
            throw new DomainException('Telegram Service auto-renew policy command is invalid.');
        }
        if ($mode !== 'within_limit') {
            if (count($parts) !== 2) {
                throw new DomainException('Stop/continue auto-renew policy must not include limits.');
            }

            return [$offering, $mode, null, null];
        }
        if (count($parts) !== 4) {
            throw new DomainException('Within-limit auto-renew policy requires absolute and percentage fields.');
        }
        $absolute = $this->optionalNonNegativeInt($parts[2], PHP_INT_MAX);
        $percentage = $this->optionalNonNegativeInt($parts[3], 1_000_000);
        if ($absolute === null && $percentage === null) {
            throw new DomainException('Within-limit auto-renew policy requires at least one bound.');
        }

        return [$offering, $mode, $absolute, $percentage];
    }

    private function optionalNonNegativeInt(string $value, int $maximum): ?int
    {
        if ($value === '-') {
            return null;
        }
        if (preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) !== 1) {
            throw new DomainException('Telegram Service auto-renew policy limit is invalid.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]]);
        if ($integer === false) {
            throw new DomainException('Telegram Service auto-renew policy limit is invalid.');
        }

        return (int) $integer;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{string,string,?int,?int}
     */
    private function storedPolicy(array $payload): array
    {
        if (count($payload) !== 4
            || ! is_string($payload['offering'] ?? null)
            || ! is_string($payload['mode'] ?? null)
            || ! array_key_exists('absolute', $payload)
            || ! array_key_exists('percentage', $payload)
            || ($payload['absolute'] !== null && ! is_int($payload['absolute']))
            || ($payload['percentage'] !== null && ! is_int($payload['percentage']))) {
            throw new RuntimeException('Stored Telegram Service auto-renew policy is invalid.');
        }
        if (! in_array($payload['mode'], ['stop', 'continue', 'within_limit'], true)
            || preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $payload['offering']) !== 1) {
            throw new RuntimeException('Stored Telegram Service auto-renew policy is invalid.');
        }
        if ($payload['mode'] !== 'within_limit') {
            if ($payload['absolute'] !== null || $payload['percentage'] !== null) {
                throw new RuntimeException('Stored Telegram Service auto-renew policy limits are invalid.');
            }

            return [$payload['offering'], $payload['mode'], null, null];
        }
        if ($payload['absolute'] === null && $payload['percentage'] === null) {
            throw new RuntimeException('Stored Telegram Service auto-renew policy limits are incomplete.');
        }

        return [$payload['offering'], $payload['mode'], $payload['absolute'], $payload['percentage']];
    }

    private function policyText(?string $mode, ?int $absolute, ?int $percentage, string $locale): string
    {
        if ($mode === null) {
            return $this->translation('telegram.navigation.admin.auto_renew_policy.unconfigured', $locale);
        }
        $absoluteText = $absolute === null ? '-' : number_format($absolute).' IRR';
        $percentageText = $percentage === null ? '-' : number_format($percentage).' bps';

        return $mode.' / abs='.$absoluteText.' / pct='.$percentageText;
    }

    /** @param array<string,mixed> $payload */
    private function transition(TelegramInteractionAction $action, string $state, array $payload, string $surface): ?TelegramInteractionSessionReceipt
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                $state,
                $payload,
                'tg-admin-service-auto-policy-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Service auto-renew policy actor binding changed.');
        }

        return $session;
    }

    private function queue(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
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
            'tg-admin-service-auto-policy-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-admin-auto-policy:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram Service auto-renew policy callback identity is invalid.');
        }

        return $action->callbackPublicId;
    }

    private function isBack(TelegramInteractionAction $action): bool
    {
        return $action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === []);
    }

    private function locale(int $userId): string
    {
        return $this->customers->forSelf($userId, $userId)->locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']' || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram Service auto-renew policy translation is unavailable.');
        }

        return $value;
    }

    private function isEntryCommand(?string $text): bool
    {
        if ($text === null) {
            return false;
        }
        $text = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $text) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $text) === 1;
    }
}
