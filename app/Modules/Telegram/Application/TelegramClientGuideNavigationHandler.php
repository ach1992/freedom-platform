<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\ClientGuideCatalogPage;
use App\Modules\Catalog\Application\ClientGuideCatalogService;
use App\Modules\Catalog\Application\ClientGuideResourceDefinition;
use App\Modules\Catalog\Application\ClientGuideResourceView;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/** @requirement CAT-007 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 QUA-004 */
final readonly class TelegramClientGuideNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.client_guides';

    public const ACTION_ADMIN_ENTRY = 'navigation.admin.client_guides';

    private const STATE_CATALOG = 'client_guide_catalog';

    private const STATE_ADMIN_LIST = 'admin_client_guide_catalog';

    private const STATE_ADMIN_EDIT = 'admin_client_guide_edit';

    private const ACTION_PAGE = 'navigation.client_guides.page';

    private const ACTION_ADMIN_PAGE = 'navigation.admin.client_guides.page';

    private const ACTION_ADMIN_ADD = 'navigation.admin.client_guides.add';

    private const ACTION_ADMIN_EDIT = 'navigation.admin.client_guides.edit';

    private const ACTION_BACK = 'navigation.back';

    private const PAGE_SIZE = 4;

    private const ADMIN_PAGE_SIZE = 6;

    /** @var list<string> */
    private const DEFINITION_KEYS = [
        'audience', 'code', 'description_en', 'description_fa', 'emoji', 'language', 'platform', 'premium',
        'reason', 'sort', 'state', 'tag', 'tier', 'title_en', 'title_fa', 'tutorial_en', 'tutorial_fa', 'url',
    ];

    public function __construct(
        private LocalizationResolver $localization,
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private ClientGuideCatalogService $catalog,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === TelegramNavigationEntryGateway::STATE
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ADMIN_ENTRY)
            || in_array($action->sessionState, [self::STATE_CATALOG, self::STATE_ADMIN_LIST, self::STATE_ADMIN_EDIT], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === TelegramNavigationEntryGateway::STATE) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram client-guide entry payload is invalid.');
            }
            $this->showCatalog($action, 1);

            return;
        }
        if ($action->sessionState === 'admin_control') {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram client-guide administrator entry payload is invalid.');
            }
            $this->showAdminList($action, 1);

            return;
        }

        match ($action->sessionState) {
            self::STATE_CATALOG => $this->handleCatalog($action),
            self::STATE_ADMIN_LIST => $this->handleAdminList($action),
            self::STATE_ADMIN_EDIT => $this->handleAdminEdit($action),
            default => throw new RuntimeException('Telegram client-guide state is unsupported.'),
        };
    }

    private function handleCatalog(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PAGE) {
                $this->showCatalog($action, $this->pageFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnHome($action);

                return;
            }
            throw new RuntimeException('Telegram client-guide callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back || $this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleAdminList(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_ADMIN_PAGE) {
                $this->showAdminList($action, $this->pageFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_ADMIN_ADD && $action->callbackPayload === []) {
                $this->showAdminEditor($action, 'create', $this->currentPage($action->sessionPayload), null);

                return;
            }
            if ($action->callbackAction === self::ACTION_ADMIN_EDIT) {
                $this->showAdminEditor($action, 'edit', $this->currentPage($action->sessionPayload), $this->resourceFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnAdminControl($action);

                return;
            }
            throw new RuntimeException('Telegram client-guide administrator callback action is unsupported.');
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnAdminControl($action);
        }
    }

    private function handleAdminEdit(TelegramInteractionAction $action): void
    {
        [$mode, $page, $publicId, $version] = $this->editorState($action->sessionPayload);
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []) {
            $this->showAdminList($action, $page);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showAdminList($action, $page);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Message || $action->messageText === null || $this->isEntryCommand($action->messageText)) {
            return;
        }

        try {
            [$definition, $reason] = $this->definitionFromText($action->messageText);
            $administratorId = $this->catalog->administratorIdForUser($action->userId);
            $context = new CatalogChangeContext(
                'tg-guide-'.substr(hash('sha256', $action->requestKey), 0, 56),
                'tg-guide-'.substr(hash('sha256', $action->requestKey.':correlation'), 0, 48),
                'telegram_client_guide',
                $reason,
                $administratorId,
            );
            if ($mode === 'create') {
                $this->catalog->create($definition, $context);
            } else {
                if ($publicId === null || $version === null) {
                    throw new RuntimeException('Telegram client-guide edit identity is unavailable.');
                }
                $this->catalog->updateByPublicId($publicId, $version, $definition, $context);
            }
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (DomainException|InvalidArgumentException) {
            $this->renderAdminEditor($action, $action->sessionVersion, $mode, $publicId, true);

            return;
        }

        $this->showAdminList($action, $page);
    }

    private function showCatalog(TelegramInteractionAction $action, int $page): void
    {
        $catalog = $this->catalog->pageForSelf($action->userId, $action->userId, $page, self::PAGE_SIZE);
        $session = $this->transition($action, self::STATE_CATALOG, ['page' => $catalog->page], 'catalog-'.$catalog->page);
        if ($session === null) {
            return;
        }
        $this->renderCatalog($action, $session->version, $catalog);
    }

    private function showAdminList(TelegramInteractionAction $action, int $page): void
    {
        $catalog = $this->catalog->administratorPageForUser($action->userId, $page, self::ADMIN_PAGE_SIZE);
        $session = $this->transition($action, self::STATE_ADMIN_LIST, ['page' => $catalog->page], 'admin-list-'.$catalog->page);
        if ($session === null) {
            return;
        }
        $this->renderAdminList($action, $session->version, $catalog);
    }

    private function showAdminEditor(TelegramInteractionAction $action, string $mode, int $page, ?string $publicId): void
    {
        if ($mode === 'create') {
            $payload = ['mode' => 'create', 'page' => $page];
        } else {
            if ($publicId === null) {
                throw new RuntimeException('Telegram client-guide edit resource is unavailable.');
            }
            $resource = $this->catalog->administratorResourceForUser($action->userId, $publicId);
            $payload = ['mode' => 'edit', 'page' => $page, 'public_id' => $resource->publicId, 'version' => $resource->version];
        }
        $session = $this->transition($action, self::STATE_ADMIN_EDIT, $payload, 'admin-'.$mode);
        if ($session === null) {
            return;
        }
        $this->renderAdminEditor($action, $session->version, $mode, $publicId, false);
    }

    private function renderCatalog(TelegramInteractionAction $action, int $sessionVersion, ClientGuideCatalogPage $catalog): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($catalog->items as $resource) {
            $rows[] = [new TelegramInlineHttpsUrlButton(
                $this->resourceButtonLabel($resource, $locale),
                $resource->resourceUrl,
                TelegramInlineHttpsUrlPurpose::ClientGuideResource,
                TelegramInlineButtonStyle::Primary,
            )];
            $items[] = $this->resourceSummary($resource, $locale);
        }
        $pagination = $this->pagination($action, $sessionVersion, self::ACTION_PAGE, $catalog, 'catalog');
        if ($pagination !== []) {
            $rows[] = $pagination;
        }
        $rows[] = [$this->callbackButton($action, $sessionVersion, self::ACTION_BACK, [], 'catalog-back', $this->translation('telegram.navigation.buttons.back', $locale))];
        $text = $catalog->items === []
            ? $this->translation('telegram.client_guides.empty', $locale)
            : $this->translation('telegram.client_guides.list', $locale, [
                'items' => implode("\n\n", $items),
                'page' => (string) $catalog->page,
                'pages' => (string) $catalog->totalPages,
            ]);
        $this->queue($action, $text, 'catalog-'.$catalog->page, new TelegramInlineKeyboardSnapshot($rows));
    }

    private function renderAdminList(TelegramInteractionAction $action, int $sessionVersion, ClientGuideCatalogPage $catalog): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];
        $items = [];
        foreach ($catalog->items as $resource) {
            $items[] = $resource->code.' — '.$resource->state.' — #'.$resource->sortOrder.' — v'.$resource->version;
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_ADMIN_EDIT,
                ['resource' => $resource->publicId],
                'admin-edit-'.$resource->publicId,
                $resource->code,
            )];
        }
        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_ADMIN_ADD,
            [],
            'admin-add',
            $this->translation('telegram.client_guides.admin.add_button', $locale),
            TelegramInlineButtonStyle::Primary,
        )];
        $pagination = $this->pagination($action, $sessionVersion, self::ACTION_ADMIN_PAGE, $catalog, 'admin');
        if ($pagination !== []) {
            $rows[] = $pagination;
        }
        $rows[] = [$this->callbackButton($action, $sessionVersion, self::ACTION_BACK, [], 'admin-back', $this->translation('telegram.navigation.buttons.back', $locale))];
        $text = $this->translation('telegram.client_guides.admin.list', $locale, [
            'items' => $items === [] ? $this->translation('telegram.client_guides.admin.none', $locale) : implode("\n", $items),
            'page' => (string) $catalog->page,
            'pages' => (string) $catalog->totalPages,
        ]);
        $this->queue($action, $text, 'admin-list-'.$catalog->page, new TelegramInlineKeyboardSnapshot($rows));
    }

    private function renderAdminEditor(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $mode,
        ?string $publicId,
        bool $invalid,
    ): void {
        $locale = $this->locale($action->userId);
        $current = $mode === 'edit' && $publicId !== null
            ? $this->definitionText($this->catalog->administratorResourceForUser($action->userId, $publicId))
            : $this->translation('telegram.client_guides.admin.create_template', $locale);
        $text = $this->translation('telegram.client_guides.admin.editor', $locale, [
            'current' => $current,
            'mode' => $this->translation('telegram.client_guides.admin.mode.'.$mode, $locale),
        ]);
        if ($invalid) {
            $text .= "\n\n".$this->translation('telegram.client_guides.admin.invalid', $locale);
        }
        $keyboard = new TelegramInlineKeyboardSnapshot([[
            $this->callbackButton($action, $sessionVersion, self::ACTION_BACK, [], 'editor-back', $this->translation('telegram.navigation.buttons.back', $locale)),
        ]]);
        $this->queue($action, $text, 'admin-editor-'.$mode, $keyboard);
    }

    /** @return array{0:ClientGuideResourceDefinition,1:string} */
    private function definitionFromText(string $text): array
    {
        if (mb_strlen($text) > 6000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Client-guide administrator definition is invalid.');
        }
        $values = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            if ($line === '' || ! str_contains($line, '=')) {
                throw new InvalidArgumentException('Client-guide administrator definition is invalid.');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || isset($values[$key])) {
                throw new InvalidArgumentException('Client-guide administrator definition is invalid.');
            }
            $values[$key] = $value;
        }
        $keys = array_keys($values);
        sort($keys, SORT_STRING);
        $expected = self::DEFINITION_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected || preg_match('/\A[0-9]{1,7}\z/', $values['sort'] ?? '') !== 1) {
            throw new InvalidArgumentException('Client-guide administrator definition is invalid.');
        }
        $nullable = static fn (string $value): ?string => $value === '-' ? null : $value;
        $reason = trim($values['reason']);
        if ($reason === '') {
            throw new InvalidArgumentException('Client-guide administrator reason is required.');
        }

        return [new ClientGuideResourceDefinition(
            $values['code'],
            $values['title_fa'],
            $nullable($values['title_en']),
            $nullable($values['description_fa']),
            $nullable($values['description_en']),
            $values['platform'],
            $values['language'],
            $values['audience'],
            $nullable($values['tier']),
            $nullable($values['tag']),
            $values['url'],
            $nullable($values['tutorial_fa']),
            $nullable($values['tutorial_en']),
            $nullable($values['emoji']),
            $nullable($values['premium']),
            (int) $values['sort'],
            $values['state'],
        ), $reason];
    }

    private function definitionText(ClientGuideResourceView $resource): string
    {
        $value = static fn (?string $item): string => $item === null || $item === '' ? '-' : str_replace(["\r", "\n"], ' ', $item);

        return implode("\n", [
            'code='.$resource->code,
            'platform='.$resource->platform,
            'language='.$resource->language,
            'audience='.$resource->audience,
            'tier='.$value($resource->tierCode),
            'tag='.$value($resource->customerTagCode),
            'sort='.$resource->sortOrder,
            'state='.$resource->state,
            'url='.$resource->resourceUrl,
            'emoji='.$value($resource->normalEmoji),
            'premium='.$value($resource->premiumEmojiId),
            'title_fa='.$value($resource->titleFa),
            'title_en='.$value($resource->titleEn),
            'description_fa='.$value($resource->descriptionFa),
            'description_en='.$value($resource->descriptionEn),
            'tutorial_fa='.$value($resource->tutorialFa),
            'tutorial_en='.$value($resource->tutorialEn),
            'reason=describe_the_change',
        ]);
    }

    private function resourceSummary(ClientGuideResourceView $resource, string $locale): string
    {
        $description = $resource->description($locale);
        $tutorial = $resource->tutorial($locale);
        $parts = [$this->resourceButtonLabel($resource, $locale).' ['.$resource->platform.']'];
        if ($description !== null && $description !== '') {
            $parts[] = mb_substr($description, 0, 320);
        }
        if ($tutorial !== null && $tutorial !== '') {
            $parts[] = mb_substr($tutorial, 0, 320);
        }

        return implode("\n", $parts);
    }

    private function resourceButtonLabel(ClientGuideResourceView $resource, string $locale): string
    {
        $prefix = $resource->normalEmoji === null ? '' : trim($resource->normalEmoji).' ';

        return mb_substr($prefix.$resource->title($locale), 0, 64);
    }

    /** @return list<TelegramInlineCallbackButton> */
    private function pagination(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        ClientGuideCatalogPage $catalog,
        string $surface,
    ): array {
        $buttons = [];
        if ($catalog->page > 1) {
            $buttons[] = $this->callbackButton($action, $sessionVersion, $callbackAction, ['page' => $catalog->page - 1], $surface.'-prev', '‹');
        }
        if ($catalog->page < $catalog->totalPages) {
            $buttons[] = $this->callbackButton($action, $sessionVersion, $callbackAction, ['page' => $catalog->page + 1], $surface.'-next', '›');
        }

        return $buttons;
    }

    /** @param array<string,int|string> $payload */
    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
        ?TelegramInlineButtonStyle $style = null,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-guide-button:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId, $style);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, TelegramNavigationEntryGateway::STATE, [], 'home');
        if ($session === null) {
            return;
        }
        $this->navigation->handle($this->syntheticAction($action, $session, '/menu'));
    }

    private function returnAdminControl(TelegramInteractionAction $action): void
    {
        if (! $this->catalog->availableForAdministratorUser($action->userId)) {
            $this->returnHome($action);

            return;
        }
        $session = $this->transition($action, 'admin_control', [], 'admin-control');
        if ($session === null) {
            return;
        }
        $this->navigation->renderCurrentAdminControl($action, $session->version);
    }

    private function syntheticAction(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
        ?string $messageText,
    ): TelegramInteractionAction {
        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':guide-return',
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
            $messageText,
            null,
            null,
            [],
            $action->replayed,
            null,
            $action->messageAcceptedAt,
        );
    }

    /** @param array<string,mixed> $payload */
    private function currentPage(array $payload): int
    {
        if (array_keys($payload) !== ['page'] || ! is_int($payload['page']) || $payload['page'] < 1) {
            throw new RuntimeException('Telegram client-guide page state is invalid.');
        }

        return $payload['page'];
    }

    /** @param array<string,mixed> $payload */
    private function pageFromPayload(array $payload): int
    {
        return $this->currentPage($payload);
    }

    /** @param array<string,mixed> $payload */
    private function resourceFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['resource'] || ! is_string($payload['resource']) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['resource']) !== 1) {
            throw new RuntimeException('Telegram client-guide resource payload is invalid.');
        }

        return $payload['resource'];
    }

    /** @param array<string,mixed> $payload
     * @return array{0:string,1:int,2:?string,3:?int}
     */
    private function editorState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if (($keys === ['mode', 'page'] && ($payload['mode'] ?? null) === 'create')
            && is_int($payload['page']) && $payload['page'] > 0) {
            return ['create', $payload['page'], null, null];
        }
        if ($keys === ['mode', 'page', 'public_id', 'version']
            && ($payload['mode'] ?? null) === 'edit'
            && is_int($payload['page']) && $payload['page'] > 0
            && is_string($payload['public_id']) && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['public_id']) === 1
            && is_int($payload['version']) && $payload['version'] > 0) {
            return ['edit', $payload['page'], $payload['public_id'], $payload['version']];
        }

        throw new RuntimeException('Telegram client-guide administrator editor state is invalid.');
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
                'tg-guide-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram client-guide actor binding changed.');
        }

        return $session;
    }

    private function queue(TelegramInteractionAction $action, string $text, string $surface, TelegramInlineKeyboardSnapshot $keyboard): void
    {
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
            'tg-guide-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-guide:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
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
            throw new RuntimeException('Telegram client-guide translation is unavailable.');
        }

        return $value;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
    }
}
