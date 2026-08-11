<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\CapacityOperationContext;
use App\Modules\Panels\Application\PanelChangeContext;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Panels\Application\TargetCapacityService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
final class TargetCapacityFoundationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_configuration_and_reservation_lifecycle_are_replay_safe(): void
    {
        foreach (['panel_target_capacities', 'panel_capacity_reservations', 'panel_capacity_reservation_events', 'panel_target_capacity_histories'] as $table) {
            self::assertTrue(Schema::hasTable($table));
        }

        $ownerId = $this->administrator(true);
        $targetId = $this->target('capacity-service');
        $service = $this->app->make(TargetCapacityService::class);
        $allocator = $this->app->make(TargetCapacityAllocator::class);
        $created = $service->create($targetId, 5, $this->adminContext($ownerId, 'target-capacity-create-000001'));
        $capacityId = (int) $created->targetId;
        $service->enable($capacityId, 1, $this->adminContext($ownerId, 'target-capacity-enable-000001'));

        $reserveContext = $this->operationContext('capacity.reserve-service-000001', 'reserve');
        $expiresAt = (new DateTimeImmutable('now'))->modify('+10 minutes');
        $held = $allocator->reserve($targetId, 2, $expiresAt, $reserveContext);
        $reserveReplay = $allocator->reserve($targetId, 2, $expiresAt, $reserveContext);
        self::assertSame('held', $held->state);
        self::assertTrue($reserveReplay->replayed);
        self::assertSame(3, $reserveReplay->availability->availableUnits);

        try {
            $allocator->reserve($targetId, 3, $expiresAt, $reserveContext);
            self::fail('Expected capacity command conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Capacity command key conflict.', $exception->getMessage());
        }

        $commitContext = $this->operationContext('capacity.commit-service-000001', 'commit');
        $committed = $allocator->commit($held->reservationKey, 1, $commitContext);
        self::assertSame('committed', $committed->state);
        self::assertTrue($allocator->commit($held->reservationKey, 1, $commitContext)->replayed);

        $originalReplay = $allocator->reserve($targetId, 2, $expiresAt, $reserveContext);
        self::assertSame('held', $originalReplay->state);
        self::assertSame(1, $originalReplay->reservationVersion);
        self::assertSame(3, $originalReplay->availability->availableUnits);

        $released = $allocator->release(
            $held->reservationKey,
            2,
            $this->operationContext('capacity.release-service-00001', 'release'),
        );
        self::assertSame('released', $released->state);
        self::assertSame(5, $released->availability->availableUnits);
        self::assertSame(3, DB::table('panel_capacity_reservation_events')->count());
        self::assertSame(2, DB::table('panel_target_capacity_histories')->where('panel_target_capacity_id', $capacityId)->count());

        $eventId = (int) DB::table('panel_capacity_reservation_events')->min('id');
        try {
            DB::table('panel_capacity_reservation_events')->where('id', $eventId)->delete();
            self::fail('Expected append-only event rejection.');
        } catch (QueryException) {
            self::assertSame(3, DB::table('panel_capacity_reservation_events')->count());
        }
    }

    public function test_direct_competing_holds_cannot_exceed_hard_limit(): void
    {
        $targetId = $this->target('capacity-direct');
        $capacityId = $this->enabledCapacity($targetId, 1);
        DB::table('panel_capacity_reservations')->insert($this->reservationRow($capacityId, 'capacity.direct-reserve-000001'));

        try {
            DB::table('panel_capacity_reservations')->insert($this->reservationRow($capacityId, 'capacity.direct-reserve-000002'));
            self::fail('Expected over-capacity rejection.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('panel_capacity_reservations')->count());
            self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('held_units'));
        }
    }

    public function test_two_independent_connections_cannot_oversubscribe(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for capacity concurrency verification.');
        }

        $targetId = $this->target('capacity-concurrent');
        $capacityId = $this->enabledCapacity($targetId, 1);
        $database = config('database.connections.mysql');
        self::assertIsArray($database);
        $prefix = sys_get_temp_dir().'/capacity-'.bin2hex(random_bytes(8));
        $barrier = $prefix.'-go';
        $results = [$prefix.'-1', $prefix.'-2'];
        DB::disconnect();

        $children = [];
        foreach ([1, 2] as $index) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $result = 'failure';
                try {
                    while (! file_exists($barrier)) {
                        usleep(1000);
                    }
                    $pdo = new PDO(
                        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $database['port'], $database['database']),
                        (string) $database['username'],
                        (string) $database['password'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                    );
                    $key = 'capacity.concurrent-reserve-00000'.$index;
                    $statement = $pdo->prepare(
                        'INSERT INTO panel_capacity_reservations '
                        .'(panel_target_capacity_id,reservation_key,purpose_code,units,state,expires_at,version,last_command_key,last_payload_hmac,last_correlation_id,last_source_code,last_reason_code,created_at,updated_at) '
                        .'VALUES (?,?,?,?,?,DATE_ADD(CURRENT_TIMESTAMP(6),INTERVAL 5 MINUTE),1,?,?,?,?,?,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6))',
                    );
                    $statement->execute([$capacityId, $key, 'concurrency_test', 1, 'held', $key, hash('sha256', $key), 'concurrency-'.$index, 'test', 'reserve']);
                    $result = 'success';
                } catch (PDOException) {
                    $result = 'failure';
                }
                file_put_contents($results[$index - 1], $result);
                exit(0);
            }
            $children[] = $pid;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();
        $outcomes = array_map(static fn (string $file): string => trim((string) file_get_contents($file)), $results);
        sort($outcomes);
        self::assertSame(['failure', 'success'], $outcomes);
        self::assertSame(1, DB::table('panel_capacity_reservations')->count());
        self::assertSame(1, (int) DB::table('panel_target_capacities')->where('id', $capacityId)->value('held_units'));

        @unlink($barrier);
        foreach ($results as $file) {
            @unlink($file);
        }
    }

    public function test_unauthorized_administrator_cannot_create_capacity(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->app->make(TargetCapacityService::class)->create(
            $this->target('capacity-unauthorized'),
            5,
            $this->adminContext($this->administrator(false), 'target-capacity-unauthorized1'),
        );
    }

    private function target(string $prefix): int
    {
        $now = now('UTC');
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => $prefix.'-connection-'.Str::lower(Str::random(5)), 'provider_type' => 'fake',
            'name_fa' => 'پنل', 'name_en' => null, 'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext', 'credential_key_version' => 1, 'tls_policy' => 'system_ca',
            'custom_ca_disk' => null, 'custom_ca_path' => null, 'certificate_pin_sha256' => null,
            'network_policy' => 'public_only', 'state' => 'disabled', 'last_test_status' => null,
            'last_panel_version' => null, 'last_capabilities_hash' => null, 'last_tested_at' => null,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId, 'code' => $prefix.'-'.Str::lower(Str::random(5)),
            'kind' => 'inbound', 'name_fa' => 'هدف', 'name_en' => null,
            'encrypted_configuration' => 'ciphertext', 'configuration_hash' => hash('sha256', $prefix),
            'configuration_key_version' => 1, 'state' => 'disabled', 'capability_status' => 'declared',
            'capability_evidence_hash' => null, 'capability_verified_at' => null,
            'verified_connection_version' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function enabledCapacity(int $targetId, int $limit): int
    {
        $now = now('UTC');

        return (int) DB::table('panel_target_capacities')->insertGetId([
            'panel_service_target_id' => $targetId, 'hard_limit' => $limit, 'held_units' => 0,
            'committed_units' => 0, 'state' => 'enabled', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function reservationRow(int $capacityId, string $key): array
    {
        $now = now('UTC');

        return [
            'panel_target_capacity_id' => $capacityId, 'reservation_key' => $key, 'purpose_code' => 'test',
            'units' => 1, 'state' => 'held', 'expires_at' => $now->copy()->addMinutes(5), 'version' => 1,
            'last_command_key' => $key, 'last_payload_hmac' => hash('sha256', $key),
            'last_correlation_id' => 'correlation-'.$key, 'last_source_code' => 'test',
            'last_reason_code' => 'reserve', 'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(), 'account_type' => 'customer', 'account_status' => 'active',
                'locale' => 'fa', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]),
            'status' => 'active', 'is_owner' => $owner, 'permission_version' => 1,
            'last_authenticated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function adminContext(int $administratorId, string $fingerprint): PanelChangeContext
    {
        return new PanelChangeContext($fingerprint, 'correlation-'.substr(hash('sha256', $fingerprint), 0, 24), 'target_capacity_change', 'Target capacity test change.', $administratorId);
    }

    private function operationContext(string $key, string $reason): CapacityOperationContext
    {
        return new CapacityOperationContext($key, 'correlation-'.substr(hash('sha256', $key), 0, 24), 'test', 'route_selection', $reason);
    }
}
