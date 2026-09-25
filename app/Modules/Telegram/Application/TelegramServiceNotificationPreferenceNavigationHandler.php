<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceProjection;
use App\Modules\Telegram\Application\Contracts\TelegramServiceNotificationPreferenceManager;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** @requirement SVC-013 ACL-002 DAT-003 SEC-002 SEC-003 QUA-001 QUA-004 */
final readonly class TelegramServiceNotificationPreferenceNavigationHandler
{
    public const ACTION_GLOBAL_ENTRY = 'navigation.notifications';

    public const ACTION_SERVICE_ENTRY = 'navigation.service.notifications';

    private const STATE = 'service_notification_preferences';

    private const ACTION_TOGGLE = 'navigation.notifications.toggle';

    private const ACTION_PAGE = 'navigation.notifications.page';

    private const ACTION_BACK = 'navigation.back';

    private const PAGE_SIZE = 8;

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramServiceNotificationPreferenceManager $preferences,
        private TelegramOwnedServiceProjection $services,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_GLOBAL_ENTRY)
            || ($action->sessionState === 'service_detail'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_SERVICE_ENTRY)
            || $action->sessionState === self::STATE;
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram global notification preference entry payload is invalid.');
            }
            $this->showPage($action, ['scope' => 'global'], 1);

            return;
        }

        if ($action->sessionState === 'service_detail') {
            if (array_keys($action->callbackPayload) !== ['service_selection']) {
                throw new RuntimeException('Telegram Service notification preference entry payload is invalid.');
            }
            $selection = $this->selection($action->callbackPayload['service_selection'] ?? null);
            $this->showPage($action, [
                'scope' => 'service',
                'service_selection' => $selection,
                'source_page' => $this->positivePage($action->sessionPayload['page'] ?? null),
            ], 1);

            return;
        }

        $scope = $this->scope($action->sessionPayload);
        if ($this->isBack($action)) {
            $this->returnFromScope($action, $scope);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }
        if ($action->callbackAction === self::ACTION_PAGE) {
            if (array_keys($action->callbackPayload) !== ['page']) {
                throw new RuntimeException('Telegram notification preference page payload is invalid.');
            }
            $this->showPage($action, $scope, $this->positivePage($action->callbackPayload['page'] ?? null));

            return;
        }
        if ($action->callbackAction !== self::ACTION_TOGGLE) {
            throw new RuntimeException('Telegram notification preference callback is unsupported.');
        }

        [$type, $threshold, $enabled, $page] = $this->togglePayload($action->callbackPayload);
        try {
            $servicePublicId = $this->servicePublicId($action, $scope);
            $snapshot = $this->preferences->snapshotForSelf($action->userId, $servicePublicId);
            $option = $snapshot->option($type, $threshold);
            if ($option === null || $option->enabled === $enabled) {
                $this->showPage($action, $scope, $page);

                return;
            }
            $callbackId = $this->callbackPublicId($action);
            $this->preferences->configureForSelf(
                $action->userId,
                $servicePublicId,
                $type,
                $threshold,
                $enabled,
                'tg-notify-pref-'.substr(hash('sha256', $callbackId), 0, 48),
                'tg-notify-pref-'.substr(hash('sha256', $callbackId.':correlation'), 0, 40),
            );
        } catch (AuthorizationException|DomainException) {
            $this->returnFromScope($action, $scope);

            return;
        }

        $this->showPage($action, $scope, $page);
    }

    /** @param array{scope:string,service_selection?:string,source_page?:int} $scope */
    private function showPage(TelegramInteractionAction $action, array $scope, int $page): void
    {
        try {
            $servicePublicId = $this->servicePublicId($action, $scope);
            $snapshot = $this->preferences->snapshotForSelf($action->userId, $servicePublicId);
        } catch (AuthorizationException|DomainException) {
            $this->returnFromScope($action, $scope);

            return;
        }

        $total = count($snapshot->options);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $options = array_slice($snapshot->options, $offset, self::PAGE_SIZE);

        $payload = $scope + ['preference_page' => $page];
        $session = $this->transition($action, $payload, 'page-'.$page);
        if ($session === null) {
            return;
        }
        $locale = $this->locale($action->userId);
        $rows = [];
        foreach ($options as $index => $option) {
            $nextEnabled = ! $option->enabled;
            $callback = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_TOGGLE,
                [
                    'type' => $option->notificationType,
                    'threshold' => $option->thresholdCode,
                    'enabled' => $nextEnabled,
                    'page' => $page,
                ],
                'tg-notify-pref-toggle:'.hash('sha256', $action->requestKey.':'.$page.':'.$index.':'.$option->notificationType.':'.$option->thresholdCode),
            );
            $rows[] = [new TelegramInlineCallbackButton(
                ($option->enabled ? '✓ ' : '○ ').$this->optionLabel($option, $locale),
                $callback->publicId,
                $option->enabled ? TelegramInlineButtonStyle::Success : null,
            )];
        }

        $pager = [];
        if ($page > 1) {
            $previous = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_PAGE,
                ['page' => $page - 1],
                'tg-notify-pref-page-prev:'.hash('sha256', $action->requestKey.':'.$page),
            );
            $pager[] = new TelegramInlineCallbackButton('‹', $previous->publicId);
        }
        if ($page < $pages) {
            $next = $this->callbacks->issue(
                $session->publicId,
                $session->version,
                self::ACTION_PAGE,
                ['page' => $page + 1],
                'tg-notify-pref-page-next:'.hash('sha256', $action->requestKey.':'.$page),
            );
            $pager[] = new TelegramInlineCallbackButton('›', $next->publicId);
        }
        if ($pager !== []) {
            $rows[] = $pager;
        }
        $back = $this->callbacks->issue(
            $session->publicId,
            $session->version,
            self::ACTION_BACK,
            [],
            'tg-notify-pref-back:'.hash('sha256', $action->requestKey.':'.$page),
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queue(
            $action,
            $this->translation(
                $scope['scope'] === 'service'
                    ? 'telegram.navigation.notifications.service_title'
                    : 'telegram.navigation.notifications.global_title',
                $locale,
                ['page' => $page, 'pages' => $pages],
            ),
            'page-'.$page,
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function optionLabel(TelegramServiceNotificationPreferenceOption $option, string $locale): string
    {
        $key = $option->thresholdCode === '*'
            ? 'telegram.navigation.notifications.type.'.$option->notificationType
            : 'telegram.navigation.notifications.threshold.'.$option->thresholdCode;

        return $this->translation($key, $locale);
    }

    /** @param array{scope:string,service_selection?:string,source_page?:int} $scope */
    private function servicePublicId(TelegramInteractionAction $action, array $scope): ?string
    {
        if ($scope['scope'] === 'global') {
            return null;
        }
        $selection = $scope['service_selection'] ?? null;
        if (! is_string($selection)) {
            throw new RuntimeException('Telegram Service notification preference scope is incomplete.');
        }

        return $this->services->detailForSelf($action->userId, $action->userId, $selection)->publicId;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{scope:string,service_selection?:string,source_page?:int}
     */
    private function scope(array $payload): array
    {
        $scope = $payload['scope'] ?? null;
        if ($scope === 'global' && isset($payload['preference_page']) && count($payload) === 2) {
            return ['scope' => 'global'];
        }
        if ($scope === 'service'
            && count($payload) === 4
            && isset($payload['preference_page'])
            && is_string($payload['service_selection'] ?? null)
            && preg_match('/\A[0-9a-f]{40}\z/', $payload['service_selection']) === 1) {
            return [
                'scope' => 'service',
                'service_selection' => $payload['service_selection'],
                'source_page' => $this->positivePage($payload['source_page'] ?? null),
            ];
        }

        throw new RuntimeException('Stored Telegram notification preference scope is invalid.');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{string,string,bool,int}
     */
    private function togglePayload(array $payload): array
    {
        if (count($payload) !== 4
            || ! is_string($payload['type'] ?? null)
            || ! is_string($payload['threshold'] ?? null)
            || ! is_bool($payload['enabled'] ?? null)) {
            throw new RuntimeException('Telegram notification preference toggle payload is invalid.');
        }
        $page = $this->positivePage($payload['page'] ?? null);
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $payload['type']) !== 1
            || ($payload['threshold'] !== '*' && preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $payload['threshold']) !== 1)) {
            throw new RuntimeException('Telegram notification preference toggle identity is invalid.');
        }

        return [$payload['type'], $payload['threshold'], $payload['enabled'], $page];
    }

    /** @param array{scope:string,service_selection?:string,source_page?:int} $scope */
    private function returnFromScope(TelegramInteractionAction $action, array $scope): void
    {
        if ($scope['scope'] === 'global') {
            $this->navigation->returnHomeFromExtension($action);

            return;
        }
        $selection = $scope['service_selection'] ?? null;
        $page = $scope['source_page'] ?? null;
        if (! is_string($selection) || ! is_int($page)) {
            throw new RuntimeException('Telegram Service notification preference return scope is invalid.');
        }
        $this->navigation->showOwnedServiceDetailForPage($action, $selection, $page);
    }

    /** @param array{scope:string,service_selection?:string,source_page?:int,preference_page:int} $payload */
    private function transition(TelegramInteractionAction $action, array $payload, string $surface): ?TelegramInteractionSessionReceipt
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE,
                $payload,
                'tg-notify-pref-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram notification preference actor binding changed.');
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
            'tg-notify-pref-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-notify-pref:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function callbackPublicId(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram notification preference callback identity is invalid.');
        }

        return $action->callbackPublicId;
    }

    private function selection(mixed $selection): string
    {
        if (! is_string($selection) || preg_match('/\A[0-9a-f]{40}\z/', $selection) !== 1) {
            throw new RuntimeException('Telegram notification preference Service selection is invalid.');
        }

        return $selection;
    }

    private function positivePage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new RuntimeException('Telegram notification preference page is invalid.');
        }

        return (int) $page;
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
            throw new RuntimeException('Telegram notification preference translation is unavailable.');
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
