<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\AccessControl\Application\AdministratorAccessManagementQueryService;
use App\Modules\AccessControl\Application\AdministratorAccessTarget;
use App\Modules\AccessControl\Application\AdministratorAccessTargetSearchDisposition;
use App\Modules\AccessControl\Application\AdministratorOwnerTransferTelegramService;
use App\Modules\AccessControl\Application\AdministratorSensitiveMutation;
use App\Modules\AccessControl\Application\AdministratorSensitiveMutationService;
use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * Telegram presentation/interaction adapter for canonical AccessControl
 * authorities. Durable authorization, mutation and approval semantics remain
 * owned by AccessControl; Telegram persists only actor-bound opaque selections.
 */
final readonly class TelegramAdministratorAccessNavigationHandler
{
    public const ACTION_ENTRY = 'navigation.admin.access';

    private const STATE_MENU = 'admin_access_menu';

    private const STATE_TARGET_SEARCH = 'admin_access_target_search';

    private const STATE_TARGET = 'admin_access_target';

    private const STATE_ROLE_INPUT = 'admin_access_role_input';

    private const STATE_PERMISSION_INPUT = 'admin_access_permission_input';

    private const STATE_ROLES = 'admin_access_roles';

    private const STATE_PERMISSIONS = 'admin_access_permissions';

    private const STATE_CUSTOM_COMMAND = 'admin_access_custom_command';

    private const STATE_CONFIRM = 'admin_access_confirm';

    private const STATE_SUBMITTING = 'admin_access_submitting';

    private const STATE_PENDING = 'admin_access_pending';

    private const STATE_APPROVALS = 'admin_access_approvals';

    private const STATE_TRANSFERS = 'admin_access_transfers';

    private const ACTION_TARGET_SEARCH = 'navigation.admin.access.target_search';

    private const ACTION_ROLES = 'navigation.admin.access.roles';

    private const ACTION_PERMISSIONS = 'navigation.admin.access.permissions';

    private const ACTION_APPROVALS = 'navigation.admin.access.approvals';

    private const ACTION_TRANSFERS = 'navigation.admin.access.transfers';

    private const ACTION_TARGET_ENABLE = 'navigation.admin.access.target.enable';

    private const ACTION_TARGET_SUSPEND = 'navigation.admin.access.target.suspend';

    private const ACTION_TARGET_REACTIVATE = 'navigation.admin.access.target.reactivate';

    private const ACTION_TARGET_REVOKE = 'navigation.admin.access.target.revoke';

    private const ACTION_TARGET_ROLE_GRANT = 'navigation.admin.access.target.role_grant';

    private const ACTION_TARGET_ROLE_REVOKE = 'navigation.admin.access.target.role_revoke';

    private const ACTION_TARGET_PERMISSION = 'navigation.admin.access.target.permission';

    private const ACTION_TARGET_OWNER_TRANSFER = 'navigation.admin.access.target.owner_transfer';

    private const ACTION_CUSTOM_COMMAND = 'navigation.admin.access.custom_command';

    private const ACTION_CONFIRM = 'navigation.admin.access.confirm';

    private const ACTION_PENDING_RETRY = 'navigation.admin.access.pending.retry';

    private const ACTION_PENDING_CANCEL = 'navigation.admin.access.pending.cancel';

    private const ACTION_APPROVE = 'navigation.admin.access.approval.approve';

    private const ACTION_REJECT = 'navigation.admin.access.approval.reject';

    private const ACTION_TRANSFER_ACCEPT = 'navigation.admin.access.transfer.accept';

    private const ACTION_TRANSFER_CANCEL = 'navigation.admin.access.transfer.cancel';

    private const ACTION_BACK = 'navigation.back';

    private const SPECIAL_OWNER_TRANSFER_REQUEST = 'owner_transfer_request';

    private const SPECIAL_OWNER_TRANSFER_ACCEPT = 'owner_transfer_accept';

    private const SPECIAL_OWNER_TRANSFER_CANCEL = 'owner_transfer_cancel';

    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramInteractionSessionService $sessions,
        private TelegramInteractionCallbackService $callbacks,
        private AdministratorAccessManagementQueryService $queries,
        private AdministratorSensitiveMutationService $mutations,
        private AdministratorOwnerTransferTelegramService $ownerTransfers,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
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
            self::STATE_TARGET_SEARCH,
            self::STATE_TARGET,
            self::STATE_ROLE_INPUT,
            self::STATE_PERMISSION_INPUT,
            self::STATE_ROLES,
            self::STATE_PERMISSIONS,
            self::STATE_CUSTOM_COMMAND,
            self::STATE_CONFIRM,
            self::STATE_PENDING,
            self::STATE_APPROVALS,
            self::STATE_TRANSFERS,
        ], true);
    }

    /** @requirement ADM-002 ACL-001 ACL-002 ACL-003 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 */
    public function handle(TelegramInteractionAction $action): void
    {
        if ($action->sessionState === 'admin_control') {
            if ($action->kind !== TelegramInteractionActionKind::Callback
                || $action->callbackAction !== self::ACTION_ENTRY
                || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator access entry is invalid.');
            }

            $this->showMenu($action);

            return;
        }

        match ($action->sessionState) {
            self::STATE_MENU => $this->handleMenu($action),
            self::STATE_TARGET_SEARCH => $this->handleTargetSearch($action),
            self::STATE_TARGET => $this->handleTarget($action),
            self::STATE_ROLE_INPUT => $this->handleRoleInput($action),
            self::STATE_PERMISSION_INPUT => $this->handlePermissionInput($action),
            self::STATE_ROLES => $this->handleRoles($action),
            self::STATE_PERMISSIONS => $this->handlePermissions($action),
            self::STATE_CUSTOM_COMMAND => $this->handleCustomCommand($action),
            self::STATE_CONFIRM => $this->handleConfirmation($action),
            self::STATE_PENDING => $this->handlePending($action),
            self::STATE_APPROVALS => $this->handleApprovals($action),
            self::STATE_TRANSFERS => $this->handleTransfers($action),
            default => throw new RuntimeException('Telegram administrator access state is unsupported.'),
        };
    }

    private function handleMenu(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator access menu payload is unsupported.');
            }

            match ($action->callbackAction) {
                self::ACTION_TARGET_SEARCH => $this->showTargetSearch($action),
                self::ACTION_ROLES => $this->showRoles($action),
                self::ACTION_PERMISSIONS => $this->showPermissions($action, null),
                self::ACTION_APPROVALS => $this->showApprovals($action),
                self::ACTION_TRANSFERS => $this->showTransfers($action),
                self::ACTION_BACK => $this->navigation->showAdminControl($action),
                default => throw new RuntimeException('Telegram administrator access menu action is unsupported.'),
            };

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->navigation->showAdminControl($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleTargetSearch(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator access search callback is unsupported.');
            }

            $this->showMenu($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMenu($action);

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
            $result = $this->queries->searchTarget(
                $action->userId,
                $action->botId,
                $action->messageText,
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        }

        if ($result->disposition !== AdministratorAccessTargetSearchDisposition::Matched
            || $result->target === null) {
            $surface = $result->disposition === AdministratorAccessTargetSearchDisposition::Ambiguous
                ? 'ambiguous'
                : 'not_found';
            $this->renderTargetSearch($action, $action->sessionVersion, $surface);

            return;
        }

        $this->transitionToTarget($action, $result->target->selectionToken);
    }

    private function handleTarget(TelegramInteractionAction $action): void
    {
        $targetSelection = $this->targetSelectionFromPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator target callback payload is unsupported.');
            }

            match ($action->callbackAction) {
                self::ACTION_TARGET_ENABLE => $this->prepareMutationConfirmation(
                    $action,
                    AdministratorSensitiveMutation::ADMINISTRATOR_ENABLE,
                    $targetSelection,
                ),
                self::ACTION_TARGET_SUSPEND => $this->prepareMutationConfirmation(
                    $action,
                    AdministratorSensitiveMutation::ADMINISTRATOR_SUSPEND,
                    $targetSelection,
                ),
                self::ACTION_TARGET_REACTIVATE => $this->prepareMutationConfirmation(
                    $action,
                    AdministratorSensitiveMutation::ADMINISTRATOR_REACTIVATE,
                    $targetSelection,
                ),
                self::ACTION_TARGET_REVOKE => $this->prepareMutationConfirmation(
                    $action,
                    AdministratorSensitiveMutation::ADMINISTRATOR_REVOKE,
                    $targetSelection,
                ),
                self::ACTION_TARGET_ROLE_GRANT => $this->showRoleInput(
                    $action,
                    AdministratorSensitiveMutation::ROLE_GRANT,
                    $targetSelection,
                ),
                self::ACTION_TARGET_ROLE_REVOKE => $this->showRoleInput(
                    $action,
                    AdministratorSensitiveMutation::ROLE_REVOKE,
                    $targetSelection,
                ),
                self::ACTION_TARGET_PERMISSION => $this->showPermissionInput($action, $targetSelection),
                self::ACTION_TARGET_OWNER_TRANSFER => $this->prepareSpecialConfirmation(
                    $action,
                    self::SPECIAL_OWNER_TRANSFER_REQUEST,
                    ['target' => $targetSelection],
                ),
                self::ACTION_BACK => $this->showTargetSearch($action),
                default => throw new RuntimeException('Telegram administrator target action is unsupported.'),
            };

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showTargetSearch($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleRoleInput(TelegramInteractionAction $action): void
    {
        [$operation, $targetSelection] = $this->roleInputPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator role input callback is unsupported.');
            }

            $this->transitionToTarget($action, $targetSelection);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->transitionToTarget($action, $targetSelection);

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
            $roleSelection = $this->queries->roleSelectionToken($action->userId, trim($action->messageText));
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException) {
            $this->renderRoleInput($action, $action->sessionVersion, $operation, 'invalid');

            return;
        }

        $this->transitionToConfirmation($action, [
            'operation' => $operation,
            'role' => $roleSelection,
            'target' => $targetSelection,
        ]);
    }

    private function handlePermissionInput(TelegramInteractionAction $action): void
    {
        $targetSelection = $this->permissionInputPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator permission input callback is unsupported.');
            }

            $this->transitionToTarget($action, $targetSelection);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->transitionToTarget($action, $targetSelection);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        if ($action->messageText === null) {
            return;
        }

        $input = strtolower(trim($action->messageText));
        if (preg_match('/\A(allow|deny|inherit)\s+([a-z0-9_.-]{1,128})\z/', $input, $matches) !== 1) {
            $this->renderPermissionInput($action, $action->sessionVersion, 'invalid');

            return;
        }

        $operation = match ($matches[1]) {
            'allow' => AdministratorSensitiveMutation::PERMISSION_ALLOW,
            'deny' => AdministratorSensitiveMutation::PERMISSION_DENY,
            'inherit' => AdministratorSensitiveMutation::PERMISSION_INHERIT,
            default => throw new RuntimeException('Telegram administrator permission effect is invalid.'),
        };

        try {
            $permissionSelection = $this->queries->permissionSelectionToken(
                $action->userId,
                $matches[2],
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException) {
            $this->renderPermissionInput($action, $action->sessionVersion, 'invalid');

            return;
        }

        $this->transitionToConfirmation($action, [
            'operation' => $operation,
            'permission' => $permissionSelection,
            'target' => $targetSelection,
        ]);
    }

    private function handleRoles(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator role catalog payload is unsupported.');
            }

            match ($action->callbackAction) {
                self::ACTION_CUSTOM_COMMAND => $this->showCustomCommand($action),
                self::ACTION_BACK => $this->showMenu($action),
                default => throw new RuntimeException('Telegram administrator role catalog action is unsupported.'),
            };

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMenu($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handlePermissions(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator permission catalog callback is unsupported.');
            }

            $this->showMenu($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMenu($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        if ($action->messageText !== null) {
            $this->renderPermissions($action, $action->sessionVersion, trim($action->messageText));
        }
    }

    private function handleCustomCommand(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction !== self::ACTION_BACK || $action->callbackPayload !== []) {
                throw new RuntimeException('Telegram custom role command callback is unsupported.');
            }

            $this->showRoles($action);

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showRoles($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);

            return;
        }

        if ($action->messageText === null) {
            return;
        }

        $this->prepareCustomCommand($action, strtolower(trim($action->messageText)));
    }

    private function handleConfirmation(TelegramInteractionAction $action): void
    {
        if ($action->kind !== TelegramInteractionActionKind::Callback) {
            if ($action->kind === TelegramInteractionActionKind::Back) {
                $this->returnFromConfirmation($action);

                return;
            }
            if ($this->isEntryCommand($action->messageText)) {
                $this->returnHome($action);
            }

            return;
        }

        if ($action->callbackPayload !== []) {
            throw new RuntimeException('Telegram administrator confirmation callback payload is unsupported.');
        }

        if ($action->callbackAction === self::ACTION_BACK) {
            $this->returnFromConfirmation($action);

            return;
        }
        if ($action->callbackAction !== self::ACTION_CONFIRM) {
            throw new RuntimeException('Telegram administrator confirmation action is unsupported.');
        }

        if (array_key_exists('special', $action->sessionPayload)) {
            $this->executeSpecialConfirmation($action);

            return;
        }

        $this->executeMutationConfirmation($action);
    }

    private function handlePending(TelegramInteractionAction $action): void
    {
        $payload = $this->pendingPayload($action->sessionPayload);

        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackPayload !== []) {
                throw new RuntimeException('Telegram administrator pending callback payload is unsupported.');
            }

            if ($action->callbackAction === self::ACTION_PENDING_RETRY) {
                $this->retryPendingMutation($action, $payload);

                return;
            }
            if ($action->callbackAction === self::ACTION_PENDING_CANCEL) {
                $this->cancelPendingMutation($action, $payload);

                return;
            }

            throw new RuntimeException('Telegram administrator pending action is unsupported.');
        }

        if ($action->kind === TelegramInteractionActionKind::Back
            || $this->isEntryCommand($action->messageText)) {
            $this->renderPending($action, $action->sessionVersion, $payload, 'pending');
        }
    }

    private function handleApprovals(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showMenu($action);

                return;
            }

            if (! in_array($action->callbackAction, [self::ACTION_APPROVE, self::ACTION_REJECT], true)) {
                throw new RuntimeException('Telegram sensitive approval action is unsupported.');
            }
            $selection = $this->singleSelectionPayload($action->callbackPayload, 'approval');

            $this->decideApproval(
                $action,
                $selection,
                $action->callbackAction === self::ACTION_APPROVE,
            );

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMenu($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function handleTransfers(TelegramInteractionAction $action): void
    {
        if ($action->kind === TelegramInteractionActionKind::Callback) {
            if ($action->callbackAction === self::ACTION_BACK && $action->callbackPayload === []) {
                $this->showMenu($action);

                return;
            }

            if (! in_array($action->callbackAction, [self::ACTION_TRANSFER_ACCEPT, self::ACTION_TRANSFER_CANCEL], true)) {
                throw new RuntimeException('Telegram Owner transfer action is unsupported.');
            }
            $selection = $this->singleSelectionPayload($action->callbackPayload, 'transfer');
            $special = $action->callbackAction === self::ACTION_TRANSFER_ACCEPT
                ? self::SPECIAL_OWNER_TRANSFER_ACCEPT
                : self::SPECIAL_OWNER_TRANSFER_CANCEL;

            $this->prepareSpecialConfirmation(
                $action,
                $special,
                ['transfer' => $selection],
            );

            return;
        }

        if ($action->kind === TelegramInteractionActionKind::Back) {
            $this->showMenu($action);

            return;
        }

        if ($this->isEntryCommand($action->messageText)) {
            $this->returnHome($action);
        }
    }

    private function showMenu(TelegramInteractionAction $action): void
    {
        if (! $this->queries->availableForUser($action->userId)) {
            $this->returnToAdminControlFailClosed($action);

            return;
        }

        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_MENU,
                [],
                'tg-admin-access-menu:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderMenu($action, $session->version);
    }

    private function showTargetSearch(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_TARGET_SEARCH,
                [],
                'tg-admin-access-target-search:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderTargetSearch($action, $session->version, 'prompt');
    }

    private function transitionToTarget(TelegramInteractionAction $action, string $selection): void
    {
        try {
            $target = $this->queries->resolveTarget($action->userId, $action->botId, $selection);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_TARGET,
                ['target' => $selection],
                'tg-admin-access-target:'.hash('sha256', $action->requestKey.':'.$selection),
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderTarget($action, $session->version, $target);
    }

    private function showRoleInput(
        TelegramInteractionAction $action,
        string $operation,
        string $targetSelection,
    ): void {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_ROLE_INPUT,
                ['operation' => $operation, 'target' => $targetSelection],
                'tg-admin-access-role-input:'.hash('sha256', $action->requestKey.':'.$operation),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderRoleInput($action, $session->version, $operation, 'prompt');
    }

    private function showPermissionInput(TelegramInteractionAction $action, string $targetSelection): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PERMISSION_INPUT,
                ['target' => $targetSelection],
                'tg-admin-access-permission-input:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderPermissionInput($action, $session->version, 'prompt');
    }

    private function showRoles(TelegramInteractionAction $action): void
    {
        try {
            $roles = $this->queries->roles($action->userId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_ROLES,
                [],
                'tg-admin-access-roles:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderRoles($action, $session->version, $roles);
    }

    private function showPermissions(TelegramInteractionAction $action, ?string $filter): void
    {
        try {
            $this->queries->permissions($action->userId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_PERMISSIONS,
                [],
                'tg-admin-access-permissions:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderPermissions($action, $session->version, $filter);
    }

    private function showCustomCommand(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_CUSTOM_COMMAND,
                [],
                'tg-admin-access-custom-command:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderCustomCommand($action, $session->version, 'prompt');
    }

    private function showApprovals(TelegramInteractionAction $action): void
    {
        try {
            $approvals = $this->queries->pendingApprovals($action->userId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_APPROVALS,
                [],
                'tg-admin-access-approvals:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->showMenu($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderApprovals($action, $session->version, $approvals);
    }

    private function showTransfers(TelegramInteractionAction $action): void
    {
        try {
            $transfers = $this->queries->ownerTransfersForUser($action->userId);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_TRANSFERS,
                [],
                'tg-admin-access-transfers:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->showMenu($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderTransfers($action, $session->version, $transfers);
    }

    private function prepareMutationConfirmation(
        TelegramInteractionAction $action,
        string $operation,
        string $targetSelection,
    ): void {
        $this->transitionToConfirmation($action, [
            'operation' => $operation,
            'target' => $targetSelection,
        ]);
    }

    /** @param array<string, string> $descriptor */
    private function transitionToConfirmation(TelegramInteractionAction $action, array $descriptor): void
    {
        try {
            $mutation = $this->mutationFromDescriptor($action, $descriptor);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_CONFIRM,
                $descriptor,
                'tg-admin-access-confirm-preview:'.hash('sha256', $action->requestKey),
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (InvalidArgumentException|RuntimeException) {
            $this->renderInputErrorForDescriptor($action, $descriptor);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderConfirmation(
            $action,
            $session->version,
            $mutation->safeSummary(),
        );
    }

    /**
     * @param array<string, string> $extra
     */
    private function prepareSpecialConfirmation(
        TelegramInteractionAction $action,
        string $special,
        array $extra,
    ): void {
        $payload = ['special' => $special] + $extra;

        try {
            $this->assertSpecialDescriptor($action, $payload);
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                self::STATE_CONFIRM,
                $payload,
                'tg-admin-access-special-preview:'.hash('sha256', $action->requestKey.':'.$special),
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->renderConfirmation(
            $action,
            $session->version,
            $this->specialSummary($action, $payload),
        );
    }

    private function prepareCustomCommand(TelegramInteractionAction $action, string $input): void
    {
        $descriptor = null;

        if (preg_match('/\Acreate\s+(custom\.[a-z0-9][a-z0-9_.-]{0,55})\z/', $input, $matches) === 1) {
            $descriptor = [
                'operation' => AdministratorSensitiveMutation::CUSTOM_ROLE_CREATE,
                'role_code' => $matches[1],
            ];
        } elseif (preg_match('/\A(enable|disable)\s+(custom\.[a-z0-9][a-z0-9_.-]{0,55})\z/', $input, $matches) === 1) {
            try {
                $role = $this->queries->roleSelectionToken($action->userId, $matches[2]);
            } catch (RuntimeException|AuthorizationException) {
                $this->renderCustomCommand($action, $action->sessionVersion, 'invalid');

                return;
            }
            $descriptor = [
                'operation' => $matches[1] === 'enable'
                    ? AdministratorSensitiveMutation::CUSTOM_ROLE_ENABLE
                    : AdministratorSensitiveMutation::CUSTOM_ROLE_DISABLE,
                'role' => $role,
            ];
        } elseif (preg_match(
            '/\A(grant|revoke)\s+(custom\.[a-z0-9][a-z0-9_.-]{0,55})\s+([a-z0-9_.-]{1,128})\z/',
            $input,
            $matches,
        ) === 1) {
            try {
                $role = $this->queries->roleSelectionToken($action->userId, $matches[2]);
                $permission = $this->queries->permissionSelectionToken($action->userId, $matches[3]);
            } catch (RuntimeException|AuthorizationException) {
                $this->renderCustomCommand($action, $action->sessionVersion, 'invalid');

                return;
            }
            $descriptor = [
                'operation' => $matches[1] === 'grant'
                    ? AdministratorSensitiveMutation::CUSTOM_ROLE_PERMISSION_GRANT
                    : AdministratorSensitiveMutation::CUSTOM_ROLE_PERMISSION_REVOKE,
                'permission' => $permission,
                'role' => $role,
            ];
        }

        if ($descriptor === null) {
            $this->renderCustomCommand($action, $action->sessionVersion, 'invalid');

            return;
        }

        $this->transitionToConfirmation($action, $descriptor);
    }

    private function executeMutationConfirmation(TelegramInteractionAction $action): void
    {
        $descriptor = $this->mutationDescriptor($action->sessionPayload);

        try {
            [$session, $pending, $changed] = $this->database->connection()->transaction(
                function () use ($action, $descriptor): array {
                    $operationKey = $this->effectKey($action);
                    $claim = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_SUBMITTING,
                        $descriptor,
                        'tg-admin-access-claim:'.hash('sha256', $action->requestKey),
                    );
                    $this->assertActor($action, $claim->userId);

                    $mutation = $this->mutationFromDescriptor($action, $descriptor);
                    $approval = $this->mutations->request(
                        $action->userId,
                        $mutation,
                        $this->mutationReason($mutation),
                        $operationKey.':request',
                    );
                    $approvalSelection = $this->queries->approvalSelectionTokenForUser(
                        $action->userId,
                        $approval->approvalId,
                    );

                    if (! $this->queries->actorIsOwner($action->userId)) {
                        $pendingPayload = $descriptor + [
                            'approval' => $approvalSelection,
                            'cancel_locked' => true,
                        ];
                        $session = $this->sessions->transition(
                            $action->sessionPublicId,
                            $claim->version,
                            self::STATE_PENDING,
                            $pendingPayload,
                            'tg-admin-access-pending:'.hash('sha256', $action->requestKey),
                        );
                        $this->assertActor($action, $session->userId);

                        return [$session, true, false];
                    }

                    $this->mutations->approve(
                        $action->userId,
                        $approval->approvalId,
                        $this->translation('telegram.navigation.admin.access.audit.owner_approval', $this->locale($action->userId)),
                        $operationKey.':approve',
                    );
                    $result = $this->mutations->execute(
                        $action->userId,
                        $approval->approvalId,
                        $mutation,
                        $this->mutationReason($mutation),
                        $operationKey.':execute',
                    );
                    $session = $this->transitionAfterMutation(
                        $action,
                        $claim->version,
                        $descriptor,
                        'confirmed',
                    );

                    return [$session, false, $result->changed];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException|DomainException) {
            $this->renderConfirmation(
                $action,
                $action->sessionVersion,
                $this->descriptorSummary($action, $descriptor),
                true,
            );

            return;
        }

        if ($pending) {
            $this->renderPending(
                $action,
                $session->version,
                $this->pendingPayload($session->payload),
                'pending',
            );

            return;
        }

        $this->renderPostMutation($action, $descriptor, $session->version, $changed);
    }

    /** @param array<string, mixed> $payload */
    private function retryPendingMutation(TelegramInteractionAction $action, array $payload): void
    {
        $descriptor = $this->descriptorFromPending($payload);
        $approvalSelection = $payload['approval'];

        try {
            [$session, $changed] = $this->database->connection()->transaction(
                function () use ($action, $descriptor, $approvalSelection): array {
                    $operationKey = $this->effectKey($action);
                    $claim = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_SUBMITTING,
                        $descriptor + ['approval' => $approvalSelection],
                        'tg-admin-access-retry-claim:'.hash('sha256', $action->requestKey),
                    );
                    $this->assertActor($action, $claim->userId);

                    $mutation = $this->mutationFromDescriptor($action, $descriptor);
                    $approvalId = $this->queries->resolveApprovalSelectionToken(
                        $action->userId,
                        $approvalSelection,
                    );
                    $result = $this->mutations->execute(
                        $action->userId,
                        $approvalId,
                        $mutation,
                        $this->mutationReason($mutation),
                        $operationKey.':execute',
                    );
                    $session = $this->transitionAfterMutation(
                        $action,
                        $claim->version,
                        $descriptor,
                        'approved',
                    );

                    return [$session, $result->changed];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException|DomainException) {
            $this->renderPending($action, $action->sessionVersion, $payload, 'not_ready');

            return;
        }

        $this->renderPostMutation($action, $descriptor, $session->version, $changed);
    }

    /** @param array<string, mixed> $payload */
    private function cancelPendingMutation(TelegramInteractionAction $action, array $payload): void
    {
        try {
            $approvalId = $this->queries->resolveApprovalSelectionToken(
                $action->userId,
                $payload['approval'],
            );
            $descriptor = $this->descriptorFromPending($payload);
            $session = $this->database->connection()->transaction(
                function () use ($action, $approvalId, $descriptor): TelegramInteractionSessionReceipt {
                    $this->mutations->cancel(
                        $action->userId,
                        $approvalId,
                        $this->translation('telegram.navigation.admin.access.audit.cancelled', $this->locale($action->userId)),
                        $this->effectKey($action).':cancel',
                    );

                    return $this->transitionAfterMutation(
                        $action,
                        $action->sessionVersion,
                        $descriptor,
                        'cancelled',
                    );
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException|DomainException) {
            $this->renderPending($action, $action->sessionVersion, $payload, 'not_ready');

            return;
        }

        $this->renderPostMutation($action, $descriptor, $session->version, false);
    }

    private function decideApproval(
        TelegramInteractionAction $action,
        string $selection,
        bool $approve,
    ): void {
        try {
            [$session, $approvals] = $this->database->connection()->transaction(
                function () use ($action, $selection, $approve): array {
                    $approvalId = $this->queries->resolveApprovalSelectionToken(
                        $action->userId,
                        $selection,
                    );
                    if ($approve) {
                        $this->mutations->approve(
                            $action->userId,
                            $approvalId,
                            $this->translation('telegram.navigation.admin.access.audit.approved', $this->locale($action->userId)),
                            $this->effectKey($action).':approval-approve',
                        );
                    } else {
                        $this->mutations->reject(
                            $action->userId,
                            $approvalId,
                            $this->translation('telegram.navigation.admin.access.audit.rejected', $this->locale($action->userId)),
                            $this->effectKey($action).':approval-reject',
                        );
                    }

                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_APPROVALS,
                        [],
                        'tg-admin-access-approval-decision:'.hash('sha256', $action->requestKey),
                    );
                    $approvals = $this->queries->pendingApprovals($action->userId);

                    return [$session, $approvals];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->showMenu($action);

            return;
        } catch (RuntimeException|DomainException) {
            $this->renderApprovals(
                $action,
                $action->sessionVersion,
                $this->queries->pendingApprovals($action->userId),
                true,
            );

            return;
        }

        $this->renderApprovals($action, $session->version, $approvals, false, true);
    }

    private function executeSpecialConfirmation(TelegramInteractionAction $action): void
    {
        $payload = $this->specialDescriptor($action->sessionPayload);
        $special = $payload['special'];

        try {
            [$session, $resultSurface] = $this->database->connection()->transaction(
                function () use ($action, $payload, $special): array {
                    $claim = $this->sessions->transition(
                        $action->sessionPublicId,
                        $action->sessionVersion,
                        self::STATE_SUBMITTING,
                        $payload,
                        'tg-admin-access-special-claim:'.hash('sha256', $action->requestKey),
                    );
                    $this->assertActor($action, $claim->userId);

                    if ($special === self::SPECIAL_OWNER_TRANSFER_REQUEST) {
                        $this->ownerTransfers->request(
                            $action->userId,
                            $action->botId,
                            $payload['target'],
                            $this->translation('telegram.navigation.admin.access.audit.owner_transfer_request', $this->locale($action->userId)),
                            $this->effectKey($action).':owner-request',
                        );
                    } elseif ($special === self::SPECIAL_OWNER_TRANSFER_ACCEPT) {
                        $this->ownerTransfers->accept(
                            $action->userId,
                            $payload['transfer'],
                            $this->translation('telegram.navigation.admin.access.audit.owner_transfer_accept', $this->locale($action->userId)),
                            $this->effectKey($action).':owner-accept',
                        );
                    } elseif ($special === self::SPECIAL_OWNER_TRANSFER_CANCEL) {
                        $this->ownerTransfers->cancel(
                            $action->userId,
                            $payload['transfer'],
                            $this->translation('telegram.navigation.admin.access.audit.owner_transfer_cancel', $this->locale($action->userId)),
                            $this->effectKey($action).':owner-cancel',
                        );
                    } else {
                        throw new RuntimeException('Telegram Owner transfer operation is unsupported.');
                    }

                    $session = $this->sessions->transition(
                        $action->sessionPublicId,
                        $claim->version,
                        self::STATE_TRANSFERS,
                        [],
                        'tg-admin-access-special-final:'.hash('sha256', $action->requestKey),
                    );
                    $this->assertActor($action, $session->userId);

                    return [$session, 'transfers'];
                },
                3,
            );
        } catch (AuthorizationException) {
            $this->returnToAdminControlFailClosed($action);

            return;
        } catch (RuntimeException|DomainException) {
            $this->renderConfirmation(
                $action,
                $action->sessionVersion,
                $this->specialSummary($action, $payload),
                true,
            );

            return;
        }

        if ($resultSurface === 'transfers') {
            $this->renderTransfers(
                $action,
                $session->version,
                $this->queries->ownerTransfersForUser($action->userId),
                false,
                true,
            );
        }
    }

    private function returnFromConfirmation(TelegramInteractionAction $action): void
    {
        if (array_key_exists('special', $action->sessionPayload)) {
            $payload = $this->specialDescriptor($action->sessionPayload);
            if (($payload['target'] ?? null) !== null) {
                $this->transitionToTarget($action, $payload['target']);

                return;
            }

            $this->showTransfers($action);

            return;
        }

        $descriptor = $this->mutationDescriptor($action->sessionPayload);
        if (isset($descriptor['target'])) {
            $this->transitionToTarget($action, $descriptor['target']);

            return;
        }

        $this->showRoles($action);
    }

    /** @param array<string, string> $descriptor */
    private function transitionAfterMutation(
        TelegramInteractionAction $action,
        int $expectedVersion,
        array $descriptor,
        string $suffix,
    ): TelegramInteractionSessionReceipt {
        $target = $descriptor['target'] ?? null;
        $nextState = is_string($target) ? self::STATE_TARGET : self::STATE_ROLES;
        $nextPayload = is_string($target) ? ['target' => $target] : [];

        $session = $this->sessions->transition(
            $action->sessionPublicId,
            $expectedVersion,
            $nextState,
            $nextPayload,
            'tg-admin-access-final-'.$suffix.':'.hash('sha256', $action->requestKey),
        );
        $this->assertActor($action, $session->userId);

        return $session;
    }

    /** @param array<string, string> $descriptor */
    private function renderPostMutation(
        TelegramInteractionAction $action,
        array $descriptor,
        ?int $sessionVersion = null,
        bool $changed = true,
    ): void {
        $version = $sessionVersion ?? $action->sessionVersion;
        if (isset($descriptor['target'])) {
            try {
                $target = $this->queries->resolveTarget(
                    $action->userId,
                    $action->botId,
                    $descriptor['target'],
                );
            } catch (AuthorizationException) {
                $this->returnToAdminControlFailClosed($action);

                return;
            }
            $this->renderTarget($action, $version, $target, $changed ? 'changed' : 'unchanged');

            return;
        }

        $this->renderRoles(
            $action,
            $version,
            $this->queries->roles($action->userId),
            $changed ? 'changed' : 'unchanged',
        );
    }

    private function renderMenu(TelegramInteractionAction $action, int $sessionVersion): void
    {
        $locale = $this->locale($action->userId);
        $rows = [];

        $search = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_TARGET_SEARCH,
            [],
            'tg-admin-access-menu-search:'.$action->requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.admin.access.buttons.target_search', $locale),
            $search->publicId,
            TelegramInlineButtonStyle::Primary,
        )];

        if ($this->administratorUsers->allowsUser($action->userId, 'access.roles.manage')) {
            $roles = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_ROLES,
                [],
                'tg-admin-access-menu-roles:'.$action->requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.access.buttons.roles', $locale),
                $roles->publicId,
                TelegramInlineButtonStyle::Primary,
            )];
        }

        if ($this->administratorUsers->allowsUser($action->userId, 'access.permissions.override')
            || $this->administratorUsers->allowsUser($action->userId, 'access.roles.manage')) {
            $permissions = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_PERMISSIONS,
                [],
                'tg-admin-access-menu-permissions:'.$action->requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.access.buttons.permissions', $locale),
                $permissions->publicId,
            )];
        }

        if ($this->administratorUsers->allowsUser($action->userId, 'access.sensitive_actions.approve')) {
            $approvals = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_APPROVALS,
                [],
                'tg-admin-access-menu-approvals:'.$action->requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.access.buttons.approvals', $locale),
                $approvals->publicId,
            )];
        }

        $transfers = $this->queries->ownerTransfersForUser($action->userId);
        if ($transfers !== [] || $this->administratorUsers->allowsUser($action->userId, 'admins.transfer_ownership')) {
            $transfer = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_TRANSFERS,
                [],
                'tg-admin-access-menu-transfers:'.$action->requestKey,
            );
            $rows[] = [new TelegramInlineCallbackButton(
                $this->translation('telegram.navigation.admin.access.buttons.transfers', $locale),
                $transfer->publicId,
            )];
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-menu-back:'.$action->requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.admin.access.menu', $locale),
            'tg-admin-access-menu-delivery:'.$action->requestKey,
            'menu',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderTargetSearch(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): void {
        if (! in_array($surface, ['prompt', 'not_found', 'ambiguous'], true)) {
            throw new RuntimeException('Telegram administrator target-search surface is invalid.');
        }

        $locale = $this->locale($action->userId);
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-target-search-back:'.$action->requestKey.':'.$surface,
        );
        $text = $this->translation('telegram.navigation.admin.access.target_search.prompt', $locale);
        if ($surface !== 'prompt') {
            $text .= "\n\n".$this->translation(
                'telegram.navigation.admin.access.target_search.'.$surface,
                $locale,
            );
        }

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-target-search-delivery:'.$action->requestKey.':'.$surface,
            'target-search',
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function renderTarget(
        TelegramInteractionAction $action,
        int $sessionVersion,
        AdministratorAccessTarget $target,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $notAvailable = $this->translation('telegram.navigation.admin.access.not_available', $locale);
        $roles = $notAvailable;
        $overrides = $notAvailable;
        $audit = $notAvailable;
        $permissionVersion = $notAvailable;
        $lastAuthenticatedAt = $notAvailable;

        if ($target->hasAdministrator()) {
            try {
                $snapshot = $this->queries->forUserPublicId($action->userId, $target->userPublicId);
                $roles = $snapshot->roleCodes === [] ? $notAvailable : implode(', ', $snapshot->roleCodes);
                $overrides = $snapshot->permissionOverrides === []
                    ? $notAvailable
                    : implode("\n", $snapshot->permissionOverrides);
                $audit = $snapshot->recentAuditActions === []
                    ? $notAvailable
                    : implode("\n", $snapshot->recentAuditActions);
                $permissionVersion = (string) $snapshot->permissionVersion;
                $lastAuthenticatedAt = $snapshot->lastAuthenticatedAt ?? $notAvailable;
            } catch (RuntimeException|AuthorizationException) {
                $this->returnToAdminControlFailClosed($action);

                return;
            }
        }

        $text = $this->translation('telegram.navigation.admin.access.target.view', $locale, [
            'account_status' => $target->accountStatus,
            'account_type' => $target->accountType,
            'admin_status' => $target->administratorStatus ?? $notAvailable,
            'audit' => $audit,
            'last_authenticated' => $lastAuthenticatedAt,
            'owner' => $target->isOwner
                ? $this->translation('telegram.navigation.admin.access.yes', $locale)
                : $this->translation('telegram.navigation.admin.access.no', $locale),
            'overrides' => $overrides,
            'permission_version' => $permissionVersion,
            'roles' => $roles,
            'user_id' => $target->userPublicId,
            'username' => $target->maskedUsername ?? $notAvailable,
        ]);
        if ($notice !== null) {
            $text = $this->translation('telegram.navigation.admin.access.notices.'.$notice, $locale)."\n\n".$text;
        }

        $rows = [];
        if (! $target->hasAdministrator()
            && $this->administratorUsers->allowsUser($action->userId, 'admins.accounts.manage')) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_TARGET_ENABLE,
                'telegram.navigation.admin.access.buttons.enable',
                $locale,
                'target-enable',
                TelegramInlineButtonStyle::Success,
            )];
        } elseif ($target->hasAdministrator() && ! $target->isOwner) {
            if ($this->administratorUsers->allowsUser($action->userId, 'admins.accounts.manage')) {
                if ($target->administratorStatus === 'active') {
                    $rows[] = [$this->callbackButton(
                        $action,
                        $sessionVersion,
                        self::ACTION_TARGET_SUSPEND,
                        'telegram.navigation.admin.access.buttons.suspend',
                        $locale,
                        'target-suspend',
                    )];
                } elseif ($target->administratorStatus === 'suspended') {
                    $rows[] = [$this->callbackButton(
                        $action,
                        $sessionVersion,
                        self::ACTION_TARGET_REACTIVATE,
                        'telegram.navigation.admin.access.buttons.reactivate',
                        $locale,
                        'target-reactivate',
                        TelegramInlineButtonStyle::Success,
                    )];
                }
                if ($target->administratorStatus !== 'revoked') {
                    $rows[] = [$this->callbackButton(
                        $action,
                        $sessionVersion,
                        self::ACTION_TARGET_REVOKE,
                        'telegram.navigation.admin.access.buttons.revoke',
                        $locale,
                        'target-revoke',
                        TelegramInlineButtonStyle::Danger,
                    )];
                }
            }

            if ($this->administratorUsers->allowsUser($action->userId, 'access.roles.manage')
                && $target->administratorStatus !== 'revoked') {
                $rows[] = [
                    $this->callbackButton(
                        $action,
                        $sessionVersion,
                        self::ACTION_TARGET_ROLE_GRANT,
                        'telegram.navigation.admin.access.buttons.role_grant',
                        $locale,
                        'target-role-grant',
                    ),
                    $this->callbackButton(
                        $action,
                        $sessionVersion,
                        self::ACTION_TARGET_ROLE_REVOKE,
                        'telegram.navigation.admin.access.buttons.role_revoke',
                        $locale,
                        'target-role-revoke',
                    ),
                ];
            }

            if ($this->administratorUsers->allowsUser($action->userId, 'access.permissions.override')
                && $target->administratorStatus !== 'revoked') {
                $rows[] = [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_TARGET_PERMISSION,
                    'telegram.navigation.admin.access.buttons.permission_override',
                    $locale,
                    'target-permission',
                )];
            }

            if ($this->queries->actorIsOwner($action->userId)
                && $target->administratorStatus === 'active') {
                $rows[] = [$this->callbackButton(
                    $action,
                    $sessionVersion,
                    self::ACTION_TARGET_OWNER_TRANSFER,
                    'telegram.navigation.admin.access.buttons.owner_transfer',
                    $locale,
                    'target-owner-transfer',
                    TelegramInlineButtonStyle::Danger,
                )];
            }
        }

        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            'telegram.navigation.buttons.back',
            $locale,
            'target-back',
        )];

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-target-delivery:'.$action->requestKey,
            'target',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderRoleInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $operation,
        string $surface,
    ): void {
        if (! in_array($operation, [
            AdministratorSensitiveMutation::ROLE_GRANT,
            AdministratorSensitiveMutation::ROLE_REVOKE,
        ], true) || ! in_array($surface, ['prompt', 'invalid'], true)) {
            throw new RuntimeException('Telegram administrator role-input surface is invalid.');
        }

        $locale = $this->locale($action->userId);
        $roles = $this->queries->roles($action->userId);
        $list = implode(', ', array_map(
            static fn ($role): string => $role->code.($role->isActive ? '' : ' [inactive]'),
            array_slice($roles, 0, 30),
        ));
        $text = $this->translation('telegram.navigation.admin.access.role_input.'.$surface, $locale, [
            'action' => $this->translation(
                $operation === AdministratorSensitiveMutation::ROLE_GRANT
                    ? 'telegram.navigation.admin.access.role_input.grant'
                    : 'telegram.navigation.admin.access.role_input.revoke',
                $locale,
            ),
            'roles' => $list === '' ? $this->translation('telegram.navigation.admin.access.not_available', $locale) : $list,
        ]);

        $this->renderSimpleBack(
            $action,
            $sessionVersion,
            $text,
            'role-input-'.$surface,
        );
    }

    private function renderPermissionInput(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): void {
        if (! in_array($surface, ['prompt', 'invalid'], true)) {
            throw new RuntimeException('Telegram administrator permission-input surface is invalid.');
        }

        $locale = $this->locale($action->userId);
        $this->renderSimpleBack(
            $action,
            $sessionVersion,
            $this->translation('telegram.navigation.admin.access.permission_input.'.$surface, $locale),
            'permission-input-'.$surface,
        );
    }

    /** @param list<\App\Modules\AccessControl\Application\AdministratorRoleCatalogItem> $roles */
    private function renderRoles(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $roles,
        ?string $notice = null,
    ): void {
        $locale = $this->locale($action->userId);
        $items = [];
        foreach (array_slice($roles, 0, 30) as $role) {
            $items[] = $this->translation('telegram.navigation.admin.access.roles.item', $locale, [
                'active' => $role->isActive ? 'active' : 'inactive',
                'code' => $role->code,
                'permissions' => count($role->permissionCodes),
                'type' => $role->isSystem ? 'system' : 'custom',
            ]);
        }
        $text = $this->translation('telegram.navigation.admin.access.roles.list', $locale, [
            'items' => $items === [] ? $this->translation('telegram.navigation.admin.access.not_available', $locale) : implode("\n", $items),
        ]);
        if ($notice !== null) {
            $text = $this->translation('telegram.navigation.admin.access.notices.'.$notice, $locale)."\n\n".$text;
        }

        $rows = [];
        if ($this->administratorUsers->allowsUser($action->userId, 'access.roles.manage')) {
            $rows[] = [$this->callbackButton(
                $action,
                $sessionVersion,
                self::ACTION_CUSTOM_COMMAND,
                'telegram.navigation.admin.access.buttons.custom_role_command',
                $locale,
                'roles-custom',
                TelegramInlineButtonStyle::Primary,
            )];
        }
        $rows[] = [$this->callbackButton(
            $action,
            $sessionVersion,
            self::ACTION_BACK,
            'telegram.navigation.buttons.back',
            $locale,
            'roles-back',
        )];

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-roles-delivery:'.$action->requestKey,
            'roles',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderPermissions(
        TelegramInteractionAction $action,
        int $sessionVersion,
        ?string $filter,
    ): void {
        $locale = $this->locale($action->userId);
        $permissions = $this->queries->permissions($action->userId);
        $normalizedFilter = $filter === null ? '' : strtolower(trim($filter));
        if ($normalizedFilter !== '' && (strlen($normalizedFilter) > 64 || preg_match('/\A[a-z0-9_.-]+\z/', $normalizedFilter) !== 1)) {
            $normalizedFilter = '';
        }

        $matches = array_values(array_filter(
            $permissions,
            static fn ($permission): bool => $normalizedFilter === ''
                || str_contains($permission->code, $normalizedFilter)
                || str_contains($permission->module, $normalizedFilter),
        ));
        $items = [];
        foreach (array_slice($matches, 0, 30) as $permission) {
            $items[] = $this->translation('telegram.navigation.admin.access.permissions.item', $locale, [
                'approval' => $permission->requiresApproval ? 'approval' : 'direct',
                'code' => $permission->code,
                'module' => $permission->module,
                'risk' => $permission->riskLevel,
            ]);
        }

        $text = $this->translation('telegram.navigation.admin.access.permissions.list', $locale, [
            'items' => $items === [] ? $this->translation('telegram.navigation.admin.access.not_available', $locale) : implode("\n", $items),
            'shown' => min(count($matches), 30),
            'total' => count($matches),
        ]);

        $this->renderSimpleBack(
            $action,
            $sessionVersion,
            $text,
            'permissions',
        );
    }

    private function renderCustomCommand(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $surface,
    ): void {
        if (! in_array($surface, ['prompt', 'invalid'], true)) {
            throw new RuntimeException('Telegram custom role command surface is invalid.');
        }

        $locale = $this->locale($action->userId);
        $this->renderSimpleBack(
            $action,
            $sessionVersion,
            $this->translation('telegram.navigation.admin.access.custom_role.'.$surface, $locale),
            'custom-role-'.$surface,
        );
    }

    private function renderConfirmation(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $summary,
        bool $failed = false,
    ): void {
        $locale = $this->locale($action->userId);
        $confirm = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_CONFIRM,
            [],
            'tg-admin-access-confirm:'.$action->requestKey,
        );
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-confirm-back:'.$action->requestKey,
        );
        $text = $this->translation('telegram.navigation.admin.access.confirm', $locale, [
            'summary' => $summary,
        ]);
        if ($failed) {
            $text = $this->translation('telegram.navigation.admin.access.confirm_failed', $locale)."\n\n".$text;
        }

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-confirm-delivery:'.$action->requestKey,
            'confirm',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.confirm', $locale),
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

    /** @param array<string, mixed> $payload */
    private function renderPending(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $payload,
        string $surface,
    ): void {
        if (! in_array($surface, ['pending', 'not_ready'], true)) {
            throw new RuntimeException('Telegram administrator pending surface is invalid.');
        }

        $locale = $this->locale($action->userId);
        $retry = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_PENDING_RETRY,
            [],
            'tg-admin-access-pending-retry:'.$action->requestKey,
        );
        $cancel = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_PENDING_CANCEL,
            [],
            'tg-admin-access-pending-cancel:'.$action->requestKey,
        );

        $this->queueConfidential(
            $action,
            $this->translation('telegram.navigation.admin.access.pending.'.$surface, $locale),
            'tg-admin-access-pending-delivery:'.$action->requestKey,
            'pending',
            new TelegramInlineKeyboardSnapshot([
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.retry_approval', $locale),
                    $retry->publicId,
                    TelegramInlineButtonStyle::Primary,
                )],
                [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.cancel_approval', $locale),
                    $cancel->publicId,
                    TelegramInlineButtonStyle::Danger,
                )],
            ]),
        );
    }

    /**
     * @param list<\App\Modules\AccessControl\Application\AdministratorSensitiveApprovalSummary> $approvals
     */
    private function renderApprovals(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $approvals,
        bool $failed = false,
        bool $decided = false,
    ): void {
        $locale = $this->locale($action->userId);
        $items = [];
        $rows = [];
        foreach ($approvals as $offset => $approval) {
            $number = $offset + 1;
            $items[] = $this->translation('telegram.navigation.admin.access.approvals.item', $locale, [
                'action' => $approval->action,
                'expires' => $approval->expiresAt,
                'number' => $number,
                'reason' => $approval->reason,
                'requester' => $approval->requesterUserPublicId,
            ]);
            $approve = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_APPROVE,
                ['selection' => $approval->selectionToken],
                'tg-admin-access-approval-approve:'.$action->requestKey.':'.$number,
            );
            $reject = $this->callbacks->issue(
                $action->sessionPublicId,
                $sessionVersion,
                self::ACTION_REJECT,
                ['selection' => $approval->selectionToken],
                'tg-admin-access-approval-reject:'.$action->requestKey.':'.$number,
            );
            $rows[] = [
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.approve_number', $locale, ['number' => $number]),
                    $approve->publicId,
                    TelegramInlineButtonStyle::Success,
                ),
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.reject_number', $locale, ['number' => $number]),
                    $reject->publicId,
                    TelegramInlineButtonStyle::Danger,
                ),
            ];
        }
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-approvals-back:'.$action->requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $text = $this->translation('telegram.navigation.admin.access.approvals.list', $locale, [
            'items' => $items === [] ? $this->translation('telegram.navigation.admin.access.not_available', $locale) : implode("\n\n", $items),
        ]);
        if ($failed) {
            $text = $this->translation('telegram.navigation.admin.access.approvals.failed', $locale)."\n\n".$text;
        } elseif ($decided) {
            $text = $this->translation('telegram.navigation.admin.access.approvals.decided', $locale)."\n\n".$text;
        }

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-approvals-delivery:'.$action->requestKey,
            'approvals',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    /**
     * @param list<\App\Modules\AccessControl\Application\AdministratorOwnerTransferSummary> $transfers
     */
    private function renderTransfers(
        TelegramInteractionAction $action,
        int $sessionVersion,
        array $transfers,
        bool $failed = false,
        bool $changed = false,
    ): void {
        $locale = $this->locale($action->userId);
        $items = [];
        $rows = [];
        foreach ($transfers as $offset => $transfer) {
            $number = $offset + 1;
            $items[] = $this->translation('telegram.navigation.admin.access.transfers.item', $locale, [
                'expires' => $transfer->expiresAt,
                'number' => $number,
                'owner' => $transfer->currentOwnerUserPublicId,
                'target' => $transfer->targetUserPublicId,
            ]);

            if ($transfer->actorIsTarget) {
                $accept = $this->callbacks->issue(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::ACTION_TRANSFER_ACCEPT,
                    ['selection' => $transfer->selectionToken],
                    'tg-admin-access-transfer-accept:'.$action->requestKey.':'.$number,
                );
                $rows[] = [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.accept_transfer_number', $locale, ['number' => $number]),
                    $accept->publicId,
                    TelegramInlineButtonStyle::Danger,
                )];
            } elseif ($transfer->actorIsCurrentOwner) {
                $cancel = $this->callbacks->issue(
                    $action->sessionPublicId,
                    $sessionVersion,
                    self::ACTION_TRANSFER_CANCEL,
                    ['selection' => $transfer->selectionToken],
                    'tg-admin-access-transfer-cancel:'.$action->requestKey.':'.$number,
                );
                $rows[] = [new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.admin.access.buttons.cancel_transfer_number', $locale, ['number' => $number]),
                    $cancel->publicId,
                    TelegramInlineButtonStyle::Danger,
                )];
            }
        }

        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-transfers-back:'.$action->requestKey,
        );
        $rows[] = [new TelegramInlineCallbackButton(
            $this->translation('telegram.navigation.buttons.back', $locale),
            $back->publicId,
        )];

        $text = $this->translation('telegram.navigation.admin.access.transfers.list', $locale, [
            'items' => $items === [] ? $this->translation('telegram.navigation.admin.access.not_available', $locale) : implode("\n\n", $items),
        ]);
        if ($failed) {
            $text = $this->translation('telegram.navigation.admin.access.transfers.failed', $locale)."\n\n".$text;
        } elseif ($changed) {
            $text = $this->translation('telegram.navigation.admin.access.transfers.changed', $locale)."\n\n".$text;
        }

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-transfers-delivery:'.$action->requestKey,
            'transfers',
            new TelegramInlineKeyboardSnapshot($rows),
        );
    }

    private function renderSimpleBack(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $text,
        string $surface,
    ): void {
        $locale = $this->locale($action->userId);
        $back = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            self::ACTION_BACK,
            [],
            'tg-admin-access-'.$surface.'-back:'.$action->requestKey,
        );

        $this->queueConfidential(
            $action,
            $text,
            'tg-admin-access-'.$surface.'-delivery:'.$action->requestKey,
            $surface,
            new TelegramInlineKeyboardSnapshot([[
                new TelegramInlineCallbackButton(
                    $this->translation('telegram.navigation.buttons.back', $locale),
                    $back->publicId,
                ),
            ]]),
        );
    }

    private function callbackButton(
        TelegramInteractionAction $action,
        int $sessionVersion,
        string $callbackAction,
        string $translationKey,
        string $locale,
        string $requestSuffix,
        ?TelegramInlineButtonStyle $style = null,
    ): TelegramInlineCallbackButton {
        $callback = $this->callbacks->issue(
            $action->sessionPublicId,
            $sessionVersion,
            $callbackAction,
            [],
            'tg-admin-access-'.$requestSuffix.':'.$action->requestKey,
        );

        return new TelegramInlineCallbackButton(
            $this->translation($translationKey, $locale),
            $callback->publicId,
            $style,
        );
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function mutationDescriptor(array $payload): array
    {
        $allowed = ['operation', 'permission', 'role', 'role_code', 'target'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new RuntimeException('Telegram administrator mutation descriptor has unknown fields.');
        }

        $descriptor = [];
        foreach ($allowed as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if (! is_string($payload[$key]) || $payload[$key] === '') {
                throw new RuntimeException('Telegram administrator mutation descriptor is invalid.');
            }
            $descriptor[$key] = $payload[$key];
        }
        if (! isset($descriptor['operation'])) {
            throw new RuntimeException('Telegram administrator mutation operation is unavailable.');
        }

        return $descriptor;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function pendingPayload(array $payload): array
    {
        if (($payload['cancel_locked'] ?? null) !== true
            || ! is_string($payload['approval'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['approval']) !== 1) {
            throw new RuntimeException('Telegram administrator pending payload is invalid.');
        }
        if (array_key_exists('expiry_locked', $payload)) {
            throw new RuntimeException('Telegram administrator pending payload must remain expirable.');
        }

        $descriptor = $payload;
        unset($descriptor['approval'], $descriptor['cancel_locked']);
        $this->mutationDescriptor($descriptor);

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function descriptorFromPending(array $payload): array
    {
        $descriptor = $payload;
        unset($descriptor['approval'], $descriptor['cancel_locked']);

        return $this->mutationDescriptor($descriptor);
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function specialDescriptor(array $payload): array
    {
        if (! is_string($payload['special'] ?? null)
            || ! in_array($payload['special'], [
                self::SPECIAL_OWNER_TRANSFER_REQUEST,
                self::SPECIAL_OWNER_TRANSFER_ACCEPT,
                self::SPECIAL_OWNER_TRANSFER_CANCEL,
            ], true)) {
            throw new RuntimeException('Telegram Owner transfer descriptor is invalid.');
        }

        $expectedKeys = $payload['special'] === self::SPECIAL_OWNER_TRANSFER_REQUEST
            ? ['special', 'target']
            : ['special', 'transfer'];
        if (array_keys($payload) !== $expectedKeys) {
            throw new RuntimeException('Telegram Owner transfer descriptor shape is invalid.');
        }
        foreach ($expectedKeys as $key) {
            if (! is_string($payload[$key]) || $payload[$key] === '') {
                throw new RuntimeException('Telegram Owner transfer descriptor value is invalid.');
            }
        }

        return $payload;
    }

    /** @param array<string, string> $descriptor */
    private function mutationFromDescriptor(
        TelegramInteractionAction $action,
        array $descriptor,
    ): AdministratorSensitiveMutation {
        $operation = $descriptor['operation'] ?? throw new RuntimeException('Administrator mutation operation is unavailable.');
        $targetPublicId = null;
        $roleCode = null;
        $permissionCode = null;

        if (isset($descriptor['target'])) {
            $targetPublicId = $this->queries->resolveTarget(
                $action->userId,
                $action->botId,
                $descriptor['target'],
            )->userPublicId;
        }
        if (isset($descriptor['role'])) {
            $roleCode = $this->queries->resolveRoleSelectionToken(
                $action->userId,
                $descriptor['role'],
            );
        } elseif (isset($descriptor['role_code'])) {
            $roleCode = $descriptor['role_code'];
        }
        if (isset($descriptor['permission'])) {
            $permissionCode = $this->queries->resolvePermissionSelectionToken(
                $action->userId,
                $descriptor['permission'],
            );
        }

        return new AdministratorSensitiveMutation(
            $operation,
            $targetPublicId,
            $roleCode,
            $permissionCode,
        );
    }

    /** @param array<string, string> $descriptor */
    private function descriptorSummary(TelegramInteractionAction $action, array $descriptor): string
    {
        return $this->mutationFromDescriptor($action, $descriptor)->safeSummary();
    }

    /** @param array<string, string> $payload */
    private function assertSpecialDescriptor(TelegramInteractionAction $action, array $payload): void
    {
        $validated = $this->specialDescriptor($payload);
        if ($validated['special'] === self::SPECIAL_OWNER_TRANSFER_REQUEST) {
            $this->queries->resolveTarget($action->userId, $action->botId, $validated['target']);

            return;
        }

        $this->queries->resolveOwnerTransferSelectionToken($action->userId, $validated['transfer']);
    }

    /** @param array<string, string> $payload */
    private function specialSummary(TelegramInteractionAction $action, array $payload): string
    {
        $validated = $this->specialDescriptor($payload);
        $locale = $this->locale($action->userId);

        if ($validated['special'] === self::SPECIAL_OWNER_TRANSFER_REQUEST) {
            $target = $this->queries->resolveTarget($action->userId, $action->botId, $validated['target']);

            return $this->translation('telegram.navigation.admin.access.owner_transfer.request_summary', $locale, [
                'target' => $target->userPublicId,
            ]);
        }

        return $this->translation(
            $validated['special'] === self::SPECIAL_OWNER_TRANSFER_ACCEPT
                ? 'telegram.navigation.admin.access.owner_transfer.accept_summary'
                : 'telegram.navigation.admin.access.owner_transfer.cancel_summary',
            $locale,
        );
    }

    /** @param array<string, mixed> $payload */
    private function targetSelectionFromPayload(array $payload): string
    {
        if (array_keys($payload) !== ['target']
            || ! is_string($payload['target'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['target']) !== 1) {
            throw new RuntimeException('Telegram administrator target state is invalid.');
        }

        return $payload['target'];
    }

    /** @param array<string, mixed> $payload @return array{0:string,1:string} */
    private function roleInputPayload(array $payload): array
    {
        if (array_keys($payload) !== ['operation', 'target']
            || ! is_string($payload['operation'] ?? null)
            || ! in_array($payload['operation'], [
                AdministratorSensitiveMutation::ROLE_GRANT,
                AdministratorSensitiveMutation::ROLE_REVOKE,
            ], true)
            || ! is_string($payload['target'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['target']) !== 1) {
            throw new RuntimeException('Telegram administrator role-input state is invalid.');
        }

        return [$payload['operation'], $payload['target']];
    }

    /** @param array<string, mixed> $payload */
    private function permissionInputPayload(array $payload): string
    {
        return $this->targetSelectionFromPayload($payload);
    }

    /** @param array<string, mixed> $payload */
    private function singleSelectionPayload(array $payload, string $kind): string
    {
        if (array_keys($payload) !== ['selection']
            || ! is_string($payload['selection'] ?? null)
            || preg_match('/\A[0-9a-f]{40}\z/', $payload['selection']) !== 1) {
            throw new RuntimeException('Telegram administrator '.$kind.' selection is invalid.');
        }

        return $payload['selection'];
    }

    /** @param array<string, string> $descriptor */
    private function renderInputErrorForDescriptor(
        TelegramInteractionAction $action,
        array $descriptor,
    ): void {
        if (isset($descriptor['target'])) {
            $this->transitionToTarget($action, $descriptor['target']);

            return;
        }

        $this->renderCustomCommand($action, $action->sessionVersion, 'invalid');
    }

    private function mutationReason(AdministratorSensitiveMutation $mutation): string
    {
        return $this->translation(
            'telegram.navigation.admin.access.audit.confirmed',
            'en',
            ['summary' => $mutation->safeSummary()],
        );
    }

    private function effectKey(TelegramInteractionAction $action): string
    {
        if ($action->callbackPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $action->callbackPublicId) !== 1) {
            throw new RuntimeException('Telegram administrator access effect callback identity is unavailable.');
        }

        return 'telegram-admin-access:'.$action->callbackPublicId;
    }

    private function returnToAdminControlFailClosed(TelegramInteractionAction $action): void
    {
        try {
            $this->navigation->showAdminControl($action);
        } catch (DomainException) {
            // Another accepted interaction already moved this session.
        }
    }

    private function returnHome(TelegramInteractionAction $action): void
    {
        try {
            $session = $this->sessions->transition(
                $action->sessionPublicId,
                $action->sessionVersion,
                TelegramNavigationEntryGateway::STATE,
                [],
                'tg-admin-access-home:'.hash('sha256', $action->requestKey),
            );
        } catch (DomainException) {
            return;
        }

        $this->assertActor($action, $session->userId);
        $this->navigation->handle(new TelegramInteractionAction(
            TelegramInteractionActionKind::Message,
            $action->requestKey.':admin-access-home',
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

    /** @param  array<string, int|string>  $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $value = $this->localization->resolve($key, $replace, $locale);
        if ($value === '' || $value === '['.$key.']') {
            throw new RuntimeException('Telegram administrator access translation is unavailable.');
        }
        if (preg_match('/:[A-Za-z_][A-Za-z0-9_]*/', $value) === 1) {
            throw new RuntimeException('Telegram administrator access translation has an unresolved placeholder.');
        }

        return $value;
    }

    private function queueConfidential(
        TelegramInteractionAction $action,
        string $text,
        string $requestKey,
        string $surface,
        ?TelegramInlineKeyboardSnapshot $keyboard = null,
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
            $requestKey,
            'tg-admin-access:'.substr(hash('sha256', $action->botId.':'.$action->updateId.':'.$surface), 0, 40),
            $keyboard,
        );
    }

    private function assertActor(TelegramInteractionAction $action, int $sessionUserId): void
    {
        if ($sessionUserId !== $action->userId) {
            throw new RuntimeException('Telegram administrator access actor binding is invalid.');
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
