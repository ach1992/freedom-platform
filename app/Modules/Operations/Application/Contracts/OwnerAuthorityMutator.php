<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

use Illuminate\Database\Connection;

interface OwnerAuthorityMutator
{
    public function currentOwnerUserId(Connection $connection): ?int;

    /**
     * @return array{changed: bool, administrator_id: int, user_id: int}
     */
    public function bootstrap(Connection $connection, int $targetUserId, string $timestamp): array;

    /**
     * @return array{
     *     changed: bool,
     *     previous_administrator_id: ?int,
     *     administrator_id: int,
     *     user_id: int,
     *     cancelled_transfer_count: int
     * }
     */
    public function recover(
        Connection $connection,
        int $targetUserId,
        ?int $expectedCurrentOwnerUserId,
        string $reason,
        string $timestamp,
    ): array;
}
