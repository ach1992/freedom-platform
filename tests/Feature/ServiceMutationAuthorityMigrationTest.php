<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SVC-004 PRV-002 PRV-003 DAT-003 SEC-008 QUA-004 */
final class ServiceMutationAuthorityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_extends_existing_provisioning_authority_without_parallel_state_machine(): void
    {
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'lifecycle_state'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'lifecycle_version'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'remote_identity_generation'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'mutation_generation'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'remote_deleted_at'));
        $this->assertTrue(Schema::hasColumn('provisioning_operations', 'operation_generation'));
        $this->assertTrue(Schema::hasColumn('provisioning_operations', 'target_remote_identity_generation'));
        $this->assertTrue(Schema::hasColumn('provisioning_operations', 'target_lifecycle_version'));
        $this->assertTrue(Schema::hasColumn('provisioning_operations', 'request_key_hash'));
        $this->assertFalse(Schema::hasTable('service_mutation_operations'));

        $operationGuard = $this->triggerStatement('provisioning_operations_update_guard');
        $insertGuard = $this->triggerStatement('provisioning_operations_insert_guard');
        $serviceInsertGuard = $this->triggerStatement('service_subscriptions_insert_guard');
        $serviceGuard = $this->triggerStatement('service_subscriptions_update_guard');
        $eventGuard = $this->triggerStatement('provisioning_remote_effect_events_insert_guard');

        $this->assertStringContainsString('initial_remote_effect_v1', $operationGuard);
        $this->assertStringContainsString('recovery_transition', $operationGuard);
        $this->assertStringContainsString('service_mutation_effect_v1', $operationGuard);
        $this->assertStringContainsString('BINARY NEW.operation_key = BINARY OLD.operation_key', $operationGuard);
        $this->assertStringContainsString('BINARY NEW.operation_type = BINARY OLD.operation_type', $operationGuard);
        $this->assertStringContainsString('NEW.operation_generation = OLD.operation_generation', $operationGuard);
        $this->assertStringContainsString('NEW.target_remote_identity_generation = OLD.target_remote_identity_generation', $operationGuard);
        $this->assertStringContainsString('NEW.target_lifecycle_version = OLD.target_lifecycle_version', $operationGuard);
        $this->assertStringContainsString("NEW.state <> 'succeeded' OR authoritative_service_id IS NOT NULL", $operationGuard);
        $this->assertStringContainsString('service_mutation_queue_v1', $insertGuard);
        $this->assertStringContainsString('target_remote_identity_generation', $insertGuard);
        $this->assertStringContainsString('target_lifecycle_version', $insertGuard);
        $this->assertStringContainsString('Initial Provisioning Operation cannot be created with remote-effect evidence.', $insertGuard);
        $this->assertStringContainsString('BINARY NEW.correlation_id = BINARY service_row.creation_correlation_id', $insertGuard);
        $this->assertStringContainsString('NEW.route_hold_expires_at IS NOT NULL', $insertGuard);
        $this->assertStringContainsString('NEW.capacity_reservation_id IS NOT NULL', $insertGuard);
        $this->assertStringContainsString('NEW.remote_username IS NOT NULL', $insertGuard);
        $this->assertStringContainsString('Service Subscription must start with a clean local lifecycle and no remote binding.', $serviceInsertGuard);
        $this->assertStringContainsString("NEW.lifecycle_state <> 'active'", $serviceInsertGuard);
        $this->assertStringContainsString('NEW.lifecycle_version <> 0', $serviceInsertGuard);
        $this->assertStringContainsString('NEW.remote_identity_generation <> 1', $serviceInsertGuard);
        $this->assertStringContainsString('NEW.mutation_generation <> 0', $serviceInsertGuard);
        $this->assertStringContainsString('NEW.remote_service_id IS NOT NULL', $serviceInsertGuard);
        $this->assertStringContainsString('service_mutation_queue_v1', $serviceGuard);
        $this->assertStringContainsString('remote_deleted_at', $serviceGuard);
        $this->assertStringContainsString("operation_row.state = 'succeeded'", $serviceGuard);
        $this->assertStringContainsString('operation_row.remote_effect_started_at IS NOT NULL', $serviceGuard);
        $this->assertStringContainsString('operation_row.remote_effect_completed_at IS NOT NULL', $serviceGuard);
        $this->assertStringContainsString('service_mutation_effect_v1', $eventGuard);
        $this->assertStringContainsString('NEW.state_version = operation_row.state_version', $eventGuard);
        $this->assertStringContainsString('BINARY NEW.correlation_id = BINARY operation_row.correlation_id', $eventGuard);
        $this->assertStringContainsString('(NEW.remote_service_id <=> operation_row.remote_service_id)', $eventGuard);
        $this->assertStringContainsString('BINARY NEW.event_type = BINARY operation_row.state', $eventGuard);
    }

    public function test_mutation_operation_insert_fails_closed_without_queue_authority(): void
    {
        $this->expectException(QueryException::class);

        DB::table('provisioning_operations')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_key' => 'service-mutation:'.Str::ulid().':1:suspend',
            'operation_type' => 'suspend',
            'operation_generation' => 1,
            'target_remote_identity_generation' => 1,
            'target_lifecycle_version' => 0,
            'request_key_hash' => hash('sha256', 'unauthorized-request'),
            'order_id' => 1,
            'order_item_id' => 1,
            'service_subscription_id' => 1,
            'user_id' => 1,
            'state' => 'queued',
            'state_version' => 1,
            'correlation_id' => 'unauthorized-correlation',
            'service_target_id' => 1,
            'remote_service_id' => 'remote-1',
            'created_at' => '2026-08-17 00:00:00.000000',
            'updated_at' => '2026-08-17 00:00:00.000000',
        ]);
    }

    public function test_clean_rollback_and_reentry_restore_initial_authority_before_reenabling_mutations(): void
    {
        $migration = require database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('service_subscriptions', 'lifecycle_state'));
        $this->assertFalse(Schema::hasColumn('service_subscriptions', 'lifecycle_version'));
        $this->assertFalse(Schema::hasColumn('service_subscriptions', 'remote_identity_generation'));
        $this->assertFalse(Schema::hasColumn('service_subscriptions', 'mutation_generation'));
        $this->assertFalse(Schema::hasColumn('provisioning_operations', 'operation_generation'));
        $this->assertStringContainsString('initial_remote_effect_v1', $this->triggerStatement('provisioning_operations_update_guard'));
        $this->assertStringNotContainsString('service_mutation_effect_v1', $this->triggerStatement('provisioning_operations_update_guard'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'lifecycle_state'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'remote_identity_generation'));
        $this->assertTrue(Schema::hasColumn('service_subscriptions', 'mutation_generation'));
        $this->assertTrue(Schema::hasColumn('provisioning_operations', 'operation_generation'));
        $this->assertStringContainsString('initial_remote_effect_v1', $this->triggerStatement('provisioning_operations_update_guard'));
        $this->assertStringContainsString('service_mutation_effect_v1', $this->triggerStatement('provisioning_operations_update_guard'));
        $this->assertStringContainsString('clean local lifecycle', $this->triggerStatement('service_subscriptions_insert_guard'));
    }

    public function test_rollback_guard_covers_all_mutation_and_identity_evidence_classes(): void
    {
        $source = file_get_contents(database_path('migrations/2026_08_17_000300_enable_service_mutation_authority.php'));
        self::assertIsString($source);

        foreach ([
            "where('operation_type', '<>', 'initial_provision')",
            "where('mutation_generation', '>', 0)",
            "whereNotNull('remote_deleted_at')",
            "where('lifecycle_version', '>', 0)",
            "where('lifecycle_state', '<>', 'active')",
            "where('remote_identity_generation', '<>', 1)",
            'createServiceInsertAuthority()',
            'service-insert-guard.sql',
        ] as $requiredGuard) {
            self::assertStringContainsString($requiredGuard, $source);
        }
    }

    private function triggerStatement(string $trigger): string
    {
        $row = DB::selectOne(
            'SELECT ACTION_STATEMENT AS statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        $this->assertNotNull($row, $trigger.' must exist.');

        return (string) $row->statement;
    }
}
