<?php

declare(strict_types=1);

namespace App\Modules\AccessControl\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class AdministratorOwnerTransferTelegramService
{
    private const TRANSFER_PERMISSION = 'admins.transfer_ownership';

    private const TRANSFER_TTL_SECONDS = 600;

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private AdministratorAccessManagementQueryService $queries,
        private OwnerTransferService $transfers,
    ) {}

    /** @requirement ADM-002 ACL-003 SEC-002 QUA-001 */
    public function request(
        int $actorUserId,
        string $botId,
        string $targetSelectionToken,
        string $reason,
        string $requestKey,
    ): string {
        $actorAdministratorId = $this->administrators->authorizeUser(
            $actorUserId,
            self::TRANSFER_PERMISSION,
        );
        $target = $this->queries->resolveTarget($actorUserId, $botId, $targetSelectionToken);
        if (! $target->hasAdministrator()) {
            throw new RuntimeException('Owner transfer target is not an administrator.');
        }

        $targetAdministratorId = $this->administratorIdForPublicUser($target->userPublicId);
        $receipt = $this->transfers->request(
            $targetAdministratorId,
            self::TRANSFER_TTL_SECONDS,
            $this->context(
                $actorAdministratorId,
                'request',
                $requestKey,
                $targetSelectionToken,
                $reason,
            ),
        );
        $transferId = $receipt->after['transfer_id'] ?? null;
        if (! is_string($transferId) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $transferId) !== 1) {
            throw new RuntimeException('Owner transfer request receipt is invalid.');
        }

        return $this->queries->ownerTransferSelectionTokenForUser($actorUserId, $transferId);
    }

    /** @requirement ADM-002 ACL-003 SEC-002 QUA-001 */
    public function accept(
        int $actorUserId,
        string $transferSelectionToken,
        string $reason,
        string $requestKey,
    ): AccessMutationReceipt {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $transferId = $this->queries->resolveOwnerTransferSelectionToken(
            $actorUserId,
            $transferSelectionToken,
        );

        return $this->transfers->accept(
            $transferId,
            $this->context(
                $administratorId,
                'accept',
                $requestKey,
                $transferSelectionToken,
                $reason,
            ),
        );
    }

    /** @requirement ADM-002 ACL-003 SEC-002 QUA-001 */
    public function cancel(
        int $actorUserId,
        string $transferSelectionToken,
        string $reason,
        string $requestKey,
    ): AccessMutationReceipt {
        $administratorId = $this->activeAdministratorIdForUser($actorUserId);
        $transferId = $this->queries->resolveOwnerTransferSelectionToken(
            $actorUserId,
            $transferSelectionToken,
        );

        return $this->transfers->cancel(
            $transferId,
            $this->context(
                $administratorId,
                'cancel',
                $requestKey,
                $transferSelectionToken,
                $reason,
            ),
        );
    }

    private function administratorIdForPublicUser(string $userPublicId): int
    {
        $id = $this->database->connection()
            ->table('administrators as administrator')
            ->join('users as user', 'user.id', '=', 'administrator.user_id')
            ->where('user.public_id', $userPublicId)
            ->value('administrator.id');

        $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException('Owner transfer target is not an administrator.');
        }

        return $normalized;
    }

    private function activeAdministratorIdForUser(int $userId): int
    {
        if ($userId < 1) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        $id = $this->database->connection()
            ->table('administrators')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('id');
        $normalized = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new AuthorizationException('Administrator authorization failed.');
        }

        return $normalized;
    }

    private function context(
        int $administratorId,
        string $phase,
        string $requestKey,
        string $selectionToken,
        string $reason,
    ): AccessChangeContext {
        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('Owner transfer reason is required.');
        }

        return new AccessChangeContext(
            hash('sha256', implode('|', ['telegram-owner-transfer', $phase, $requestKey, $selectionToken])),
            'tg-owner:'.substr(hash('sha256', 'owner-transfer|'.$requestKey.'|'.$selectionToken), 0, 48),
            'telegram_owner_transfer_'.$phase,
            $normalizedReason,
            $administratorId,
        );
    }
}
