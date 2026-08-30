<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Domain\VerificationStatus;
use Illuminate\Database\Connection;

final readonly class CustomerIdentityProfilePersistence implements CustomerIdentityProfileWriter
{
    public function ensure(Connection $connection, int $userId, string $timestamp): void
    {
        $tierId = $connection->table('customer_tiers')->where('code', 'new')->value('id');
        $connection->table('customer_profiles')->insertOrIgnore([
            'user_id' => $userId,
            'current_tier_id' => is_numeric($tierId) ? (int) $tierId : null,
            'tier_locked' => false,
            'phone_verification_status' => VerificationStatus::Unverified->value,
            'identity_verification_status' => VerificationStatus::Unverified->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    public function updatePhoneVerificationStatus(
        Connection $connection,
        int $userId,
        VerificationStatus $status,
        string $timestamp,
    ): void {
        $connection->table('customer_profiles')->where('user_id', $userId)->update([
            'phone_verification_status' => $status->value,
            'updated_at' => $timestamp,
        ]);
    }

    public function updateIdentityVerificationStatus(
        Connection $connection,
        int $userId,
        VerificationStatus $status,
        string $timestamp,
    ): void {
        $connection->table('customer_profiles')->where('user_id', $userId)->update([
            'identity_verification_status' => $status->value,
            'updated_at' => $timestamp,
        ]);
    }
}
