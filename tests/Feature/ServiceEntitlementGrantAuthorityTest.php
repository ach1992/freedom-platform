<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Provisioning\Application\ServiceMutationQueueService;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SVC-012 ADM-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
final class ServiceEntitlementGrantAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_authority_extends_canonical_service_mutation_surface_and_is_capability_fenced(): void
    {
        self::assertTrue(Schema::hasTable('service_entitlement_grant_batches'));
        self::assertTrue(Schema::hasTable('service_entitlement_grant_items'));
        self::assertTrue(Schema::hasTable('service_entitlement_grant_authorities'));

        $typeConstraint = $this->checkConstraintClause('provisioning_operations', 'provisioning_operations_type_chk');
        self::assertStringContainsString('grant_data', $typeConstraint);
        self::assertStringContainsString('grant_days', $typeConstraint);
        self::assertStringContainsString('grant_data_days', $typeConstraint);

        $operationInsert = $this->triggerStatement('provisioning_operations_insert_guard');
        self::assertStringContainsString('service_entitlement_grant_queue_v1', $operationInsert);
        self::assertStringContainsString('service_operational_authority_capability', $operationInsert);
        self::assertStringContainsString('service_entitlement_grant_items grant_item', $operationInsert);
        self::assertStringContainsString("grant_batch.state = 'active'", $operationInsert);

        $batchInsert = $this->triggerStatement('service_entitlement_grant_batches_insert_guard');
        self::assertStringContainsString('service_operational_authority_capability', $batchInsert);
        self::assertStringContainsString('service.operational.entitlement_grant.previewed', $batchInsert);
        self::assertStringContainsString('request_fingerprint', $batchInsert);

        $batchUpdate = $this->triggerStatement('service_entitlement_grant_batches_update_guard');
        self::assertStringContainsString('queued_items', $batchUpdate);
        self::assertStringContainsString('needs_review_items', $batchUpdate);
        self::assertStringContainsString('NEW.queued_count <> queued_items', $batchUpdate);
        self::assertStringContainsString('pending_items = OLD.item_count', $batchUpdate);

        $authorityInsert = $this->triggerStatement('service_entitlement_grant_authorities_insert_guard');
        self::assertStringContainsString('service_operational_authority_capability', $authorityInsert);
        self::assertStringContainsString('service_entitlement_grant_item_id', $authorityInsert);
    }

    public function test_grant_batch_insert_fails_closed_without_operational_capability_and_audit_authority(): void
    {
        $this->expectException(QueryException::class);

        DB::table('service_entitlement_grant_batches')->insert([
            'public_id' => (string) Str::ulid(),
            'request_key_hash' => hash('sha256', 'forged-grant-batch-request'),
            'payload_hash' => hash('sha256', 'forged-grant-batch-payload'),
            'source_type' => 'admin_grant',
            'actor_administrator_id' => 1,
            'audit_log_id' => 1,
            'reason_code' => 'forged',
            'reason' => 'forged entitlement grant',
            'selection_mode' => 'explicit',
            'selected_sales_server_id' => null,
            'data_bytes' => 1024,
            'duration_days' => null,
            'notify_customers' => true,
            'state' => 'previewed',
            'item_count' => 1,
            'queued_count' => 0,
            'succeeded_count' => 0,
            'failed_count' => 0,
            'needs_review_count' => 0,
            'cancelled_count' => 0,
            'correlation_id' => 'forged-grant-correlation',
            'expires_at' => '2026-09-26 01:15:00.000000',
            'items_committed_at' => null,
            'created_at' => '2026-09-26 01:00:00.000000',
            'updated_at' => '2026-09-26 01:00:00.000000',
            'completed_at' => null,
        ]);
    }

    public function test_generic_service_mutation_queue_rejects_administrative_grant_types(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Administrative Service entitlement grants require audited grant authority.');

        $this->app->make(ServiceMutationQueueService::class)->queue(
            (string) Str::ulid(),
            ServiceMutationType::GrantData,
            'grant-bypass-request',
            'grant-bypass-correlation',
        );
    }

    private function triggerStatement(string $triggerName): string
    {
        $row = DB::selectOne(
            'SELECT ACTION_STATEMENT AS action_statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$triggerName],
        );
        self::assertNotNull($row, "Missing trigger {$triggerName}.");
        self::assertIsString($row->action_statement);

        return $row->action_statement;
    }

    private function checkConstraintClause(string $table, string $constraint): string
    {
        $row = DB::selectOne(
            'SELECT CHECK_CLAUSE AS check_clause FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$constraint],
        );
        self::assertNotNull($row, "Missing CHECK {$table}.{$constraint}.");
        self::assertIsString($row->check_clause);

        return $row->check_clause;
    }
}
