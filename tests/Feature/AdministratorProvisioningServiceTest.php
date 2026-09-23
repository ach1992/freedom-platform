<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorProvisioningService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ADM-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-001 */
final class AdministratorProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_authorized_owner_enables_existing_user_exactly_once_without_implicit_role(): void
    {
        $ownerId = $this->administrator(true);
        $targetPublicId = $this->user();
        $service = $this->app->make(AdministratorProvisioningService::class);
        $context = $this->context($ownerId, 'administrator-enable-request-0001');

        $enabled = $service->enableUser($targetPublicId, $context);
        $replay = $service->enableUser($targetPublicId, $context);

        self::assertTrue($enabled->changed);
        self::assertTrue($replay->replayed);
        self::assertSame($enabled->targetAdministratorId, $replay->targetAdministratorId);
        self::assertDatabaseHas('administrators', [
            'id' => $enabled->targetAdministratorId,
            'status' => 'active',
            'is_owner' => 0,
            'permission_version' => 1,
        ]);
        self::assertSame(0, DB::table('administrator_role_assignments')
            ->where('administrator_id', $enabled->targetAdministratorId)
            ->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('action', 'access.administrator.enabled')
            ->where('target_id', (string) $enabled->targetAdministratorId)
            ->count());
    }

    public function test_existing_administrator_or_deleted_user_cannot_be_enabled_again(): void
    {
        $ownerId = $this->administrator(true);
        $existingPublicId = $this->user();
        $existingUserId = (int) DB::table('users')->where('public_id', $existingPublicId)->value('id');
        DB::table('administrators')->insert([
            'user_id' => $existingUserId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $service = $this->app->make(AdministratorProvisioningService::class);

        try {
            $service->enableUser(
                $existingPublicId,
                $this->context($ownerId, 'administrator-enable-request-0010'),
            );
            self::fail('Expected existing administrator failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Administrator already exists for this user.', $exception->getMessage());
        }

        $deletedPublicId = $this->user('deleted');
        try {
            $service->enableUser(
                $deletedPublicId,
                $this->context($ownerId, 'administrator-enable-request-0011'),
            );
            self::fail('Expected deleted target failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Administrator enable target user does not exist.', $exception->getMessage());
        }
    }

    public function test_unauthorized_actor_cannot_enable_administrator(): void
    {
        $actorId = $this->administrator();
        $targetPublicId = $this->user();
        $service = $this->app->make(AdministratorProvisioningService::class);

        try {
            $service->enableUser(
                $targetPublicId,
                $this->context($actorId, 'administrator-enable-request-0020'),
            );
            self::fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('administrators')
                ->join('users', 'users.id', '=', 'administrators.user_id')
                ->where('users.public_id', $targetPublicId)
                ->count());
        }
    }

    private function administrator(bool $owner = false): int
    {
        $publicId = $this->user();
        $userId = (int) DB::table('users')->where('public_id', $publicId)->value('id');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function user(string $status = 'active'): string
    {
        $publicId = (string) Str::ulid();
        DB::table('users')->insert([
            'public_id' => $publicId,
            'locale' => 'fa',
            'account_type' => 'customer',
            'account_status' => $status,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $publicId;
    }

    private function context(int $actorAdministratorId, string $fingerprint): AccessChangeContext
    {
        return new AccessChangeContext(
            requestFingerprint: $fingerprint,
            correlationId: 'admin-enable-test-'.substr(hash('sha256', $fingerprint), 0, 40),
            reasonCode: 'administrator_enable_test',
            reason: 'Administrator enable test.',
            actorAdministratorId: $actorAdministratorId,
        );
    }
}
