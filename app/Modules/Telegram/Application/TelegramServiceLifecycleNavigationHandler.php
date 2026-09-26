<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceLifecycleExecutor;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/** @requirement SVC-004 SVC-006 ACL-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class TelegramServiceLifecycleNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.service.lifecycle';

    private const STATE_CONFIRM = 'service_lifecycle_confirm';

    private const STATE_RESULT = 'service_lifecycle_result';

    private const ACTION_CONFIRM = 'navigation.service.lifecycle.confirm';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramOwnedServiceProjection $services,
        private TelegramOwnedServiceLifecycleExecutor $lifecycle,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'service_detail'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [self::STATE_CONFIRM, self::STATE_RESULT], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'service_detail') {
            [$serviceSelection, $serviceAction] = $this->entryPayload($action->callbackPayload);
            $this->showConfirmation($action, $serviceSelection, $serviceAction, $this->page($action->sessionPayload));

            return;
        }

        if ($action->sessionState === self::STATE_CONFIRM) {
            $this->handleConfirmation($action);

            return;
        }

        if ($action->sessionState === self::STATE_RESULT) {
            $this->handleResult($action);

            return;
        }

        throw new RuntimeException('Telegram Service lifecycle state is unsupported.');
    }

    private function showConfirmation(
        TelegramInteractionAction $action,
        string $selectionToken,
        TelegramOwnedServiceAction $serviceAction,
        int $page,
    ): void {
        try {
            $detail = $this->services->detailForSelf($action->userId, $action->userId, $selectionToken);
        } catch (AuthorizationException|DomainException) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selectionToken, $page);

            return;
        }
        if (! in_array($serviceAction, $detail->allowedActions, true) || ! $serviceAction->isLifecycleAction()) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selectionToken, $page);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_CONFIRM,
            ['page' => $page, 'service_selection' => $selectionToken, 'action' => $serviceAction->value],
            'confirm-'.$serviceAction->value,
        );
        if ($session === null) {
            return;
        }

        $confirm = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_CONFIRM,
            [],
            'tg-service-lifecycle-confirm:'.hash('sha256', $action->requestKey.':'.$serviceAction->value),
        );
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-service-lifecycle-back:'.hash('sha256', $action->requestKey.':'.$serviceAction->value),
        );
        $locale = $this->locale($action->userId);
        $keyboard = new TelegramInlineKeyboardSnapshot([
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.services.lifecycle.confirm_button', $locale),
                $confirm->publicId,
                TelegramInlineButtonStyle::Danger,
            )],
            [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            )],
        ]);

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.services.lifecycle.confirm.'.$serviceAction->value, $locale),
            'tg-service-lifecycle-confirm-delivery:'.hash('sha256', $action->requestKey),
            'confirm-'.$serviceAction->value,
            $keyboard,
        );
    }

    private function handleConfirmation(TelegramInteractionAction $action): void
    {
        [$page, $selectionToken, $serviceAction] = $this->statePayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selectionToken, $page);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback
            || $action->callbackAction !== self::ACTION_CONFIRM
            || $action->callbackPayload !== []) {
            throw new RuntimeException('Telegram Service lifecycle confirmation action is unsupported.');
        }

        try {
            $detail = $this->services->detailForSelf($action->userId, $action->userId, $selectionToken);
            if (! in_array($serviceAction, $detail->allowedActions, true)) {
                throw new AuthorizationException('Telegram Service lifecycle action is no longer available.');
            }
            $callbackPublicId = $this->callbackPublicId($action);
            $requestKey = 'tg-service-life-'.substr(hash('sha256', $callbackPublicId), 0, 48);
            $correlationId = 'tg-service-life-'.substr(hash('sha256', $callbackPublicId.':correlation'), 0, 40);

            if ($serviceAction === TelegramOwnedServiceAction::RefreshDetails) {
                $result = $this->lifecycle->executeForSelf(
                    $action->userId,
                    $detail->publicId,
                    $serviceAction,
                    $requestKey,
                    $correlationId,
                );
                $session = $this->transition(
                    $action,
                    self::STATE_RESULT,
                    ['page' => $page, 'service_selection' => $selectionToken, 'action' => $serviceAction->value],
                    'result-'.$serviceAction->value,
                );
            } else {
                [$result, $session] = $this->database->connection()->transaction(function () use (
                    $action,
                    $detail,
                    $serviceAction,
                    $requestKey,
                    $correlationId,
                    $page,
                    $selectionToken,
                ): array {
                    $result = $this->lifecycle->executeForSelf(
                        $action->userId,
                        $detail->publicId,
                        $serviceAction,
                        $requestKey,
                        $correlationId,
                    );
                    $session = $this->transition(
                        $action,
                        self::STATE_RESULT,
                        ['page' => $page, 'service_selection' => $selectionToken, 'action' => $serviceAction->value],
                        'result-'.$serviceAction->value,
                    );

                    return [$result, $session];
                }, 3);
            }
        } catch (AuthorizationException|DomainException) {
            $this->showUnavailable($action, $selectionToken, $serviceAction, $page);

            return;
        }
        if ($session === null) {
            return;
        }

        $this->renderResult($action, $session->version, $selectionToken, $serviceAction, $page, $result->status);
    }

    private function handleResult(TelegramInteractionAction $action): void
    {
        [$page, $selectionToken] = $this->statePayload($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Back
            || ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])) {
            $this->navigation->showOwnedServiceDetailForPage($action, $selectionToken, $page);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Message && $this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);
        }
    }

    private function showUnavailable(
        TelegramInteractionAction $action,
        string $selectionToken,
        TelegramOwnedServiceAction $serviceAction,
        int $page,
    ): void {
        $session = $this->transition(
            $action,
            self::STATE_RESULT,
            ['page' => $page, 'service_selection' => $selectionToken, 'action' => $serviceAction->value],
            'unavailable-'.$serviceAction->value,
        );
        if ($session === null) {
            return;
        }
        $this->renderResult(
            $action,
            $session->version,
            $selectionToken,
            $serviceAction,
            $page,
            null,
        );
    }

    private function renderResult(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $selectionToken,
        TelegramOwnedServiceAction $serviceAction,
        int $page,
        ?TelegramOwnedServiceLifecycleStatus $status,
    ): void {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-service-lifecycle-result-back:'.hash('sha256', $action->requestKey.':'.$serviceAction->value),
        );
        $locale = $this->locale($action->userId);
        $key = $status === null
            ? 'telegram.navigation.services.lifecycle.result.unavailable'
            : 'telegram.navigation.services.lifecycle.result.'.$status->value;
        $this->queueConfidential(
            $action,
            $this->translation($key, $locale),
            'tg-service-lifecycle-result-delivery:'.hash('sha256', $action->requestKey),
            'result-'.$serviceAction->value,
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{string,TelegramOwnedServiceAction}
     */
    private function entryPayload(array $payload): array
    {
        if (count($payload) !== 2
            || ! is_string($payload['service_selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['service_selection']) !== 1
            || ! is_string($payload['action'] ?? null)) {
            throw new RuntimeException('Telegram Service lifecycle entry payload is invalid.');
        }
        $serviceAction = TelegramOwnedServiceAction::tryFrom($payload['action']);
        if ($serviceAction === null || ! $serviceAction->isLifecycleAction()) {
            throw new RuntimeException('Telegram Service lifecycle action is invalid.');
        }

        return [$payload['service_selection'], $serviceAction];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{int,string,TelegramOwnedServiceAction}
     */
    private function statePayload(array $payload): array
    {
        if (count($payload) !== 3
            || filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || ! is_string($payload['service_selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['service_selection']) !== 1
            || ! is_string($payload['action'] ?? null)) {
            throw new RuntimeException('Stored Telegram Service lifecycle state is invalid.');
        }
        $serviceAction = TelegramOwnedServiceAction::tryFrom($payload['action']);
        if ($serviceAction === null || ! $serviceAction->isLifecycleAction()) {
            throw new RuntimeException('Stored Telegram Service lifecycle action is invalid.');
        }

        return [(int) $payload['page'], $payload['service_selection'], $serviceAction];
    }

    /** @param array<string,mixed> $payload */
    private function page(array $payload): int
    {
        $page = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new RuntimeException('Telegram Service lifecycle page is invalid.');
        }

        return (int) $page;
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
                'tg-service-lifecycle-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Service lifecycle actor binding changed.');
        }

        return $session;
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram Service lifecycle callback identity is invalid.');
        }

        return $action->callbackPublicId;
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $requestKey,
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
            $requestKey,
            'tg-service-life:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
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
            throw new RuntimeException('Telegram Service lifecycle translation is unavailable.');
        }

        return $value;
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
