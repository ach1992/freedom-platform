<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Bounded ADM-001 cross-entity search presentation. Domain-owned search sources
 * expose only permission-filtered read models; this handler never mutates those
 * domains and never stores the administrator query in session/callback state.
 */
final readonly class TelegramAdministratorSearchNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.global_search';

    private const STATE_SEARCH = 'admin_global_search';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramAdministratorSearchService $search,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || $action->sessionState === self::STATE_SEARCH;
    }

    /** @requirement ADM-001 ACL-001 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_ENTRY
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator global-search entry is invalid.');
            }

            $this->showSearch($action);

            return;
        }

        if ($action->sessionState !== self::STATE_SEARCH) {
            throw new RuntimeException('Telegram administrator global-search state is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->navigation->showAdminControl($action);

                return;
            }

            throw new RuntimeException('Telegram administrator global-search callback is unsupported.');
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

        try {
            $items = $this->search->search($action->userId, $action->botId, $action->messageText);
        } catch (AuthorizationException) {
            $this->navigation->showAdminControl($action);

            return;
        }

        $this->renderSearch($action, $action->sessionVersion, $items);
    }

    private function showSearch(TelegramInteractionAction $action): void
    {
        if (! $this->search->availableFor($action->userId)) {
            $this->navigation->showAdminControl($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_SEARCH,
                [],
                'tg-admin-global-search:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderSearch($action, $session->version, null);
    }

    /**
     * @param  list<TelegramAdministratorSearchItem>|null  $items
     */
    private function renderSearch(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?array $items,
    ): void {
        $locale = $this->locale($action->userId);
        $key = 'telegram.navigation.admin.global_search.';
        if ($items === null) {
            $text = $this->translation($key.'prompt', $locale);
        } elseif ($items === []) {
            $text = $this->translation($key.'prompt', $locale)
                ."\n\n".$this->translation($key.'not_found', $locale);
        } else {
            $lines = [];
            foreach ($items as $offset => $item) {
                $notAvailable = $this->translation($key.'not_available', $locale);
                $reference = $item->reference ?? $notAvailable;
                if ($item->reference !== null && $item->referenceMasked) {
                    $reference .= $this->translation($key.'masked_suffix', $locale);
                }
                $lines[] = $this->translation($key.'item', $locale, [
                    'number' => $offset + 1,
                    'kind' => $this->translation($key.'kinds.'.$item->kind, $locale),
                    'id' => $item->publicId,
                    'state' => $item->state,
                    'owner' => $item->ownerPublicId ?? $notAvailable,
                    'provider' => $item->providerCode ?? $notAvailable,
                    'reference' => $reference,
                    'amount' => $item->amountIrr === null
                        ? $notAvailable
                        : $this->translation($key.'amount', $locale, ['amount' => number_format($item->amountIrr)]),
                ]);
            }
            $text = $this->translation($key.'results', $locale, ['items' => implode("\n\n", $lines)]);
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-global-search-back:'.hash('sha256', $action->requestKey),
        );

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
            'tg-admin-global-search-delivery:'.hash('sha256', $action->requestKey),
            'tg-admin-global-search:'.substr(hash('sha256', $action->botId.':'.$action->updateId), 0, 40),
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
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
                'tg-admin-global-search-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);

        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-global-search-home',
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

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    /** @param  array<string,int|string>  $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram administrator global-search translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator global-search translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram administrator global-search actor binding is invalid.');
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
