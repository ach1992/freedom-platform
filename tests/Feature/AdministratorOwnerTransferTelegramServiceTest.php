<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorAccessManagementQueryService;
use App\Modules\AccessControl\Application\AdministratorOwnerTransferTelegramService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement ADM-002 ACL-003 SEC-002 DAT-003 QUA-001 */
final class AdministratorOwnerTransferTelegramServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_owner_requests_and_target_accepts_using_separate_actor_bound_tokens(): void
    {
        [$ownerUserId, $ownerAdministratorId] = $this->administrator(true);
        [$targetUserId, $targetAdministratorId, $targetPublicId] = $this->administrator();
        $botId = '123456789';

        $queries = $this->app->make(AdministratorAccessManagementQueryService::class);
        $adapter = $this->app->make(AdministratorOwnerTransferTelegramService::class);
        $target = $queries->searchTarget($ownerUserId, $botId, $targetPublicId);
        self::assertNotNull($target->target);

        $ownerTransferToken = $adapter->request(
            $ownerUserId,
            $botId,
            $target->target->selectionToken,
            'Transfer ownership test.',
            'owner-transfer-request-0001',
        );

        $targetTransfers = $queries->ownerTransfersForUser($targetUserId);
        self::assertCount(1, $targetTransfers);
        $targetTransferToken = $targetTransfers[0]->selectionToken;
        self::assertNotSame($ownerTransferToken, $targetTransferToken);
        self::assertTrue($targetTransfers[0]->actorIsTarget);

        $accepted = $adapter->accept(
            $targetUserId,
            $targetTransferToken,
            'Accept ownership transfer test.',
            'owner-transfer-accept-0001',
        );

        self::assertTrue($accepted->changed);
        self::assertSame(0, (int) DB::table('administrators')->where('id', $ownerAdministratorId)->value('is_owner'));
        self::assertSame(1, (int) DB::table('administrators')->where('id', $targetAdministratorId)->value('is_owner'));
        self::assertDatabaseHas('owner_transfer_requests', [
            'state' => 'accepted',
            'current_owner_administrator_id' => $ownerAdministratorId,
            'target_administrator_id' => $targetAdministratorId,
        ]);
    }

    /** @return array{0:int,1:int,2:string} */
    private function administrator(bool $owner = false): array
    {
        $publicId = (string) Str::ulid();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'locale' => 'fa',
            'account_type' => 'customer',
            'account_status' => 'active',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return [$userId, $administratorId, $publicId];
    }
}
