<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class TelegramMenuConfigurationNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.menus';

    private const STATE_LIST = 'admin_menu_configuration';

    private const STATE_EDIT = 'admin_menu_configuration_edit';

    private const STATE_PREVIEW = 'admin_menu_configuration_preview';

    private const STATE_APPLYING = 'admin_menu_configuration_applying';

    private const ACTION_CREATE = 'navigation.admin.menus.create';

    private const ACTION_SELECT = 'navigation.admin.menus.select';

    private const ACTION_PREVIEW = 'navigation.admin.menus.preview';

    private const ACTION_PREVIEW_NOOP = 'navigation.admin.menus.preview.noop';

    private const ACTION_PUBLISH = 'navigation.admin.menus.publish';

    private const ACTION_ROLLBACK = 'navigation.admin.menus.rollback';

    private const ACTION_BACK = 'navigation.back';

    private const HISTORY_LIMIT = 6;

    public function __construct(
        private LocalizationResolver $localization,
        private NonRestrictedTelegramPresentationFactory $presentations,
        private TelegramDeliveryQueueService $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private TelegramMenuConfigurationService $menus,
        private TelegramMenuConfigurationAuthoringParser $parser,
        private TelegramMenuPresentationCapabilities $capabilities,
        private AdministratorUserPermissionAuthorizer $administrators,
        private CustomerAccountSummaryService $customers,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        return ($action->sessionState === 'admin_control'
                && $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY)
            || in_array($action->sessionState, [
                self::STATE_LIST,
                self::STATE_EDIT,
                self::STATE_PREVIEW,
                self::STATE_APPLYING,
            ], true);
    }

    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram menu administrator entry payload is invalid.');
            }
            $this->showList($action, null);

            return;
        }

        match ($action->sessionState) {
            self::STATE_LIST => $this->handleList($action),
            self::STATE_EDIT => $this->handleEdit($action),
            self::STATE_PREVIEW => $this->handlePreview($action),
            self::STATE_APPLYING => throw new RuntimeException('Telegram menu configuration applying state must not be externally observable.'),
            default => throw new RuntimeException('Telegram menu administrator state is unsupported.'),
        };
    }

    private function handleList(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_CREATE && $action->callbackPayload === []) {
                $this->showEditor($action, $this->selectedMenuFromState($action->sessionPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_SELECT) {
                $this->showList($action, $this->menuKeyFromPayload($action->callbackPayload));

                return;
            }
            if ($action->callbackAction === self::ACTION_PREVIEW) {
                $this->showPreview(
                    $action,
                    $this->versionPublicIdFromPayload($action->callbackPayload),
                    $this->selectedMenuFromState($action->sessionPayload),
                );

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->returnAdminControl($action);

                return;
            }

            throw new RuntimeException('Telegram menu administrator list callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->returnAdminControl($action);
        } elseif ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleEdit(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_BACK
            && $action->callbackPayload === []) {
            $this->showList($action, $this->returnMenuFromEditState($action->sessionPayload));

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showList($action, $this->returnMenuFromEditState($action->sessionPayload));

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Message
            || $action->messageText === null
            || $this->isEntryCommand($action->messageText)) {
            return;
        }

        try {
            $actorAdministratorId = $this->administrators->authorizeUser(
                $action->userId,
                TelegramMenuConfigurationMutationExecutor::MANAGE_PERMISSION,
            );
            $draft = $this->parser->parse($action->messageText);

            [$version, $session] = $this->database->connection()->transaction(
                function () use ($action, $actorAdministratorId, $draft): array {
                    $version = $this->menus->createVersion(
                        $draft->menuKey,
                        $draft->definition,
                        $this->changeContext(
                            $actorAdministratorId,
                            $action,
                            'create',
                            $draft->reason,
                        ),
                    );
                    $head = $this->menus->headSnapshot($draft->menuKey);
                    if ($head === null) {
                        throw new RuntimeException('Telegram menu configuration head disappeared after version creation.');
                    }

                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_PREVIEW,
                        [
                            'expected_generation' => $head['generation'],
                            'return_menu' => $draft->menuKey,
                            'version_public_id' => $version->publicId,
                        ],
                        'tg-menu-transition:'.hash('sha256', $action->requestKey.':created-preview'),
                    );
                    $this->assertActor($action, $session);

                    return [$version, $session];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (InvalidArgumentException|RuntimeException $exception) {
            if ($exception instanceof RuntimeException
                && ! str_starts_with($exception->getMessage(), 'Telegram menu')) {
                throw $exception;
            }
            $this->renderEditor(
                $action,
                $action->sessionVersion,
                $this->returnMenuFromEditState($action->sessionPayload),
                true,
            );

            return;
        }

        $this->renderPreview(
            $action,
            $session->version,
            $version,
            $this->previewGenerationFromState($session->payload),
        );
    }

    private function handlePreview(TelegramInteractionAction $action): void
    {
        [$versionPublicId, $expectedGeneration, $returnMenu] = $this->previewState($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_PREVIEW_NOOP && $action->callbackPayload === []) {
                $this->assertPermission($action);
                $this->renderPreview(
                    $action,
                    $action->sessionVersion,
                    $this->menus->versionByPublicId($versionPublicId),
                    $expectedGeneration,
                );

                return;
            }
            if ($action->callbackAction === self::ACTION_PUBLISH && $action->callbackPayload === []) {
                $this->applyVersion($action, $versionPublicId, $expectedGeneration, false);

                return;
            }
            if ($action->callbackAction === self::ACTION_ROLLBACK && $action->callbackPayload === []) {
                $this->applyVersion($action, $versionPublicId, $expectedGeneration, true);

                return;
            }
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showList($action, $returnMenu);

                return;
            }

            throw new RuntimeException('Telegram menu preview callback is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showList($action, $returnMenu);
        } elseif ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function applyVersion(
        TelegramInteractionAction $action,
        string $versionPublicId,
        int $expectedGeneration,
        bool $rollback,
    ): void {
        try {
            $actorAdministratorId = $this->administrators->authorizeUser(
                $action->userId,
                TelegramMenuConfigurationMutationExecutor::MANAGE_PERMISSION,
            );

            [$version, $finalSession] = $this->database->connection()->transaction(
                function () use (
                    $action,
                    $actorAdministratorId,
                    $versionPublicId,
                    $expectedGeneration,
                    $rollback,
                ): array {
                    $version = $this->menus->versionByPublicId($versionPublicId);
                    $claim = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_APPLYING,
                        [
                            'expected_generation' => $expectedGeneration,
                            'return_menu' => $version->menuKey,
                            'version_public_id' => $version->publicId,
                        ],
                        'tg-menu-transition:'.hash('sha256', $action->requestKey.':apply-claim'),
                    );
                    $this->assertActor($action, $claim);

                    $context = $this->changeContext(
                        $actorAdministratorId,
                        $action,
                        $rollback ? 'rollback' : 'publish',
                        $rollback
                            ? 'Rollback Telegram menu configuration to a reviewed historical version.'
                            : 'Publish reviewed Telegram menu configuration version.',
                    );
                    if ($rollback) {
                        $this->menus->rollback(
                            $version->menuKey,
                            $version->version,
                            $expectedGeneration,
                            $context,
                        );
                    } else {
                        $this->menus->publish(
                            $version->publicId,
                            $expectedGeneration,
                            $context,
                        );
                    }

                    $finalSession = $this->sessions->transition(
                        $claim->publicId,
                        $claim->version,
                        self::STATE_LIST,
                        ['menu_key' => $version->menuKey],
                        'tg-menu-transition:'.hash('sha256', $action->requestKey.':apply-complete'),
                    );
                    $this->assertActor($action, $finalSession);

                    return [$version, $finalSession];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Telegram menu publication generation conflict.') {
                throw $exception;
            }

            $version = $this->menus->versionByPublicId($versionPublicId);
            $this->showList($action, $version->menuKey, true);

            return;
        }

        $this->renderList(
            $action,
            $finalSession->version,
            $version->menuKey,
            true,
        );
    }

    private function showList(
        TelegramInteractionAction $action,
        ?string $selectedMenu,
        bool $stale = false,
    ): void {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        if ($selectedMenu !== null) {
            TelegramMenuItemDefinition::assertMenuKey($selectedMenu);
        }

        $payload = $selectedMenu === null ? [] : ['menu_key' => $selectedMenu];
        $session = $this->transition($action, self::STATE_LIST, $payload, 'list');
        if ($session === null) {
            return;
        }

        $this->renderList($action, $session->version, $selectedMenu, false, $stale);
    }

    private function renderList(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $selectedMenu,
        bool $changed = false,
        bool $stale = false,
    ): void {
        $this->assertPermission($action);
        $locale = $this->locale($action->userId);
        $keys = $this->menus->menuKeys();
        $rows = [];
        $summary = [];

        foreach ($keys as $menuKey) {
            $head = $this->menus->headSnapshot($menuKey);
            if ($head === null) {
                continue;
            }
            $activeVersion = 0;
            if ($head['active_version_id'] !== null) {
                $active = $this->menus->active($menuKey);
                if ($active === null) {
                    throw new RuntimeException('Telegram menu active version is unavailable.');
                }
                $activeVersion = $active->version;
            }
            $summary[] = $this->translation('telegram.navigation.admin.menus.item', $locale, [
                'generation' => $head['generation'],
                'menu_key' => $menuKey,
                'version' => $activeVersion,
            ]);
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_SELECT,
                ['menu_key' => $menuKey],
                'select-'.$menuKey,
                ($selectedMenu === $menuKey ? '• ' : '').$menuKey,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        if ($selectedMenu !== null) {
            foreach (array_slice($this->menus->history($selectedMenu), 0, self::HISTORY_LIMIT) as $version) {
                $rows[] = [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_PREVIEW,
                    ['version_public_id' => $version->publicId],
                    'preview-'.$version->publicId,
                    $this->translation('telegram.navigation.admin.menus.version_button', $locale, [
                        'version' => $version->version,
                    ]),
                    $version->active ? TelegramInlineButtonStyle::Success : null,
                )];
            }
        }

        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_CREATE,
            [],
            'create',
            $this->translation('telegram.navigation.admin.menus.create_button', $locale),
            TelegramInlineButtonStyle::Primary,
        )];
        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'back',
            $this->translation('telegram.navigation.buttons.back', $locale),
        )];

        $text = $this->translation('telegram.navigation.admin.menus.list', $locale, [
            'items' => $summary === []
                ? $this->translation('telegram.navigation.admin.menus.none', $locale)
                : implode("\n", $summary),
            'selected' => $selectedMenu ?? $this->translation('telegram.navigation.admin.menus.not_selected', $locale),
        ]);
        if ($changed) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.menus.changed', $locale);
        }
        if ($stale) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.menus.stale', $locale);
        }

        $this->queue($action, $text, 'list', new TelegramInlineKeyboardSnapshot($rows));
    }

    private function showEditor(TelegramInteractionAction $action, ?string $returnMenu): void
    {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_EDIT,
            ['return_menu' => $returnMenu],
            'editor',
        );
        if ($session === null) {
            return;
        }

        $this->renderEditor($action, $session->version, $returnMenu, false);
    }

    private function renderEditor(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $returnMenu,
        bool $invalid,
    ): void {
        $locale = $this->locale($action->userId);
        $text = $this->translation('telegram.navigation.admin.menus.editor', $locale, [
            'example' => $this->authoringExample($locale, $returnMenu ?? 'home'),
        ]);
        if ($invalid) {
            $text .= "\n\n".$this->translation('telegram.navigation.admin.menus.invalid', $locale);
        }

        $keyboard = new TelegramInlineKeyboardSnapshot([[
            $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_BACK,
                [],
                'editor-back',
                $this->translation('telegram.navigation.buttons.back', $locale),
            ),
        ]]);
        $this->queue($action, $text, 'editor', $keyboard);
    }

    private function showPreview(
        TelegramInteractionAction $action,
        string $versionPublicId,
        ?string $returnMenu,
    ): void {
        try {
            $this->assertPermission($action);
            $version = $this->menus->versionByPublicId($versionPublicId);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $head = $this->menus->headSnapshot($version->menuKey);
        if ($head === null) {
            throw new RuntimeException('Telegram menu preview head is unavailable.');
        }
        $session = $this->transition(
            $action,
            self::STATE_PREVIEW,
            [
                'expected_generation' => $head['generation'],
                'return_menu' => $returnMenu ?? $version->menuKey,
                'version_public_id' => $version->publicId,
            ],
            'preview',
        );
        if ($session === null) {
            return;
        }

        $this->renderPreview($action, $session->version, $version, $head['generation']);
    }

    private function renderPreview(
        TelegramInteractionAction $action,
        int $sessionVersion,
        TelegramMenuConfigurationVersion $version,
        int $expectedGeneration,
    ): void {
        $this->assertPermission($action);
        $locale = $this->locale($action->userId);
        $rows = [];

        foreach ($version->definition->items as $item) {
            if (! $item->enabled || ($item->language !== 'any' && $item->language !== $locale)) {
                continue;
            }

            $callback = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PREVIEW_NOOP,
                [],
                'tg-menu-preview-noop:'.hash('sha256', $action->requestKey.':'.$version->publicId.':'.$item->key),
            );
            $rows[$item->row][$item->order] = new TelegramInlineCallbackButton(
                $this->previewLabel($item, $locale),
                $callback->publicId,
                $this->capabilities->style($item->style),
                $this->capabilities->premiumEmojiId($item),
            );
        }

        ksort($rows, SORT_NUMERIC);
        $normalized = [];
        foreach ($rows as $row) {
            ksort($row, SORT_NUMERIC);
            $normalized[] = array_values($row);
        }

        $active = $this->menus->active($version->menuKey);
        if (! $version->active) {
            $rollback = $active !== null && $version->version < $active->version;
            $normalized[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                $rollback ? self::ACTION_ROLLBACK : self::ACTION_PUBLISH,
                [],
                $rollback ? 'rollback' : 'publish',
                $this->translation(
                    $rollback
                        ? 'telegram.navigation.admin.menus.rollback_button'
                        : 'telegram.navigation.admin.menus.publish_button',
                    $locale,
                ),
                $rollback ? TelegramInlineButtonStyle::Danger : TelegramInlineButtonStyle::Success,
            )];
        }

        $normalized[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'preview-back',
            $this->translation('telegram.navigation.buttons.back', $locale),
        )];

        $this->queue(
            $action,
            $this->translation('telegram.navigation.admin.menus.preview', $locale, [
                'active' => $version->active
                    ? $this->translation('telegram.navigation.admin.menus.yes', $locale)
                    : $this->translation('telegram.navigation.admin.menus.no', $locale),
                'generation' => $expectedGeneration,
                'items' => count($version->definition->items),
                'menu_key' => $version->menuKey,
                'version' => $version->version,
            ]),
            'preview-'.$version->publicId,
            new TelegramInlineKeyboardSnapshot($normalized),
        );
    }

    private function previewLabel(TelegramMenuItemDefinition $item, string $locale): string
    {
        $base = $locale === 'en'
            ? ($item->labelEn ?? $item->labelFa)
            : $item->labelFa;

        if ($base === null && $item->actionKey !== null) {
            $registered = TelegramMenuRegisteredAction::tryFrom($item->actionKey);
            $base = $registered === null ? null : $this->registeredPreviewLabel($registered, $locale);
        }
        if ($base === null) {
            $base = $item->key;
        }

        return $this->capabilities->label($item, $base);
    }

    private function registeredPreviewLabel(TelegramMenuRegisteredAction $registered, string $locale): string
    {
        $key = match ($registered) {
            TelegramMenuRegisteredAction::MyAccount => 'telegram.navigation.buttons.my_account',
            TelegramMenuRegisteredAction::WalletTransfer => 'telegram_wallet_transfer.entry',
            TelegramMenuRegisteredAction::Purchase => 'telegram.navigation.buttons.buy_service',
            TelegramMenuRegisteredAction::Trial => 'telegram.navigation.buttons.trial_service',
            TelegramMenuRegisteredAction::MyServices => 'telegram.navigation.buttons.my_services',
            TelegramMenuRegisteredAction::ClientGuides => 'telegram.navigation.buttons.client_guides',
            TelegramMenuRegisteredAction::Support => 'telegram.navigation.buttons.support',
            TelegramMenuRegisteredAction::ExternalSupport => 'telegram.navigation.buttons.external_support',
            TelegramMenuRegisteredAction::Agent => 'telegram.navigation.buttons.agent_menu',
            TelegramMenuRegisteredAction::Admin => 'telegram.navigation.buttons.admin',
            TelegramMenuRegisteredAction::Referral => 'telegram.navigation.buttons.referral',
        };

        return $this->translation($key, $locale);
    }

    private function authoringExample(string $locale, string $menuKey): string
    {
        $label = $locale === 'en' ? 'My Account' : 'حساب من';

        return json_encode([
            'menu_key' => $menuKey,
            'reason' => 'describe_the_change',
            'items' => [[
                'key' => 'my_account',
                'kind' => 'system',
                'action_type' => 'registered',
                'action_key' => 'my_account',
                'label_fa' => $locale === 'en' ? null : $label,
                'label_en' => $locale === 'en' ? $label : null,
                'normal_emoji' => '👤',
                'premium_emoji_id' => null,
                'style' => 'primary',
                'row' => 0,
                'order' => 0,
                'enabled' => true,
                'language' => 'any',
                'audience' => 'all',
                'active_from' => null,
                'active_until' => null,
            ], [
                'key' => 'my_services',
                'kind' => 'system',
                'action_type' => 'registered',
                'action_key' => 'my_services',
                'style' => 'primary',
                'row' => 1,
                'order' => 0,
                'enabled' => true,
                'language' => 'any',
                'audience' => 'all',
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function returnAdminControl(TelegramInteractionAction $action): void
    {
        try {
            $this->assertPermission($action);
        } catch (AuthorizationException) {
            $this->returnHome($action);

            return;
        }

        $session = $this->transition($action, 'admin_control', [], 'admin-control');
        if ($session === null) {
            return;
        }
        $this->navigation->renderCurrentAdminControl($action, $session->version);
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        $session = $this->transition($action, TelegramNavigationEntryGateway::STATE, [], 'home');
        if ($session === null) {
            return;
        }
        $this->navigation->handle($this->syntheticAction($action, $session, '/menu'));
    }

    private function syntheticAction(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
        ?string $messageText,
    ): TelegramInteractionAction {
        return new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':menu-config-return',
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

    private function assertPermission(TelegramInteractionAction $action): int
    {
        return $this->administrators->authorizeUser(
            $action->userId,
            TelegramMenuConfigurationMutationExecutor::MANAGE_PERMISSION,
        );
    }

    private function changeContext(
        int $administratorId,
        TelegramInteractionAction $action,
        string $operation,
        string $reason,
    ): TelegramConfigurationChangeContext {
        return new TelegramConfigurationChangeContext(
            'tg-menu-'.$operation.'-'.substr(hash('sha256', $action->requestKey), 0, 56),
            'tg-menu-'.substr(hash('sha256', $action->requestKey.':correlation'), 0, 48),
            'telegram_menu_configuration',
            $reason,
            $administratorId,
        );
    }

    /** @param array<string,mixed> $payload */
    private function menuKeyFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['menu_key'] || ! is_string($payload['menu_key'])) {
            throw new RuntimeException('Telegram menu selection payload is invalid.');
        }
        TelegramMenuItemDefinition::assertMenuKey($payload['menu_key']);

        return $payload['menu_key'];
    }

    /** @param array<string,mixed> $payload */
    private function versionPublicIdFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['version_public_id']
            || ! is_string($payload['version_public_id'])
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['version_public_id']) !== 1) {
            throw new RuntimeException('Telegram menu version payload is invalid.');
        }

        return $payload['version_public_id'];
    }

    /** @param array<string,mixed> $payload */
    private function selectedMenuFromState(array $payload): ?string
    {
        if ($payload === []) {
            return null;
        }
        if (array_keys($payload) !== ['menu_key'] || ! is_string($payload['menu_key'])) {
            throw new RuntimeException('Telegram menu list state is invalid.');
        }
        TelegramMenuItemDefinition::assertMenuKey($payload['menu_key']);

        return $payload['menu_key'];
    }

    /** @param array<string,mixed> $payload */
    private function returnMenuFromEditState(array $payload): ?string
    {
        if (array_keys($payload) !== ['return_menu']) {
            throw new RuntimeException('Telegram menu editor state is invalid.');
        }
        if ($payload['return_menu'] === null) {
            return null;
        }
        if (! is_string($payload['return_menu'])) {
            throw new RuntimeException('Telegram menu editor return state is invalid.');
        }
        TelegramMenuItemDefinition::assertMenuKey($payload['return_menu']);

        return $payload['return_menu'];
    }

    /** @param array<string,mixed> $payload
     * @return array{string,int,?string}
     */
    private function previewState(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['expected_generation', 'return_menu', 'version_public_id']
            || ! is_int($payload['expected_generation'])
            || $payload['expected_generation'] < 0
            || ! is_string($payload['version_public_id'])
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $payload['version_public_id']) !== 1
            || ($payload['return_menu'] !== null && ! is_string($payload['return_menu']))) {
            throw new RuntimeException('Telegram menu preview state is invalid.');
        }
        if (is_string($payload['return_menu'])) {
            TelegramMenuItemDefinition::assertMenuKey($payload['return_menu']);
        }

        return [
            $payload['version_public_id'],
            $payload['expected_generation'],
            $payload['return_menu'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function previewGenerationFromState(array $payload): int
    {
        [, $generation] = $this->previewState($payload);

        return $generation;
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
            'tg-menu-button:'.hash('sha256', $action->requestKey.':'.$surface),
        );

        return new TelegramInlineCallbackButton($label, $callback->publicId, $style);
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
                'tg-menu-transition:'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        $this->assertActor($action, $session);

        return $session;
    }

    private function assertActor(
        TelegramInteractionAction $action,
        TelegramInteractionSessionReceipt $session,
    ): void {
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram menu configuration actor binding changed.');
        }
    }

    private function queue(
        TelegramInteractionAction $action,
        string $text,
        string $surface,
        TelegramInlineKeyboardSnapshot $keyboard,
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
            'tg-menu-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            'tg-menu:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 48),
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
            throw new RuntimeException('Telegram menu configuration translation is unavailable.');
        }

        return $value;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
    }
}
