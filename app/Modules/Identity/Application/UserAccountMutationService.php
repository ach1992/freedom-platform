<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\AccountType;
use Illuminate\Database\Connection;

final readonly class UserAccountMutationService
{
    public function updateAccountType(
        Connection $connection,
        int $userId,
        AccountType $accountType,
        string $timestamp,
    ): int {
        return $connection->table('users')->where('id', $userId)->update([
            'account_type' => $accountType->value,
            'updated_at' => $timestamp,
        ]);
    }

    public function updateAccountStatus(
        Connection $connection,
        int $userId,
        AccountStatus $accountStatus,
        string $timestamp,
    ): int {
        return $connection->table('users')->where('id', $userId)->update([
            'account_status' => $accountStatus->value,
            'updated_at' => $timestamp,
        ]);
    }
}
