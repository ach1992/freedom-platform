<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Identity\Domain\AccountStatus;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class CustomerAccountStateService
{
    private const ACTION = 'customer.status.transition';

    private const PERMISSION = 'identity.customers.manage_status';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private CustomerMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement ONB-005 USR-003 SEC-002 QUA-001 */
    public function transition(
        int $userId,
        AccountStatus $targetStatus,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        if ($userId < 1) {
            throw new RuntimeException('Customer user ID must be positive.');
        }

        $context->requireAdministrator();

        try {
            return $this->database->connection()->transaction(
                fn (): CustomerMutationReceipt => $this->transitionWithinTransaction(
                    $userId,
                    $targetStatus,
                    $context,
                ),
            );
        } catch (QueryException $exception) {
            $existing = $this->audit->existing(self::ACTION, $userId, $context->requestFingerprint);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function transitionWithinTransaction(
        int $userId,
        AccountStatus $targetStatus,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        $connection = $this->database->connection();
        $administratorId = $context->requireAdministrator();
        $this->assertActiveAdministrator($connection, $administratorId);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        $existing = $this->audit->existing(self::ACTION, $userId, $context->requestFingerprint, true);

        if ($existing !== null) {
            return $existing;
        }

        /** @var object{account_status: string}|null $user */
        $user = $connection->table('users')
            ->where('id', $userId)
            ->lockForUpdate()
            ->first(['account_status']);

        if ($user === null) {
            throw new RuntimeException('Customer was not found.');
        }

        $currentStatus = AccountStatus::tryFrom($user->account_status);

        if ($currentStatus === null) {
            throw new RuntimeException('Customer account status is invalid.');
        }

        $before = ['account_status' => $currentStatus->value];
        $after = ['account_status' => $targetStatus->value];
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        if ($currentStatus !== $targetStatus) {
            $connection->table('users')->where('id', $userId)->update([
                'account_status' => $targetStatus->value,
                'updated_at' => $now,
            ]);

            $connection->table('customer_status_histories')->insert([
                'user_id' => $userId,
                'from_status' => $currentStatus->value,
                'to_status' => $targetStatus->value,
                'actor_administrator_id' => $context->actorAdministratorId,
                'reason_code' => $context->reasonCode,
                'reason' => $context->reason,
                'created_at' => $now,
            ]);
        }

        return $this->audit->record(
            $connection,
            self::ACTION,
            $userId,
            $context,
            $before,
            $after,
        );
    }

    private function assertActiveAdministrator(Connection $connection, int $administratorId): void
    {
        $active = $connection->table('administrators')
            ->where('id', $administratorId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->exists();

        if (! $active) {
            throw new RuntimeException('An active administrator is required.');
        }
    }
}