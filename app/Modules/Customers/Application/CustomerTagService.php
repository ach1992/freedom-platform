<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class CustomerTagService
{
    private const ASSIGN_ACTION = 'customer.tag.assign';

    private const PERMISSION = 'identity.customers.manage_tags';

    private const REMOVE_ACTION = 'customer.tag.remove';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $authorizer,
        private CustomerMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement USR-003 SEC-002 QUA-001 */
    public function assign(
        int $userId,
        string $tagCode,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        return $this->mutate(self::ASSIGN_ACTION, $userId, $tagCode, true, $context);
    }

    /** @requirement USR-003 SEC-002 QUA-001 */
    public function remove(
        int $userId,
        string $tagCode,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        return $this->mutate(self::REMOVE_ACTION, $userId, $tagCode, false, $context);
    }

    private function mutate(
        string $action,
        int $userId,
        string $tagCode,
        bool $assign,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        if ($userId < 1 || preg_match('/\A[a-z0-9_.-]{1,64}\z/', $tagCode) !== 1) {
            throw new RuntimeException('Customer tag mutation input is invalid.');
        }

        $context->requireAdministrator();

        try {
            return $this->database->connection()->transaction(
                fn (): CustomerMutationReceipt => $this->mutateWithinTransaction(
                    $action,
                    $userId,
                    $tagCode,
                    $assign,
                    $context,
                ),
            );
        } catch (QueryException $exception) {
            $existing = $this->audit->existing($action, $userId, $context->requestFingerprint);

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function mutateWithinTransaction(
        string $action,
        int $userId,
        string $tagCode,
        bool $assign,
        CustomerChangeContext $context,
    ): CustomerMutationReceipt {
        $connection = $this->database->connection();
        $administratorId = $context->requireAdministrator();
        $this->assertActiveAdministrator($connection, $administratorId);
        $this->authorizer->authorize($administratorId, self::PERMISSION);

        $existing = $this->audit->existing($action, $userId, $context->requestFingerprint, true);

        if ($existing !== null) {
            return $existing;
        }

        $customerExists = $connection->table('users')->where('id', $userId)->lockForUpdate()->exists();

        if (! $customerExists) {
            throw new RuntimeException('Customer was not found.');
        }

        $tagQuery = $connection->table('customer_tags')->where('code', $tagCode);

        if ($assign) {
            $tagQuery->where('is_active', true);
        }

        /** @var object{id: int, code: string}|null $tag */
        $tag = $tagQuery->lockForUpdate()->first(['id', 'code']);

        if ($tag === null) {
            throw new RuntimeException('Requested customer tag is unavailable.');
        }

        /** @var object{id: int, removed_at: ?string}|null $assignment */
        $assignment = $connection->table('customer_tag_assignments')
            ->where('user_id', $userId)
            ->where('tag_id', $tag->id)
            ->lockForUpdate()
            ->first(['id', 'removed_at']);
        $currentlyAssigned = $assignment !== null && $assignment->removed_at === null;
        $before = ['tag_code' => $tag->code, 'assigned' => $currentlyAssigned];
        $after = ['tag_code' => $tag->code, 'assigned' => $assign];
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        if ($assign && ! $currentlyAssigned) {
            if ($assignment === null) {
                $connection->table('customer_tag_assignments')->insert([
                    'user_id' => $userId,
                    'tag_id' => $tag->id,
                    'assigned_by_administrator_id' => $administratorId,
                    'assigned_at' => $now,
                    'removed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $connection->table('customer_tag_assignments')->where('id', $assignment->id)->update([
                    'assigned_by_administrator_id' => $administratorId,
                    'assigned_at' => $now,
                    'removed_at' => null,
                    'updated_at' => $now,
                ]);
            }
        }

        if (! $assign && $currentlyAssigned && $assignment !== null) {
            $connection->table('customer_tag_assignments')->where('id', $assignment->id)->update([
                'removed_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $this->audit->record(
            $connection,
            $action,
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