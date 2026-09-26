<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorServiceOperations;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final readonly class TelegramAdministratorServiceOperationsNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.service.operations';

    private const STATE_INPUT = 'admin_service_operations';

    private const STATE_CONFIRM = 'admin_service_operations_confirm';

    private const STATE_RESULT = 'admin_service_operations_result';

    private const ACTION_CONFIRM = 'navigation.admin.service.operations.confirm';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAdministratorServiceOperations $operations,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array(
                $action->sessionState,
                [self::STATE_INPUT, self::STATE_CONFIRM, self::STATE_RESULT],
                true,
            );
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator Service operations entry payload is invalid.');
            }
            $this->showInput($action, false);

            return;
        }

        match ($action->sessionState) {
            self::STATE_INPUT => $this->handleInput($action),
            self::STATE_CONFIRM => $this->handleConfirm($action),
            self::STATE_RESULT => $this->handleResult($action),
            default => throw new RuntimeException('Telegram administrator Service operations state is unsupported.'),
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
            $preview = $this->operations->prepareForUser(
                $action->userId,
                $action->messageText,
                'tg-admin-svc-preview:'.substr(hash('sha256', $action->requestKey), 0, 48),
                'tg-admin-svc-preview:'.substr(hash('sha256', $action->requestKey.':correlation'), 0, 40),
            );
        } catch (AuthorizationException) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        } catch (DomainException) {
            $this->showInput($action, true);

            return;
        }

        if (! $preview->requiresConfirmation) {
            $this->showResult($action, $preview->summary);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_CONFIRM,
            [
                'summary' => $preview->summary,
                'operation' => $preview->confirmationPayload,
            ],
            'confirm',
        );
        if ($session === null) {
            return;
        }

        $confirm = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_CONFIRM,
            [],
            'tg-admin-service-ops-confirm:'.hash('sha256', $action->requestKey.':confirm'),
        );
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-admin-service-ops-confirm-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation(
                'telegram.navigation.admin.service_operations.confirm',
                $locale,
                ['summary' => $preview->summary],
            ),
            'confirm',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation(
                        'telegram.navigation.admin.service_operations.confirm_button',
                        $locale,
                    ),
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
            throw new RuntimeException('Telegram administrator Service operation confirmation is unsupported.');
        }

        $operation = $this->storedOperation($action->sessionPayload);
        $callbackId = $this->callbackPublicId($action);
        try {
            $result = $this->operations->executeForUser(
                $action->userId,
                $operation,
                'tg-admin-svc-confirm:'.substr(hash('sha256', $callbackId), 0, 48),
                'tg-admin-svc-confirm:'.substr(hash('sha256', $callbackId.':correlation'), 0, 40),
            );
        } catch (AuthorizationException) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        } catch (DomainException) {
            $this->showInput($action, true);

            return;
        }

        $this->showResult($action, $result->summary);
    }

    private function handleResult(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showInput($action, false);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);
        }
    }

    private function showInput(TelegramInteractionAction $action, bool $invalid): void
    {
        if (! $this->operations->availableFor($action->userId)) {
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
            'tg-admin-service-ops-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $key = $invalid
            ? 'telegram.navigation.admin.service_operations.invalid'
            : 'telegram.navigation.admin.service_operations.prompt';
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

    private function showResult(TelegramInteractionAction $action, string $summary): void
    {
        $session = $this->transition($action, self::STATE_RESULT, [], 'result');
        if ($session === null) {
            return;
        }
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-admin-service-ops-result-back:'.hash('sha256', $action->requestKey),
        );
        $locale = $this->locale($action->userId);
        $this->queue(
            $action,
            $this->translation(
                'telegram.navigation.admin.service_operations.result',
                $locale,
                ['summary' => $summary],
            ),
            'result',
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
        if (! $this->operations->availableFor($action->userId)) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        $session = $this->transition($action, 'admin_control', [], 'admin-control');
        if ($session !== null) {
            $this->navigation->renderCurrentAdminControl($action, $session->version);
        }
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function storedOperation(array $payload): array
    {
        if (count($payload) !== 2
            || ! is_string($payload['summary'] ?? null)
            || ! is_array($payload['operation'] ?? null)
            || $payload['operation'] === []) {
            throw new RuntimeException('Stored Telegram administrator Service operation is invalid.');
        }

        /** @var array<string,mixed> $operation */
        $operation = $payload['operation'];

        return $operation;
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
                'tg-admin-service-ops-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram administrator Service operation actor binding changed.');
        }

        return $session;
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
            'tg-admin-service-ops-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-admin-service-ops:'.substr(
                hash('sha256', $action->botId.':'.$action->updateId.':'.$surface),
                0,
                40,
            ),
            $keyboard,
        );
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram administrator Service operation callback identity is invalid.');
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
        if ($value === ''
            || $value === '['.$key.']'
            || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator Service operations translation is unavailable.');
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
