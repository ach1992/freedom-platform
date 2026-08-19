<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\AgentBulkOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 AGT-003 AGT-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class NonPaidOrderMigrationRollbackSafetyTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Authority migration rollback safety requires MariaDB/MySQL.');
        }
        $this->seed();
    }

    public function test_source_authority_rollback_preserves_raced_evidence_and_retries_from_a_durable_close(): void
    {
        /** @var Migration $source */
        $source = require database_path('migrations/2026_08_19_000100_create_non_paid_order_source_authority.php');
        /** @var Migration $shape */
        $shape = require database_path('migrations/2026_08_19_000110_prepare_non_paid_order_shape.php');
        /** @var Migration $invalidation */
        $invalidation = require database_path('migrations/2026_08_19_000115_extend_provisioning_invalidation_to_non_paid_sources.php');
        /** @var Migration $activation */
        $activation = require database_path('migrations/2026_08_19_000120_activate_non_paid_order_authority.php');
        /** @var Migration $serviceMutation */
        $serviceMutation = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');
        /** @var Migration $bulk */
        $bulk = require database_path('migrations/2026_08_19_000130_create_agent_bulk_order_orchestration.php');

        $bulk->down();
        $activation->down();
        $invalidation->down();
        $shape->down();
        $this->assertPurchaseOnlySourceFence();

        $row = $this->validAdministratorGrantRow('rollback-source-race');
        $raced = false;
        $pdo = $this->independentPdo();
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $pdo, $row): void {
            unset($bindings, $connection);
            if ($raced || ! str_contains(strtolower($query), 'alter table `order_source_authorizations` add constraint `order_source_authorizations_bootstrap_block_chk`')) {
                return;
            }

            $raced = true;
            $this->insertSourceAuthorization($pdo, $row);
        });

        try {
            $source->down();
            self::fail('A source authorization racing the rollback close must make the atomic bootstrap CHECK fail.');
        } catch (QueryException) {
            self::assertTrue($raced);
        }

        self::assertSame(1, DB::table('order_source_authorizations')->where('authorization_key', $row['authorization_key'])->count());
        self::assertTrue($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_bootstrap_block_chk'));
        self::assertTrue($this->triggerExists('order_source_authorizations_insert_guard'));
        $replay = $this->app->make(OrderSourceAuthorizationService::class)->authorizeAdministratorGrant(
            (string) $row['authorization_key'],
            (int) $row['actor_id'],
            (int) $row['user_id'],
            (int) $row['plan_offering_id'],
            (string) $row['reason_code'],
            'rollback-source-replay-correlation',
        );
        self::assertTrue($replay->replayed);

        // Test-only cleanup of the deliberately raced durable evidence. Production rollback must
        // preserve it, which is what the assertions above prove.
        Schema::dropIfExists('order_source_authorizations');
        $source->up();

        $faulted = false;
        DB::listen(function (QueryExecuted $query) use (&$faulted): void {
            if ($faulted || ! str_contains(strtolower($query->sql), 'drop trigger if exists order_source_authorizations_delete_guard')) {
                return;
            }

            $faulted = true;
            throw new RuntimeException('Injected after source final guard removal committed.');
        });

        try {
            $source->down();
            self::fail('The fault injector must interrupt after the first source final guard DROP commits.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected after source final guard removal committed.', $exception->getMessage());
        }

        self::assertTrue($this->constraintExists('order_source_authorizations', 'order_source_authorizations_bootstrap_block_chk'));
        self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
        self::assertTrue($this->triggerExists('order_source_authorizations_bootstrap_insert_barrier'));
        self::assertFalse($this->triggerExists('order_source_authorizations_delete_guard'));
        try {
            $this->app->make(OrderSourceAuthorizationService::class)->authorizeAdministratorGrant(
                'rollback-source-blocked-authority',
                (int) $row['actor_id'],
                (int) $row['user_id'],
                (int) $row['plan_offering_id'],
                (string) $row['reason_code'],
                'rollback-source-blocked-correlation',
            );
            self::fail('A committed rollback barrier must make stale-ready source authority non-consumable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Order source authorization authority is not finalized.', $exception->getMessage());
        }

        $source->down();
        self::assertFalse(Schema::hasTable('order_source_authorizations'));
        $source->up();
        $shape->up();
        $invalidation->up();
        $serviceMutation->up();
        $activation->up();
        $bulk->up();
        $this->assertSourceAuthorityReady();
    }

    public function test_agent_bulk_rollback_closes_parent_first_preserves_raced_evidence_and_retries_after_fault(): void
    {
        /** @var Migration $bulk */
        $bulk = require database_path('migrations/2026_08_19_000130_create_agent_bulk_order_orchestration.php');
        $agentId = $this->activeAgent('rollback-bulk-race');
        $row = [
            'public_id' => (string) Str::ulid(),
            'batch_key' => 'rollback-bulk-parent-race',
            'request_payload_hash' => hash('sha256', 'rollback-bulk-parent-race'),
            'user_id' => $agentId,
            'item_count' => 1,
            'creation_correlation_id' => 'rollback-bulk-parent-correlation',
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ];
        $raced = false;
        $pdo = $this->independentPdo();
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $pdo, $row): void {
            unset($bindings, $connection);
            if ($raced || ! str_contains(strtolower($query), 'alter table `agent_bulk_orders` add constraint `agent_bulk_orders_bootstrap_block_chk`')) {
                return;
            }

            $raced = true;
            $this->insertBulkParent($pdo, $row);
        });

        try {
            $bulk->down();
            self::fail('A bulk parent racing the rollback close must make the atomic parent bootstrap CHECK fail.');
        } catch (QueryException) {
            self::assertTrue($raced);
        }

        self::assertSame(1, DB::table('agent_bulk_orders')->where('batch_key', $row['batch_key'])->count());
        self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
        self::assertTrue($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_bootstrap_block_chk'));
        self::assertTrue($this->triggerExists('agent_bulk_orders_insert_guard'));

        Schema::dropIfExists('agent_bulk_order_items');
        Schema::dropIfExists('agent_bulk_orders');
        $bulk->up();

        $faulted = false;
        DB::listen(function (QueryExecuted $query) use (&$faulted): void {
            if ($faulted || ! str_contains(strtolower($query->sql), 'drop trigger if exists agent_bulk_items_delete_guard')) {
                return;
            }

            $faulted = true;
            throw new RuntimeException('Injected after Agent bulk final guard removal committed.');
        });

        try {
            $bulk->down();
            self::fail('The fault injector must interrupt after the first Agent bulk final guard DROP commits.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected after Agent bulk final guard removal committed.', $exception->getMessage());
        }

        self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
        self::assertTrue($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_bootstrap_block_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));
        self::assertTrue($this->triggerExists('agent_bulk_orders_bootstrap_insert_barrier'));
        self::assertTrue($this->triggerExists('agent_bulk_items_bootstrap_insert_barrier'));
        self::assertFalse($this->triggerExists('agent_bulk_items_delete_guard'));
        try {
            $this->app->make(AgentBulkOrderService::class)->execute(
                'rollback-bulk-blocked-parent',
                $agentId,
                [['child_key' => 'rollback-bulk-blocked-child', 'purchase_settlement_public_id' => (string) Str::ulid()]],
                'rollback-bulk-blocked-correlation',
            );
            self::fail('A committed parent rollback barrier must make stale-ready Agent bulk authority non-consumable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Agent bulk Order authority is not finalized.', $exception->getMessage());
        }

        $bulk->down();
        self::assertFalse(Schema::hasTable('agent_bulk_order_items'));
        self::assertFalse(Schema::hasTable('agent_bulk_orders'));
        $bulk->up();
        $this->assertAgentBulkAuthorityReady();
    }

    /** @return array<string,mixed> */
    private function validAdministratorGrantRow(string $suffix): array
    {
        $administratorId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $offering = $this->activeBenefitOffering($suffix);
        /** @var object{code:string,sales_server_id:int|string,panel_service_target_id:int|string,service_mode_code:string,server_selection_mode:string,protocol_selection_mode:string,duration_days:int|string,data_allowance_bytes:int|string|null,device_limit:int|string|null,version:int|string}|null $offeringRow */
        $offeringRow = DB::table('plan_offerings')->where('id', $offering['id'])->first([
            'code', 'sales_server_id', 'panel_service_target_id', 'service_mode_code', 'server_selection_mode',
            'protocol_selection_mode', 'duration_days', 'data_allowance_bytes', 'device_limit', 'version',
        ]);
        self::assertNotNull($offeringRow);
        $configuration = [
            'data_allowance_bytes' => $offeringRow->data_allowance_bytes === null ? null : (int) $offeringRow->data_allowance_bytes,
            'device_limit' => $offeringRow->device_limit === null ? null : (int) $offeringRow->device_limit,
            'duration_days' => (int) $offeringRow->duration_days,
            'offering_code' => $offeringRow->code,
            'offering_version' => (int) $offeringRow->version,
            'protocol_selection_mode' => $offeringRow->protocol_selection_mode,
            'sales_server_id' => (int) $offeringRow->sales_server_id,
            'server_selection_mode' => $offeringRow->server_selection_mode,
            'service_mode_code' => $offeringRow->service_mode_code,
            'service_target_id' => (int) $offeringRow->panel_service_target_id,
        ];
        ksort($configuration, SORT_STRING);
        $configurationJson = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $request = [
            'actor_administrator_id' => $administratorId,
            'plan_offering_id' => $offering['id'],
            'reason_code' => 'manual_service_grant',
            'user_id' => $userId,
        ];
        ksort($request, SORT_STRING);
        $requestJson = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'public_id' => (string) Str::ulid(),
            'source_type' => 'admin_grant',
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
            'trial_reservation_id' => null,
            'trial_reservation_command_key' => null,
            'benefit_entitlement_id' => null,
            'benefit_entitlement_public_id' => null,
            'authorization_key' => 'rollback-source-'.substr(hash('sha256', $suffix), 0, 32),
            'request_payload_hash' => hash('sha256', $requestJson),
            'configuration_snapshot' => $configurationJson,
            'configuration_snapshot_hash' => hash('sha256', $configurationJson),
            'actor_type' => 'administrator',
            'actor_id' => $administratorId,
            'reason_code' => 'manual_service_grant',
            'correlation_id' => 'rollback-source-'.substr(hash('sha256', 'correlation-'.$suffix), 0, 32),
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ];
    }

    private function activeAgent(string $suffix): int
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'agent',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $applicationId = (int) DB::table('agent_applications')->insertGetId([
            'customer_id' => $userId,
            'active_customer_id' => null,
            'state' => 'approved',
            'claimed_by_administrator_id' => null,
            'decided_by_administrator_id' => null,
            'decision_reason_code' => null,
            'decision_reason' => null,
            'application_version' => 1,
            'submitted_at' => $now,
            'claimed_at' => null,
            'decided_at' => $now,
            'reapply_allowed_at' => null,
            'reapplication_released_at' => null,
            'reapplication_released_by_administrator_id' => null,
            'reapplication_release_reason_code' => null,
            'reapplication_release_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('agent_profiles')->insert([
            'user_id' => $userId,
            'status' => 'active',
            'pricing_profile_code' => 'rollback-'.substr(hash('sha256', $suffix), 0, 16),
            'approved_application_id' => $applicationId,
            'approved_by_administrator_id' => null,
            'approved_at' => $now,
            'suspended_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function independentPdo(): PDO
    {
        $database = config('database.connections.mysql');
        self::assertIsArray($database);

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', (string) $database['host'], (int) $database['port'], (string) $database['database']),
            (string) $database['username'],
            (string) $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @param array<string,mixed> $row */
    private function insertSourceAuthorization(PDO $pdo, array $row): void
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO order_source_authorizations
(public_id,source_type,user_id,plan_offering_id,trial_reservation_id,trial_reservation_command_key,benefit_entitlement_id,benefit_entitlement_public_id,authorization_key,request_payload_hash,configuration_snapshot,configuration_snapshot_hash,actor_type,actor_id,reason_code,correlation_id,created_at)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $statement->execute([
            $row['public_id'], $row['source_type'], $row['user_id'], $row['plan_offering_id'], $row['trial_reservation_id'],
            $row['trial_reservation_command_key'], $row['benefit_entitlement_id'], $row['benefit_entitlement_public_id'],
            $row['authorization_key'], $row['request_payload_hash'], $row['configuration_snapshot'], $row['configuration_snapshot_hash'],
            $row['actor_type'], $row['actor_id'], $row['reason_code'], $row['correlation_id'], $row['created_at'],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function insertBulkParent(PDO $pdo, array $row): void
    {
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO agent_bulk_orders
(public_id,batch_key,request_payload_hash,user_id,item_count,creation_correlation_id,created_at)
VALUES (?,?,?,?,?,?,?)
SQL);
        $statement->execute([
            $row['public_id'], $row['batch_key'], $row['request_payload_hash'], $row['user_id'],
            $row['item_count'], $row['creation_correlation_id'], $row['created_at'],
        ]);
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function triggerExists(string $trigger): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function assertPurchaseOnlySourceFence(): void
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'orders_unimplemented_source_insert_guard' AND LOCATE(?, ACTION_STATEMENT) > 0",
            ["IF NEW.source_type <> 'purchase' THEN"],
        );
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->aggregate);
    }

    private function assertSourceAuthorityReady(): void
    {
        self::assertTrue($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_bootstrap_block_chk'));
        self::assertTrue($this->triggerExists('order_source_authorizations_insert_guard'));
    }

    private function assertAgentBulkAuthorityReady(): void
    {
        self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
        self::assertTrue($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_bootstrap_block_chk'));
        self::assertTrue($this->triggerExists('agent_bulk_orders_insert_guard'));
        self::assertTrue($this->triggerExists('agent_bulk_items_insert_guard'));
    }
}
