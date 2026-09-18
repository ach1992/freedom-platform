<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorCustomerTargetDiscovery;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Read-only administrator customer target discovery/preview.
 *
 * Customer lookup/authorization remains in the Customers projection. This
 * handler owns only restart-safe Telegram interaction and confidential copy.
 */
final readonly class TelegramAdminCustomerNavigationHandler
{
    public const ACTION_SEARCH = 'navigation.admin.customer_search';

    private const STATE_SEARCH = 'admin_customer_search';

    private const STATE_PREVIEW = 'admin_customer_preview';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAdministratorCustomerTargetDiscovery $targets,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_SEARCH)
            || in_array($action->sessionState, [self::STATE_SEARCH, self::STATE_PREVIEW], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_SEARCH
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator customer search entry is invalid.');
            }
            $this->showSearch($action);

            return;
        }

        if ($action->sessionState === self::STATE_SEARCH) {
            $this->handleSearch($action);

            return;
        }

        if ($action->sessionState === self::STATE_PREVIEW) {
            $this->handlePreview($action);

            return;
        }

        throw new RuntimeException('Telegram administrator customer navigation state is unsupported.');
    }

    private function handleSearch(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->navigation->showAdminControl($action);

                return;
            }

            throw new RuntimeException('Telegram administrator customer search callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->navigation->showAdminControl($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $result = $this->targets->search($action->userId, $action->botId, $action->messageText);
        if ($result->disposition === TelegramAdministratorCustomerTargetSearchDisposition::NotFound) {
            $this->renderSearch($action, $action->sessionVersion, 'not_found');

            return;
        }
        if ($result->disposition === TelegramAdministratorCustomerTargetSearchDisposition::Ambiguous) {
            $this->renderSearch($action, $action->sessionVersion, 'ambiguous');

            return;
        }
        if ($result->target === null) {
            throw new RuntimeException('Telegram administrator customer search result is invalid.');
        }

        try {
            $target = $this->targets->resolve(
                $action->userId,
                $action->botId,
                $result->target->selectionToken,
            );
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PREVIEW,
                ['selection' => $target->selectionToken],
                'tg-admin-customer-preview:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderPreview($action, $session->version, $target);
    }

    private function handlePreview(TelegramInteractionAction $action): void
    {
        $selection = $this->selectionFromPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showSearch($action);

                return;
            }

            throw new RuntimeException('Telegram administrator customer preview callback is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showSearch($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }
        if ($action->messageText === null) {
            return;
        }

        $target = $this->targets->resolve($action->userId, $action->botId, $selection);
        $this->renderPreview($action, $action->sessionVersion, $target);
    }

    private function showSearch(TelegramInteractionAction $action): void
    {
        if (! $this->targets->availableFor($action->userId)) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SEARCH,
                [],
                'tg-admin-customer-search:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);
        $this->renderSearch($action, $session->version);
    }

    private function renderSearch(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram.navigation.admin.customer_search.prompt', $locale);
        if ($notice !== null) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.customer_search.'.$notice, $locale);
        }

        $this->queue(
            $action,
            $text,
            'search',
            $this->backKeyboard($action, $sessionVersion, $locale, 'search'),
        );
    }

    private function renderPreview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramAdministratorCustomerTarget $target,
    ): void {
        $locale = $this->locale($action->userId);
        $username = $target->maskedUsername
            ?? $this->translation('telegram.navigation.admin.customer_search.username_unavailable', $locale);

        $this->queue(
            $action,
            $this->translation('telegram.navigation.admin.customer_search.preview', $locale, [
                'telegram_id' => $target->telegramUserId,
                'account_id' => $target->accountPublicId,
                'username' => $username,
                'account_type' => $this->translation(
                    'telegram.navigation.account.values.account_type.'.$target->accountType,
                    $locale,
                ),
                'account_status' => $this->translation(
                    'telegram.navigation.account.values.account_status.'.$target->accountStatus,
                    $locale,
                ),
            ]),
            'preview',
            $this->backKeyboard($action, $sessionVersion, $locale, 'preview'),
        );
    }

    private function backKeyboard(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $locale,
        string $surface,
    ): TelegramInlineKeyboardSnapshot {
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-customer-back:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.buttons.back', $locale),
                $back->publicId,
            ),
        ]]);
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
        $presentation = $this->presentations->fromSource($source);
        $this->delivery->send(
            $action->telegramUserId,
            $presentation,
            'tg-admin-customer-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-admin-customer:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
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
                'tg-admin-customer-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }
        $this->assertActor($action, $session->userId);

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-customer-home',
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

    /** @param array<string,mixed> $payload */
    private function selectionFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['selection']
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram administrator customer preview state is invalid.');
        }

        return $payload['selection'];
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram administrator customer translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator customer translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram administrator customer session actor binding is invalid.');
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
