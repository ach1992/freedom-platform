<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Operations\Application\OperationalAlertLifecycleService;
use App\Modules\Operations\Application\OperationalAlertQueryService;
use App\Modules\Operations\Application\OperationsCenterActionService;
use App\Modules\Operations\Application\OperationsCenterFact;
use App\Modules\Operations\Application\OperationsCenterService;
use App\Modules\Operations\Application\OperationsCenterSnapshot;
use App\Modules\Operations\Application\OperationsPermissions;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/** Telegram adapter for the permission-aware Operations application boundary. */
final readonly class TelegramAdministratorOperationsNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.operations';

    private const STATE_MENU = 'admin_operations_menu';

    private const STATE_SNAPSHOT = 'admin_operations_snapshot';

    private const STATE_ALERTS = 'admin_operations_alerts';

    private const STATE_ALERT = 'admin_operations_alert';

    private const ACTION_SNAPSHOT = 'navigation.admin.operations.snapshot';

    private const ACTION_ALERTS = 'navigation.admin.operations.alerts';

    private const ACTION_ALERT_VIEW = 'navigation.admin.operations.alert_view';

    private const ACTION_ALERT_ACK = 'navigation.admin.operations.alert_ack';

    private const ACTION_ALERT_RESOLVE = 'navigation.admin.operations.alert_resolve';

    private const ACTION_DISPATCH_OUTBOX = 'navigation.admin.operations.dispatch_outbox';

    private const ACTION_BACK = 'navigation.back';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private OperationsCenterService $operations,
        private OperationalAlertQueryService $alerts,
        private OperationalAlertLifecycleService $alertLifecycle,
        private OperationsCenterActionService $actions,
        private TelegramNavigationHandler $navigation,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if ($action->sessionState === 'admin_control') {
            return $action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_ENTRY;
        }

        return in_array($action->sessionState, [
            self::STATE_MENU,
            self::STATE_SNAPSHOT,
            self::STATE_ALERTS,
            self::STATE_ALERT,
        ], true);
    }

    /** @requirement OPS-001 OPS-002 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 */
    public function handle(TelegramInteractionAction $action): void
    {
        try {
            if ($action->sessionState === 'admin_control') {
                if ($action->kind !== TelegramInteractionActionKind::Callback
                    || $action->callbackAction !== self::ACTION_ENTRY
                    || $action->callbackPayload !== []
                ) {
                    throw new RuntimeException('Telegram Operations Center entry is invalid.');
                }

                $this->showMenu($action);

                return;
            }

            match ($action->sessionState) {
                self::STATE_MENU => $this->handleMenu($action),
                self::STATE_SNAPSHOT => $this->handleSnapshot($action),
                self::STATE_ALERTS => $this->handleAlerts($action),
                self::STATE_ALERT => $this->handleAlert($action),
                default => throw new RuntimeException('Telegram Operations Center state is unsupported.'),
            };
        } catch (AuthorizationException) {
            $this->returnToAdminControl($action);
        }
    }

    private function handleMenu(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action) || $this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        match ($action->callbackAction) {
            self::ACTION_SNAPSHOT => $this->showSnapshot($action),
            self::ACTION_ALERTS => $this->showAlerts($action),
            self::ACTION_DISPATCH_OUTBOX => $this->dispatchOutbox($action),
            default => throw new RuntimeException('Telegram Operations Center menu action is unsupported.'),
        };
    }

    private function handleSnapshot(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind === TelegramInteractionActionKind::Callback
            && $action->callbackAction === self::ACTION_SNAPSHOT
            && $action->callbackPayload === []
        ) {
            $this->showSnapshot($action);

            return;
        }
    }

    private function handleAlerts(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showMenu($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            return;
        }
        if ($action->callbackAction === self::ACTION_ALERTS && $action->callbackPayload === []) {
            $this->showAlerts($action);

            return;
        }
        if ($action->callbackAction !== self::ACTION_ALERT_VIEW) {
            throw new RuntimeException('Telegram Operations Center alert-list action is unsupported.');
        }

        $alertId = $action->callbackPayload['alert'] ?? null;
        if (! is_string($alertId)) {
            throw new RuntimeException('Telegram Operations Center alert identity is invalid.');
        }

        $this->showAlert($action, $alertId);
    }

    private function handleAlert(TelegramInteractionAction $action): void
    {
        if ($this->isBack($action)) {
            $this->showAlerts($action);

            return;
        }
        if ($this->isEntryCommand($action->messageText)) {
            $this->returnToAdminControl($action);

            return;
        }
        if ($action->kind !== TelegramInteractionActionKind::Callback || $action->callbackPayload !== []) {
            return;
        }

        $alertId = $action->sessionPayload['alert'] ?? null;
        if (! is_string($alertId)) {
            throw new RuntimeException('Telegram Operations Center alert session is invalid.');
        }

        try {
            if ($action->callbackAction === self::ACTION_ALERT_ACK) {
                $this->alertLifecycle->acknowledge(
                    $action->userId,
                    $alertId,
                    'Acknowledged from Telegram Operations Center.',
                    $this->correlationId($action, 'alert-ack'),
                    $action->requestKey.':operations-alert-ack',
                );
                $this->showAlert($action, $alertId, 'acknowledged');

                return;
            }

            if ($action->callbackAction === self::ACTION_ALERT_RESOLVE) {
                $this->alertLifecycle->resolve(
                    $action->userId,
                    $alertId,
                    'Resolved from Telegram Operations Center.',
                    $this->correlationId($action, 'alert-resolve'),
                    $action->requestKey.':operations-alert-resolve',
                );
                $this->showAlert($action, $alertId, 'resolved');

                return;
            }
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->showAlert($action, $alertId, 'operation_unavailable');

            return;
        }

        throw new RuntimeException('Telegram Operations Center alert action is unsupported.');
    }

    private function dispatchOutbox(TelegramInteractionAction $action): void
    {
        try {
            $this->actions->dispatchDueOutbox(
                $action->userId,
                25,
                $this->correlationId($action, 'dispatch-outbox'),
                $action->requestKey.':operations-dispatch-outbox',
            );
            $this->showSnapshot($action, 'dispatch_completed');
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->showSnapshot($action, 'operation_unavailable');
        }
    }

    private function showMenu(TelegramInteractionAction $action): void
    {
        $this->administratorUsers->authorizeUser($action->userId, OperationsPermissions::VIEW);
        $session = $this->transition($action, self::STATE_MENU, [], 'menu');
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $rows = [
            [$this->callbackButton(
                $action,
                $session->version,
                self::ACTION_SNAPSHOT,
                [],
                'snapshot',
                $this->translation('telegram_operations.snapshot_button', $locale),
            )],
            [$this->callbackButton(
                $action,
                $session->version,
                self::ACTION_ALERTS,
                [],
                'alerts',
                $this->translation('telegram_operations.alerts_button', $locale),
            )],
        ];
        if ($this->administratorUsers->allowsUser($action->userId, OperationsPermissions::ACTIONS_EXECUTE)) {
            $rows[] = [$this->callbackButton(
                $action,
                $session->version,
                self::ACTION_DISPATCH_OUTBOX,
                [],
                'dispatch-outbox',
                $this->translation('telegram_operations.dispatch_outbox_button', $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $session->version, 'menu', $locale)];

        $this->queue(
            $action,
            $this->translation('telegram_operations.menu', $locale),
            'menu',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function showSnapshot(TelegramInteractionAction $action, ?string $statusKey = null): void
    {
        $snapshot = $this->operations->snapshot($action->userId);
        $session = $this->transition($action, self::STATE_SNAPSHOT, [], 'snapshot');
        if ($session === null) {
            return;
        }

        $this->renderSnapshot($action, $session->version, $snapshot, $statusKey);
    }

    private function showAlerts(TelegramInteractionAction $action): void
    {
        $alerts = $this->alerts->unresolved($action->userId, 10);
        $session = $this->transition($action, self::STATE_ALERTS, [], 'alerts');
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $lines = [$this->translation('telegram_operations.alerts_title', $locale)];
        $rows = [];
        foreach ($alerts as $alert) {
            $tracking = substr(hash('sha256', $alert['id'].':'.$alert['activation_sequence']), 0, 12);
            $lines[] = sprintf(
                '[%s] %s · %s · x%d',
                strtoupper($alert['severity']),
                mb_substr($alert['event_name'], 0, 80),
                $tracking,
                $alert['occurrence_count'],
            );
            $rows[] = [$this->callbackButton(
                $action,
                $session->version,
                self::ACTION_ALERT_VIEW,
                ['alert' => $alert['id']],
                'alert-'.$tracking,
                strtoupper($alert['severity']).' · '.mb_substr($alert['event_name'], 0, 40),
            )];
        }
        if ($alerts === []) {
            $lines[] = '';
            $lines[] = $this->translation('telegram_operations.alerts_empty', $locale);
        }
        $rows[] = [$this->callbackButton(
            $action,
            $session->version,
            self::ACTION_ALERTS,
            [],
            'alerts-refresh',
            $this->translation('telegram_operations.refresh_button', $locale),
        )];
        $rows[] = [$this->backButton($action, $session->version, 'alerts', $locale)];

        $this->queue(
            $action,
            implode("\n", $lines),
            'alerts',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function showAlert(
        TelegramInteractionAction $action,
        string $alertId,
        ?string $statusKey = null,
    ): void {
        $alert = $this->alerts->find($action->userId, $alertId);
        if ($alert === null) {
            $this->showAlerts($action);

            return;
        }

        $session = $this->transition(
            $action,
            self::STATE_ALERT,
            ['alert' => $alertId],
            'alert-detail',
        );
        if ($session === null) {
            return;
        }

        $locale = $this->locale($action->userId);
        $tracking = substr(hash('sha256', $alert['id'].':'.$alert['activation_sequence']), 0, 12);
        $lines = [
            $this->translation('telegram_operations.alert_title', $locale),
            '',
            'Severity: '.$alert['severity'],
            'Event: '.$alert['event_name'],
            'Tracking: '.$tracking,
            'Occurrences: '.$alert['occurrence_count'],
            'First: '.$alert['first_seen_at'].' UTC',
            'Last: '.$alert['last_seen_at'].' UTC',
            'Acknowledged: '.($alert['acknowledged_at'] ?? '-'),
            'Resolved: '.($alert['resolved_at'] ?? '-'),
        ];
        if ($statusKey !== null) {
            array_unshift(
                $lines,
                $this->translation('telegram_operations.'.$statusKey, $locale),
                '',
            );
        }

        $rows = [];
        if ($alert['resolved_at'] === null
            && $this->administratorUsers->allowsUser($action->userId, OperationsPermissions::ALERTS_MANAGE)
        ) {
            if ($alert['acknowledged_at'] === null) {
                $rows[] = [$this->callbackButton(
                    $action,
                    $session->version,
                    self::ACTION_ALERT_ACK,
                    [],
                    'alert-ack-'.$tracking,
                    $this->translation('telegram_operations.ack_button', $locale),
                )];
            }
            $rows[] = [$this->callbackButton(
                $action,
                $session->version,
                self::ACTION_ALERT_RESOLVE,
                [],
                'alert-resolve-'.$tracking,
                $this->translation('telegram_operations.resolve_button', $locale),
            )];
        }
        $rows[] = [$this->backButton($action, $session->version, 'alert', $locale)];

        $this->queue(
            $action,
            implode("\n", $lines),
            'alert-detail',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderSnapshot(
        TelegramInteractionAction $action,
        int $sessionVersion,
        OperationsCenterSnapshot $snapshot,
        ?string $statusKey,
    ): void {
        $locale = $this->locale($action->userId);
        $lines = [
            $this->translation('telegram_operations.snapshot_title', $locale),
            'Generated: '.$snapshot->generatedAtUtc->format('Y-m-d H:i:s').' UTC',
            '',
        ];
        if ($statusKey !== null) {
            array_unshift(
                $lines,
                $this->translation('telegram_operations.'.$statusKey, $locale),
                '',
            );
        }

        $ordered = $snapshot->facts;
        usort($ordered, static function (OperationsCenterFact $left, OperationsCenterFact $right): int {
            $priority = [
                'manual_review' => 0,
                'degraded' => 1,
                'unknown' => 2,
                'observed' => 3,
                'healthy' => 4,
                'empty' => 5,
            ];

            return ($priority[$left->state] <=> $priority[$right->state])
                ?: ($left->code <=> $right->code);
        });

        $rendered = 0;
        foreach ($ordered as $fact) {
            $line = sprintf('[%s] %s = %d', strtoupper($fact->state), $fact->code, $fact->value);
            if ($fact->detail !== null) {
                $line .= ' · '.mb_substr($fact->detail, 0, 80);
            }

            $prospective = implode("\n", [...$lines, $line]);
            if (mb_strlen($prospective) > 3300 || $rendered >= 35) {
                break;
            }

            $lines[] = $line;
            $rendered++;
        }
        if ($rendered < count($ordered)) {
            $lines[] = '… +'.(count($ordered) - $rendered);
        }

        $rows = [[
            $this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_SNAPSHOT,
                [],
                'snapshot-refresh',
                $this->translation('telegram_operations.refresh_button', $locale),
            ),
        ]];
        $rows[] = [$this->backButton($action, $sessionVersion, 'snapshot', $locale)];

        $this->queue(
            $action,
            implode("\n", $lines),
            'snapshot',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function isBack(TelegramInteractionAction $action): bool
    {
        return ($action->kind === TelegramInteractionActionKind::Callback
                && $action->callbackAction === self::ACTION_BACK
                && $action->callbackPayload === [])
            || $action->kind === TelegramInteractionActionKind::Back
            || preg_match('/\A\/back(?:@[A-Za-z0-9_]+)?\z/u', trim((string) $action->messageText)) === 1;
    }

    private function isEntryCommand(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return $normalized === '/start' || $normalized === '/menu';
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
                'tg-admin-operations-'.$surface.':'.hash('sha256', $action->requestKey.':'.$surface),
            );
        } catch (DomainException) {
            return null;
        }
        if ($session->userId !== $action->userId) {
            throw new RuntimeException('Telegram Operations Center actor binding changed.');
        }

        return $session;
    }

    private function returnToAdminControl(TelegramInteractionAction $action): void
    {
        try {
            $this->navigation->showAdminControl($action);
        } catch (DomainException) {
            // A concurrent accepted interaction already moved the session.
        }
    }

    /** @param array<string,mixed> $payload */
    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        array $payload,
        string $surface,
        string $label,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            $payload,
            'tg-admin-operations-callback:'.hash('sha256', $surface.':'.$action->requestKey),
        );

        return new TelegramInlineCallbackButton(
            $label,
            $callback->publicId,
            TelegramInlineButtonStyle::Primary,
        );
    }

    private function backButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
        string $locale,
    ): TelegramInlineCallbackButton {
        return $this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'back-'.$surface,
            $this->translation('telegram.navigation.buttons.back', $locale),
        );
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
            'tg-admin-operations-delivery:'.hash('sha256', $action->requestKey.':'.$surface),
            $this->correlationId($action, $surface),
            $keyboard,
        );
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }

    private function correlationId(TelegramInteractionAction $action, string $surface): string
    {
        return 'tg-ops:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 56);
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === ''
            || $value === '['.$key.']'
            || preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1
        ) {
            throw new RuntimeException('Telegram Operations Center translation is unavailable.');
        }

        return $value;
    }
}
