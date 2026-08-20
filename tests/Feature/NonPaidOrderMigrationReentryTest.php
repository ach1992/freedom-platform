<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\AgentBulkOrderService;
use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 AGT-003 AGT-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class NonPaidOrderMigrationReentryTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->serviceOperationalMigration()->down();
    }

    protected function tearDown(): void
    {
        try {
            $this->serviceOperationalMigration()->up();
            $this->paidServiceMutationMigration()->up();
        } finally {
            parent::tearDown();
        }
    }

    public function test_source_authority_migration_converges_after_table_create_commits_without_migration_record(): void
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
        $injected = false;
        $unauthorized = $this->unauthorizedAdministratorGrantRow('source-create-cut');

        $serviceMutation->up();
        $activation->up();
        $bulk->up();
        $this->assertServiceMutationRemoteEffectAuthority();

        try {
            $bulk->down();
            $activation->down();
            $this->assertServiceMutationRemoteEffectAuthority();
            $invalidation->down();
            $shape->down();
            $source->down();
            $this->assertPurchaseOnlySourceFence();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'create table `order_source_authorizations`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed Order source authorization table creation.');
            });

            try {
                $source->up();
                self::fail('The fault injector must interrupt after the source-authority table CREATE commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed Order source authorization table creation.', $exception->getMessage());
            }

            self::assertTrue(Schema::hasTable('order_source_authorizations'));
            self::assertTrue($this->constraintExists('order_source_authorizations', 'order_source_authorizations_bootstrap_block_chk'));
            self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_auth_type_chk'));
            self::assertFalse($this->triggerExists('order_source_authorizations_insert_guard'));
            self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
            $this->assertQueryRejected(fn (): bool => DB::table('order_source_authorizations')->insert($unauthorized));
            self::assertSame(0, DB::table('order_source_authorizations')->count());
            $this->assertPurchaseOnlySourceFence();

            // Model a real legacy partial table created before this remediation: the table exists,
            // its final relational guard is absent, and no intrinsic bootstrap CHECK protects it.
            DB::statement('ALTER TABLE `order_source_authorizations` DROP CONSTRAINT `order_source_authorizations_bootstrap_block_chk`');
            self::assertTrue(DB::table('order_source_authorizations')->insert($unauthorized));
            self::assertSame(1, DB::table('order_source_authorizations')->count());

            try {
                $source->up();
                self::fail('Re-entry must not bless unverified Order source rows from a legacy partial migration.');
            } catch (RuntimeException $exception) {
                self::assertSame('Cannot repair unverified Order source authorization rows from a partial migration.', $exception->getMessage());
            }
            self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
            self::assertTrue($this->triggerExists('order_source_authorizations_bootstrap_insert_barrier'));
            $this->assertPurchaseOnlySourceFence();

            try {
                $this->app->make(OrderSourceAuthorizationService::class)->authorizeAdministratorGrant(
                    (string) $unauthorized['authorization_key'],
                    (int) $unauthorized['actor_id'],
                    (int) $unauthorized['user_id'],
                    (int) $unauthorized['plan_offering_id'],
                    (string) $unauthorized['reason_code'],
                    'partial-source-replay-correlation-01',
                );
                self::fail('Marker-less legacy source authority must not replay through the application service.');
            } catch (RuntimeException $exception) {
                self::assertSame('Order source authorization authority is not finalized.', $exception->getMessage());
            }
            try {
                $this->app->make(NonPaidOrderService::class)->materialize(
                    (string) $unauthorized['public_id'],
                    'partial-source-order-correlation-01',
                );
                self::fail('Marker-less legacy source authority must not materialize an Order.');
            } catch (RuntimeException $exception) {
                self::assertSame('Order source authorization authority is not finalized.', $exception->getMessage());
            }
            self::assertSame(0, DB::table('orders')->count());

            // Manual cleanup is test-only evidence construction. Normal migration re-entry refuses
            // this state; after removal, a clean empty blocked migration must converge normally.
            Schema::dropIfExists('order_source_authorizations');
            $source->up();
            $this->assertSourceAuthorityReady();
            $this->assertPurchaseOnlySourceFence();
            $this->assertQueryRejected(fn (): bool => DB::table('order_source_authorizations')->insert($unauthorized));
            self::assertSame(0, DB::table('order_source_authorizations')->count());
        } finally {
            $injected = true;
            if (Schema::hasTable('order_source_authorizations')
                && ! $this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk')) {
                Schema::dropIfExists('order_source_authorizations');
            }
            $source->up();
            $shape->up();
            $invalidation->up();
            $serviceMutation->up();
            $this->activateNonPaidAuthority($activation);
            $bulk->up();
        }

        $this->assertSupportedSourceFence();
        $this->assertSourceAuthorityReady();
        $this->assertAgentBulkAuthorityReady();
    }

    public function test_order_shape_migration_converges_after_one_table_alter_and_drop_before_add_cut(): void
    {
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
        $injected = false;

        $serviceMutation->up();
        $activation->up();
        $bulk->up();
        $this->assertServiceMutationRemoteEffectAuthority();

        try {
            $bulk->down();
            $activation->down();
            $this->assertServiceMutationRemoteEffectAuthority();
            $invalidation->down();
            $shape->down();
            $this->assertPurchaseOnlySourceFence();

            // Model a committed first table alteration with the second table still untouched.
            DB::statement('ALTER TABLE orders ADD COLUMN `order_source_authorization_id` BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE orders ADD COLUMN `order_source_authorization_public_id` CHAR(26) NULL');
            DB::statement('ALTER TABLE orders ADD UNIQUE INDEX `orders_source_authorization_unique` (`order_source_authorization_id`)');
            DB::statement('ALTER TABLE orders ADD UNIQUE INDEX `orders_source_authorization_public_unique` (`order_source_authorization_public_id`)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT `orders_order_source_authorization_id_foreign` FOREIGN KEY (`order_source_authorization_id`) REFERENCES `order_source_authorizations` (`id`) ON DELETE RESTRICT');

            self::assertTrue(Schema::hasColumn('orders', 'order_source_authorization_id'));
            self::assertFalse(Schema::hasColumn('order_items', 'order_source_authorization_id'));

            $shape->up();
            $this->assertPreparedOrderShapeReady();
            $this->assertPurchaseOnlySourceFence();

            DB::statement('ALTER TABLE `orders` DROP INDEX `orders_source_authorization_public_unique`');
            DB::statement('ALTER TABLE `orders` ADD UNIQUE INDEX `orders_source_authorization_public_unique` (`order_source_authorization_public_id`(10))');
            self::assertFalse($this->namedIndexMatches('orders', 'orders_source_authorization_public_unique', ['order_source_authorization_public_id'], true));
            try {
                $shape->up();
                self::fail('Expected-name prefix indexes must not satisfy full-column source authority identity.');
            } catch (RuntimeException $exception) {
                self::assertSame('Non-paid Order shape has incompatible index: orders.orders_source_authorization_public_unique', $exception->getMessage());
            }
            DB::statement('ALTER TABLE `orders` DROP INDEX `orders_source_authorization_public_unique`');
            DB::statement('ALTER TABLE `orders` ADD UNIQUE INDEX `orders_source_authorization_public_unique` (`order_source_authorization_public_id`)');
            $shape->up();
            $this->assertPreparedOrderShapeReady();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'drop constraint `orders_state_chk`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed non-paid Order state constraint drop.');
            });

            try {
                $shape->up();
                self::fail('The fault injector must interrupt after the constraint DROP commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed non-paid Order state constraint drop.', $exception->getMessage());
            }

            self::assertFalse($this->constraintExists('orders', 'orders_state_chk'));
            $this->assertPurchaseOnlySourceFence();

            $shape->up();
            $this->assertPreparedOrderShapeReady();
            $this->assertPurchaseOnlySourceFence();
        } finally {
            $injected = true;
            $shape->up();
            $invalidation->up();
            $serviceMutation->up();
            $this->activateNonPaidAuthority($activation);
            $bulk->up();
        }

        $this->assertSupportedSourceFence();
        $this->assertAgentBulkAuthorityReady();
    }

    public function test_agent_bulk_migration_converges_after_parent_table_create_commits_without_migration_record(): void
    {
        /** @var Migration $bulk */
        $bulk = require database_path('migrations/2026_08_19_000130_create_agent_bulk_order_orchestration.php');
        $injected = false;
        $customerId = $this->benefitUser();
        $unauthorizedParent = $this->unauthorizedBulkParentRow($customerId, 'bulk-parent-create-cut');

        try {
            $bulk->down();
            $this->assertSupportedSourceFence();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'create table `agent_bulk_orders`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed Agent bulk parent table creation.');
            });

            try {
                $bulk->up();
                self::fail('The fault injector must interrupt after the Agent bulk parent CREATE commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed Agent bulk parent table creation.', $exception->getMessage());
            }

            self::assertTrue(Schema::hasTable('agent_bulk_orders'));
            self::assertFalse(Schema::hasTable('agent_bulk_order_items'));
            self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
            self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_count_chk'));
            self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
            $this->assertQueryRejected(fn (): bool => DB::table('agent_bulk_orders')->insert($unauthorizedParent));
            self::assertSame(0, DB::table('agent_bulk_orders')->count());
            $this->assertSupportedSourceFence();

            $bulk->up();
            $this->assertAgentBulkAuthorityReady();
            $this->assertSupportedSourceFence();
            $this->assertQueryRejected(fn (): bool => DB::table('agent_bulk_orders')->insert($unauthorizedParent));
        } finally {
            $injected = true;
            $bulk->up();
        }
    }

    public function test_agent_bulk_child_create_cut_is_intrinsically_blocked_and_legacy_rows_are_not_blessed(): void
    {
        /** @var Migration $bulk */
        $bulk = require database_path('migrations/2026_08_19_000130_create_agent_bulk_order_orchestration.php');
        $injected = false;
        $customerId = $this->benefitUser();
        $unauthorizedParent = $this->unauthorizedBulkParentRow($customerId, 'bulk-child-create-cut');
        $forgedChild = $this->forgedBulkChildRow('bulk-child-create-cut');

        try {
            $bulk->down();

            DB::listen(function (QueryExecuted $query) use (&$injected): void {
                if ($injected || ! str_contains(strtolower($query->sql), 'create table `agent_bulk_order_items`')) {
                    return;
                }

                $injected = true;
                throw new RuntimeException('Injected after committed Agent bulk child table creation.');
            });

            try {
                $bulk->up();
                self::fail('The fault injector must interrupt after the Agent bulk child CREATE commits.');
            } catch (RuntimeException $exception) {
                self::assertTrue($injected);
                self::assertSame('Injected after committed Agent bulk child table creation.', $exception->getMessage());
            }

            self::assertTrue(Schema::hasTable('agent_bulk_orders'));
            self::assertTrue(Schema::hasTable('agent_bulk_order_items'));
            self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
            self::assertTrue($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_bootstrap_block_chk'));
            self::assertFalse($this->triggerExists('agent_bulk_orders_insert_guard'));
            self::assertFalse($this->triggerExists('agent_bulk_items_insert_guard'));
            self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
            self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));

            $this->assertQueryRejected(fn (): bool => DB::table('agent_bulk_orders')->insert($unauthorizedParent));
            $this->assertQueryRejected(fn (): bool => DB::table('agent_bulk_order_items')->insert($forgedChild));
            self::assertSame(0, DB::table('agent_bulk_orders')->count());
            self::assertSame(0, DB::table('agent_bulk_order_items')->count());

            // Simulate the older vulnerable child-created/guards-absent state. A forged pending child
            // can exist only after deliberately removing the new intrinsic barrier; re-entry must
            // close mutation first and refuse to mark that state ready rather than bless the row.
            DB::statement('ALTER TABLE `agent_bulk_order_items` DROP CONSTRAINT `agent_bulk_items_bootstrap_block_chk`');
            self::assertTrue(DB::table('agent_bulk_order_items')->insert($forgedChild));
            self::assertSame(1, DB::table('agent_bulk_order_items')->count());

            try {
                $bulk->up();
                self::fail('Agent bulk re-entry must not bless unverified child rows from a legacy partial migration.');
            } catch (RuntimeException $exception) {
                self::assertSame('Cannot repair unverified Agent bulk child rows from a partial migration.', $exception->getMessage());
            }
            self::assertTrue($this->triggerExists('agent_bulk_orders_bootstrap_insert_barrier'));
            self::assertTrue($this->triggerExists('agent_bulk_items_bootstrap_insert_barrier'));
            self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
            self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));

            try {
                $this->app->make(AgentBulkOrderService::class)->execute(
                    'partial-agent-bulk-parent-01',
                    1,
                    [['child_key' => 'partial-agent-bulk-child-01', 'purchase_settlement_public_id' => (string) Str::ulid()]],
                    'partial-agent-bulk-correlation-01',
                );
                self::fail('Marker-less Agent bulk authority must not reach existing-parent or child processing.');
            } catch (RuntimeException $exception) {
                self::assertSame('Agent bulk Order authority is not finalized.', $exception->getMessage());
            }
            self::assertSame(0, DB::table('orders')->count());

            Schema::dropIfExists('agent_bulk_order_items');
            Schema::dropIfExists('agent_bulk_orders');
            $bulk->up();
            $this->assertAgentBulkAuthorityReady();
        } finally {
            $injected = true;
            if (Schema::hasTable('agent_bulk_order_items')
                && ! $this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk')) {
                Schema::dropIfExists('agent_bulk_order_items');
            }
            if (Schema::hasTable('agent_bulk_orders')
                && ! $this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk')) {
                Schema::dropIfExists('agent_bulk_orders');
            }
            $bulk->up();
        }
    }

    private function paidServiceMutationMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_20_000110_enable_paid_service_mutation_authority.php');

        return $migration;
    }

    private function serviceOperationalMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');

        return $migration;
    }

    /** @return array<string,mixed> */
    private function unauthorizedAdministratorGrantRow(string $suffix): array
    {
        $administratorId = $this->nonOwnerAdministrator();
        $userId = $this->benefitUser();
        $offering = $this->activeBenefitOffering('migration-'.$suffix);
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
            'authorization_key' => 'partial-admin-grant-'.substr(hash('sha256', $suffix), 0, 32),
            'request_payload_hash' => hash('sha256', $requestJson),
            'configuration_snapshot' => $configurationJson,
            'configuration_snapshot_hash' => hash('sha256', $configurationJson),
            'actor_type' => 'administrator',
            'actor_id' => $administratorId,
            'reason_code' => 'manual_service_grant',
            'correlation_id' => 'partial-admin-'.substr(hash('sha256', $suffix), 0, 32),
            'created_at' => now('UTC'),
        ];
    }

    /** @return array<string,mixed> */
    private function unauthorizedBulkParentRow(int $customerId, string $suffix): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'batch_key' => 'partial-bulk-'.substr(hash('sha256', $suffix), 0, 32),
            'request_payload_hash' => hash('sha256', 'partial-bulk-request-'.$suffix),
            'user_id' => $customerId,
            'item_count' => 1,
            'creation_correlation_id' => 'partial-bulk-'.substr(hash('sha256', 'correlation-'.$suffix), 0, 32),
            'created_at' => now('UTC'),
        ];
    }

    /** @return array<string,mixed> */
    private function forgedBulkChildRow(string $suffix): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'agent_bulk_order_id' => 1,
            'line_number' => 1,
            'child_key' => 'partial-child-'.substr(hash('sha256', $suffix), 0, 32),
            'purchase_settlement_id' => 1,
            'purchase_settlement_public_id' => (string) Str::ulid(),
            'source_quote_id' => 1,
            'source_quote_public_id' => (string) Str::ulid(),
            'state' => 'pending',
            'attempt_count' => 0,
            'order_id' => null,
            'order_item_id' => null,
            'last_error_code' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected database authority rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function assertServiceMutationRemoteEffectAuthority(): void
    {
        self::assertTrue(
            $this->triggerContains('provisioning_remote_effect_events_insert_guard', 'service_mutation_effect_v1'),
            'The exact pre-#150 predecessor must retain Service-mutation remote-effect event authority.',
        );
    }

    private function activateNonPaidAuthority(Migration $activation): void
    {
        try {
            $activation->up();
        } catch (RuntimeException $exception) {
            $this->assertNonPaidActivationReadinessSurface();
            throw $exception;
        }

        $this->assertNonPaidActivationReadinessSurface();
    }

    private function assertNonPaidActivationReadinessSurface(): void
    {
        foreach ([
            'orders_insert_guard',
            'order_items_insert_guard',
            'orders_initial_history',
            'order_state_histories_insert_guard',
            'service_subscriptions_insert_guard',
            'service_subscriptions_update_guard',
            'provisioning_operations_insert_guard',
            'provisioning_operations_update_guard',
            'provisioning_operation_histories_insert_guard',
            'provisioning_operation_initial_history',
            'provisioning_remote_effect_events_insert_guard',
            'orders_update_guard',
            'orders_provisioning_history',
            'orders_provisioning_outbox_envelope_guard',
            'outbox_initial_provision_envelope_update_guard',
            'orders_unimplemented_source_insert_guard',
        ] as $trigger) {
            self::assertTrue($this->triggerExists($trigger), $trigger.' must exist at non-paid activation readiness.');
        }
        foreach ([
            ['orders', 'orders_source_type_chk'],
            ['orders', 'orders_provisioning_exact_authority_chk'],
        ] as [$table, $constraint]) {
            self::assertTrue($this->constraintExists($table, $constraint), $constraint.' must exist at non-paid activation readiness.');
        }
        foreach ([
            ['service_subscriptions_insert_guard', 'zero-cost source authority shape is invalid'],
            ['service_subscriptions_insert_guard', 'clean local lifecycle and no remote binding'],
            ['service_subscriptions_update_guard', 'service_mutation_queue_v1'],
            ['service_subscriptions_update_guard', 'service_mutation_effect_v1'],
            ['provisioning_operations_insert_guard', 'Initial Provisioning Operation zero-cost authority shape is invalid'],
            ['provisioning_operations_insert_guard', 'service_mutation_queue_v1'],
            ['provisioning_operations_update_guard', 'initial_remote_effect_v1'],
            ['provisioning_operations_update_guard', 'service_mutation_effect_v1'],
            ['provisioning_operations_update_guard', 'recovery_transition'],
            ['provisioning_operation_histories_insert_guard', 'service_mutation_requested'],
            ['provisioning_operation_initial_history', 'service_mutation_requested'],
            ['provisioning_remote_effect_events_insert_guard', 'service_mutation_effect_v1'],
        ] as [$trigger, $needle]) {
            self::assertTrue($this->triggerContains($trigger, $needle), $trigger.' must contain readiness marker '.$needle.'.');
        }
    }

    private function assertSourceAuthorityReady(): void
    {
        self::assertTrue($this->constraintExists('order_source_authorizations', 'order_source_authorizations_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('order_source_authorizations', 'order_source_authorizations_bootstrap_block_chk'));
        self::assertFalse($this->triggerExists('order_source_authorizations_bootstrap_insert_barrier'));
        foreach ([
            'order_source_auth_type_chk',
            'order_source_auth_actor_chk',
            'order_source_auth_upstream_shape_chk',
            'order_source_auth_hash_chk',
            'order_source_auth_snapshot_chk',
            'order_source_auth_reason_chk',
        ] as $constraint) {
            self::assertTrue($this->constraintExists('order_source_authorizations', $constraint), $constraint.' must exist.');
        }
        self::assertTrue($this->indexMatches('order_source_authorizations', ['public_id'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['trial_reservation_id'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['trial_reservation_command_key'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['benefit_entitlement_id'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['benefit_entitlement_public_id'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['authorization_key'], true));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['user_id', 'created_at'], false));
        self::assertTrue($this->indexMatches('order_source_authorizations', ['source_type', 'created_at'], false));
        self::assertTrue($this->foreignKeyEquivalent('order_source_authorizations', 'user_id', 'users', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('order_source_authorizations', 'plan_offering_id', 'plan_offerings', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('order_source_authorizations', 'trial_reservation_id', 'trial_reservations', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('order_source_authorizations', 'benefit_entitlement_id', 'benefit_code_free_service_entitlements', 'id'));
        self::assertTrue($this->triggerContains('order_source_authorizations_insert_guard', 'Unsupported non-paid Order authorization source.'));
        self::assertTrue($this->triggerContains('order_source_authorizations_update_guard', 'Order source authorizations are immutable.'));
        self::assertTrue($this->triggerContains('order_source_authorizations_delete_guard', 'Order source authorizations are non-deletable.'));
    }

    private function assertPreparedOrderShapeReady(): void
    {
        foreach (['orders', 'order_items'] as $table) {
            self::assertTrue(Schema::hasColumn($table, 'order_source_authorization_id'));
            self::assertTrue(Schema::hasColumn($table, 'order_source_authorization_public_id'));
        }
        self::assertTrue($this->namedIndexMatches('orders', 'orders_source_authorization_unique', ['order_source_authorization_id'], true));
        self::assertTrue($this->namedIndexMatches('orders', 'orders_source_authorization_public_unique', ['order_source_authorization_public_id'], true));
        self::assertTrue($this->namedIndexMatches('order_items', 'order_items_source_authorization_unique', ['order_source_authorization_id'], true));
        self::assertTrue($this->namedIndexMatches('order_items', 'order_items_source_authorization_public_unique', ['order_source_authorization_public_id'], true));
        self::assertTrue($this->foreignKeyMatches('orders', 'orders_order_source_authorization_id_foreign', 'order_source_authorization_id', 'order_source_authorizations', 'id'));
        self::assertTrue($this->foreignKeyMatches('order_items', 'order_items_order_source_authorization_id_foreign', 'order_source_authorization_id', 'order_source_authorizations', 'id'));
        foreach ([
            ['orders', 'orders_state_chk'],
            ['orders', 'orders_non_purchase_finance_shape_chk'],
            ['orders', 'orders_source_authorization_shape_chk'],
            ['orders', 'orders_non_paid_lifecycle_chk'],
            ['order_items', 'order_items_override_source_chk'],
            ['order_items', 'order_items_source_authority_shape_chk'],
        ] as [$table, $constraint]) {
            self::assertTrue($this->constraintExists($table, $constraint), $constraint.' must exist.');
        }
        self::assertSame('YES', $this->columnNullability('order_items', 'source_quote_id'));
        self::assertSame('YES', $this->columnNullability('order_items', 'source_quote_public_id'));
    }

    private function assertAgentBulkAuthorityReady(): void
    {
        self::assertTrue(Schema::hasTable('agent_bulk_orders'));
        self::assertTrue(Schema::hasTable('agent_bulk_order_items'));
        self::assertTrue($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_authority_ready_v2_chk'));
        self::assertTrue($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_authority_ready_v2_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_orders', 'agent_bulk_orders_bootstrap_block_chk'));
        self::assertFalse($this->constraintExists('agent_bulk_order_items', 'agent_bulk_items_bootstrap_block_chk'));
        self::assertFalse($this->triggerExists('agent_bulk_orders_bootstrap_insert_barrier'));
        self::assertFalse($this->triggerExists('agent_bulk_items_bootstrap_insert_barrier'));
        foreach ([
            ['agent_bulk_orders', 'agent_bulk_orders_hash_chk'],
            ['agent_bulk_orders', 'agent_bulk_orders_count_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_state_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_attempt_chk'],
            ['agent_bulk_order_items', 'agent_bulk_items_result_shape_chk'],
        ] as [$table, $constraint]) {
            self::assertTrue($this->constraintExists($table, $constraint), $constraint.' must exist.');
        }
        self::assertTrue($this->indexMatches('agent_bulk_orders', ['public_id'], true));
        self::assertTrue($this->indexMatches('agent_bulk_orders', ['batch_key'], true));
        self::assertTrue($this->indexMatches('agent_bulk_orders', ['user_id', 'created_at'], false));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_orders', 'user_id', 'users', 'id'));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['public_id'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['purchase_settlement_id'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['order_id'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['order_item_id'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['agent_bulk_order_id', 'line_number'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['agent_bulk_order_id', 'child_key'], true));
        self::assertTrue($this->indexMatches('agent_bulk_order_items', ['agent_bulk_order_id', 'state'], false));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_order_items', 'agent_bulk_order_id', 'agent_bulk_orders', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_order_items', 'purchase_settlement_id', 'purchase_settlements', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_order_items', 'source_quote_id', 'quotes', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_order_items', 'order_id', 'orders', 'id'));
        self::assertTrue($this->foreignKeyEquivalent('agent_bulk_order_items', 'order_item_id', 'order_items', 'id'));
        self::assertTrue($this->triggerContains('agent_bulk_orders_insert_guard', 'bounded immutable request authority'));
        self::assertTrue($this->triggerContains('agent_bulk_items_insert_guard', 'accepted agent purchase settlement authority'));
        self::assertTrue($this->triggerContains('agent_bulk_items_update_guard', 'exact canonical purchase Order and Item'));
    }

    private function assertPurchaseOnlySourceFence(): void
    {
        self::assertTrue($this->triggerContains('orders_unimplemented_source_insert_guard', "IF NEW.source_type <> 'purchase' THEN"));
        foreach (['trial', 'benefit_code', 'admin_grant'] as $sourceType) {
            self::assertFalse($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
        }
    }

    private function assertSupportedSourceFence(): void
    {
        foreach (['purchase', 'trial', 'benefit_code', 'admin_grant'] as $sourceType) {
            self::assertTrue($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
        }
        foreach (['gift', 'service_code'] as $sourceType) {
            self::assertFalse($this->triggerContains('orders_unimplemented_source_insert_guard', "HEX('{$sourceType}')"));
        }
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

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    /** @param list<string> $columns */
    private function namedIndexMatches(string $table, string $index, array $columns, bool $unique): bool
    {
        /** @var list<object{column_name:string,non_unique:int|string,sub_part:int|string|null}> $rows */
        $rows = DB::select(
            'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
            [$table, $index],
        );
        if ($rows === []) {
            return false;
        }
        $actual = array_map(static fn (object $row): string => (string) $row->column_name, $rows);
        $fullColumns = array_reduce(
            $rows,
            static fn (bool $carry, object $row): bool => $carry && $row->sub_part === null,
            true,
        );

        return $actual === $columns
            && ((int) $rows[0]->non_unique === 0) === $unique
            && $fullColumns;
    }

    /** @param list<string> $columns */
    private function indexMatches(string $table, array $columns, bool $unique): bool
    {
        /** @var list<object{index_name:string,column_name:string,non_unique:int|string,sub_part:int|string|null}> $rows */
        $rows = DB::select(
            'SELECT INDEX_NAME AS index_name, COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table],
        );
        /** @var array<string, list<object{index_name:string,column_name:string,non_unique:int|string,sub_part:int|string|null}>> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->index_name][] = $row;
        }
        foreach ($groups as $group) {
            $actual = array_map(static fn ($row): string => (string) $row->column_name, $group);
            $isUnique = $group !== [] && (int) $group[0]->non_unique === 0;
            $fullColumns = $group !== [] && array_reduce(
                $group,
                static fn (bool $carry, $row): bool => $carry && $row->sub_part === null,
                true,
            );
            if ($actual === $columns && $isUnique === $unique && $fullColumns) {
                return true;
            }
        }

        return false;
    }

    private function foreignKeyEquivalent(string $table, string $column, string $referencedTable, string $referencedColumn): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.KEY_COLUMN_USAGE k
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND r.TABLE_NAME = k.TABLE_NAME
WHERE k.CONSTRAINT_SCHEMA = DATABASE()
  AND k.TABLE_NAME = ?
  AND k.COLUMN_NAME = ?
  AND k.REFERENCED_TABLE_NAME = ?
  AND k.REFERENCED_COLUMN_NAME = ?
  AND r.DELETE_RULE = 'RESTRICT'
SQL, [$table, $column, $referencedTable, $referencedColumn]);

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function foreignKeyMatches(string $table, string $constraint, string $column, string $referencedTable, string $referencedColumn): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT k.COLUMN_NAME AS column_name,
       k.REFERENCED_TABLE_NAME AS referenced_table,
       k.REFERENCED_COLUMN_NAME AS referenced_column,
       r.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE k
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
   AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
   AND r.TABLE_NAME = k.TABLE_NAME
WHERE k.CONSTRAINT_SCHEMA = DATABASE()
  AND k.TABLE_NAME = ?
  AND k.CONSTRAINT_NAME = ?
SQL, [$table, $constraint]);

        return $row !== null
            && (string) $row->column_name === $column
            && (string) $row->referenced_table === $referencedTable
            && (string) $row->referenced_column === $referencedColumn
            && (string) $row->delete_rule === 'RESTRICT';
    }

    private function columnNullability(string $table, string $column): string
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );
        self::assertNotNull($row);

        return (string) $row->is_nullable;
    }
}
