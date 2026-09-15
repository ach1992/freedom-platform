<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contracts;

use App\Modules\Identity\Domain\VerificationStatus;
use Illuminate\Database\Connection;

interface CustomerIdentityProfileWriter
{
    public function ensure(Connection $connection, int $userId, string $timestamp): void;

    public function updatePhoneVerificationStatus(
        Connection $connection,
        int $userId,
        VerificationStatus $status,
        string $timestamp,
    ): void;

    public function updateIdentityVerificationStatus(
        Connection $connection,
        int $userId,
        VerificationStatus $status,
        string $timestamp,
    ): void;
}
