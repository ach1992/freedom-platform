<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\OwnerTransferService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement ACL-003 ADM-002 SEC-002 QUA-001 */
final class OwnerTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_current_owner_requests_and_receiving_administrator_accepts_exactly_once(): void
    {
        $ownerId = $this->administrator(true);
        $targetId = $this->administrator();
        $service = $this->app->make(OwnerTransferService::class);
        $requestContext = $this->context($ownerId, 'owner-transfer-request-0001');

        $requested = $service->request($targetId, 300, $requestContext);
        $requestReplay = $service->request($targetId, 300, $requestContext);
        $transferId = $this->transferId($requested->after);
        $acceptContext = $this->context($targetId, 'owner-transfer-accept-0001');
        $accepted = $service->accept($transferId, $acceptContext);
        $acceptReplay = $service->accept($transferId, $acceptContext);

        self::assertTrue($requested->changed);
        self::assertTrue($requestReplay->replayed);
        self::assertTrue($accepted->changed);
        self::assertTrue($acceptReplay->replayed);
        self::assertFalse((bool) DB::table('administrators')->where('id', $ownerId)->value('is_owner'));
        self::assertTrue((bool) DB::table('administrators')->where('id', $targetId)->value('is_owner'));
        self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
        self::assertSame(2, (int) DB::table('administrators')->where('id', $ownerId)->value('permission_version'));
        self::assertSame(2, (int) DB::table('administrators')->where('id', $targetId)->value('permission_version'));
        self::assertSame('accepted', DB::table('owner_transfer_requests')->where('id', $transferId)->value('state'));
        self::assertNull(DB::table('owner_transfer_requests')->where('id', $transferId)->value('active_current_owner_id'));
        self::assertNull(DB::table('owner_transfer_requests')->where('id', $transferId)->value('active_target_administrator_id'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.owner_transfer.request')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'access.owner_transfer.accept')->count());
    }

    public function test_only_receiving_administrator_with_recent_authentication_can_accept(): void
    {
        $ownerId = $this->administrator(true);
        $targetId = $this->administrator();
        $attackerId = $this->administrator();
        $service = $this->app->make(OwnerTransferService::class);
        $transferId = $this->transferId(
            $service->request(
                $targetId,
                300,
                $this->context($ownerId, 'owner-transfer-request-0010'),
            )->after,
        );

        try {
            $service->accept($transferId, $this->context($attackerId, 'owner-transfer-accept-0010'));
            self::fail('Expected receiving administrator authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame('pending', DB::table('owner_transfer_requests')->where('id', $transferId)->value('state'));
        }

        DB::table('administrators')->where('id', $targetId)->update([
            'last_authenticated_at' => now('UTC')->subMinutes(6),
        ]);

        try {
            $service->accept($transferId, $this->context($targetId, 'owner-transfer-accept-0011'));
            self::fail('Expected recent authentication failure.');
        } catch (AuthorizationException) {
            self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
            self::assertSame('pending', DB::table('owner_transfer_requests')->where('id', $transferId)->value('state'));
        }

        DB::table('administrators')->where('id', $targetId)->update([
            'last_authenticated_at' => now('UTC'),
        ]);
        $service->accept($transferId, $this->context($targetId, 'owner-transfer-accept-0012'));

        self::assertSame($targetId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
    }

    public function test_stale_or_tampered_intent_fails_closed_without_changing_owner(): void
    {
        $ownerId = $this->administrator(true);
        $targetId = $this->administrator();
        $service = $this->app->make(OwnerTransferService::class);
        $transferId = $this->transferId(
            $service->request(
                $targetId,
                300,
                $this->context($ownerId, 'owner-transfer-request-0020'),
            )->after,
        );

        DB::table('administrators')->where('id', $targetId)->increment('permission_version');

        try {
            $service->accept($transferId, $this->context($targetId, 'owner-transfer-accept-0020'));
            self::fail('Expected stale Owner transfer intent failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Owner transfer intent is stale.', $exception->getMessage());
            self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
            self::assertSame('pending', DB::table('owner_transfer_requests')->where('id', $transferId)->value('state'));
        }

        DB::table('administrators')->where('id', $targetId)->decrement('permission_version');
        DB::table('owner_transfer_requests')->where('id', $transferId)->update([
            'signed_intent_hash' => str_repeat('0', 64),
        ]);

        try {
            $service->accept($transferId, $this->context($targetId, 'owner-transfer-accept-0021'));
            self::fail('Expected Owner transfer integrity failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Owner transfer intent integrity check failed.', $exception->getMessage());
            self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'access.owner_transfer.accept')->count());
        }
    }

    public function test_expired_request_and_owner_cancellation_release_active_claims(): void
    {
        $ownerId = $this->administrator(true);
        $targetId = $this->administrator();
        $service = $this->app->make(OwnerTransferService::class);
        $expiredTransferId = $this->transferId(
            $service->request(
                $targetId,
                60,
                $this->context($ownerId, 'owner-transfer-request-0030'),
            )->after,
        );
        DB::table('owner_transfer_requests')->where('id', $expiredTransferId)->update([
            'expires_at' => now('UTC')->subSecond(),
        ]);

        $expired = $service->accept(
            $expiredTransferId,
            $this->context($targetId, 'owner-transfer-accept-0030'),
        );

        self::assertSame('expired', $expired->after['state']);
        self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
        self::assertNull(DB::table('owner_transfer_requests')->where('id', $expiredTransferId)->value('active_current_owner_id'));

        $cancelTransferId = $this->transferId(
            $service->request(
                $targetId,
                300,
                $this->context($ownerId, 'owner-transfer-request-0031'),
            )->after,
        );
        $cancelContext = $this->context($ownerId, 'owner-transfer-cancel-0031');
        $cancelled = $service->cancel($cancelTransferId, $cancelContext);
        $replay = $service->cancel($cancelTransferId, $cancelContext);

        self::assertSame('cancelled', $cancelled->after['state']);
        self::assertTrue($replay->replayed);
        self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
    }

    public function test_database_rejects_a_second_active_owner(): void
    {
        $ownerId = $this->administrator(true);

        try {
            $this->administrator(true);
            self::fail('Expected Owner singleton database constraint.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('administrators')->where('is_owner', true)->count());
            self::assertSame($ownerId, (int) DB::table('administrators')->where('is_owner', true)->value('id'));
        }
    }

    public function test_non_owner_cannot_request_transfer_and_fingerprint_cannot_be_rebound(): void
    {
        $ownerId = $this->administrator(true);
        $targetId = $this->administrator();
        $otherTargetId = $this->administrator();
        $service = $this->app->make(OwnerTransferService::class);

        try {
            $service->request(
                $otherTargetId,
                300,
                $this->context($targetId, 'owner-transfer-request-0040'),
            );
            self::fail('Expected current Owner authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('owner_transfer_requests')->count());
        }

        $context = $this->context($ownerId, 'owner-transfer-request-0041');
        $service->request($targetId, 300, $context);

        try {
            $service->request($otherTargetId, 300, $context);
            self::fail('Expected Owner transfer fingerprint conflict.');
        } catch (RuntimeException) {
            self::assertSame(1, DB::table('owner_transfer_requests')->count());
            self::assertSame($targetId, (int) DB::table('owner_transfer_requests')->value('target_administrator_id'));
        }
    }

    /** @param array<string, bool|int|string|null> $after */
    private function transferId(array $after): string
    {
        $transferId = $after['transfer_id'] ?? null;

        self::assertIsString($transferId);

        return $transferId;
    }

    private function administrator(bool $owner = false): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(
        int $actorAdministratorId,
        string $requestFingerprint,
    ): AccessChangeContext {
        return new AccessChangeContext(
            $requestFingerprint,
            str_replace('request', 'correlation', $requestFingerprint),
            'owner_transfer_test',
            'Owner transfer test operation.',
            $actorAdministratorId,
        );
    }
}
