<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Provisioning\Application\ServiceBatchGrantService;
use App\Modules\Provisioning\Application\ServiceOperationalAuthorityGuard;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-008 SVC-009 SVC-010 SVC-011 SVC-012 DAT-003 DAT-004 QUA-004 */
final class ServiceOperationalMigrationSafetyTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Service operational migration safety requires MariaDB/MySQL.');
        }
        $this->seed();
        $this->migration()->up();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateDatabaseTables();
        } finally {
            parent::tearDown();
        }
    }

    public function test_interrupted_down_is_consumer_fail_closed_restores_predecessor_guard_and_retries_cleanly(): void
    {
        $faultInjected = false;
        DB::listen(function (QueryExecuted $query) use (&$faultInjected): void {
            if ($faultInjected) {
                return;
            }
            $sql = strtolower(trim($query->sql));
            if (! str_contains($sql, 'drop trigger if exists service_imports_delete_guard')) {
                return;
            }

            $faultInjected = true;
            throw new RuntimeException('service-operational-down-fault');
        });

        try {
            $this->migration()->down();
            self::fail('Interrupted Service operational rollback must surface the injected fault.');
        } catch (RuntimeException $exception) {
            self::assertSame('service-operational-down-fault', $exception->getMessage());
        }
        self::assertTrue($faultInjected);
        foreach ($this->bootstrapChecks() as $table => $constraint) {
            self::assertTrue($this->constraintExists($table, $constraint), $table.' must remain blocked after interrupted rollback.');
        }
        try {
            $this->app->make(ServiceOperationalAuthorityGuard::class)->assertFinalized();
            self::fail('Partially dismantled Service operational authority must not remain consumable.');
        } catch (RuntimeException $exception) {
            self::assertSame('Service operational authority is not finalized.', $exception->getMessage());
        }
        $guard = $this->serviceUpdateGuard();
        self::assertStringNotContainsString('service_import_attach_v1', $guard);
        self::assertStringNotContainsString('service_ownership_transfer_v1', $guard);
        self::assertStringNotContainsString('service_repair_v1', $guard);
        self::assertStringContainsString('initial_remote_effect_v1', $guard);
        self::assertStringContainsString('service_mutation_queue_v1', $guard);
        self::assertStringContainsString('service_mutation_effect_v1', $guard);

        $this->migration()->down();
        foreach (array_keys($this->bootstrapChecks()) as $table) {
            self::assertFalse(DB::getSchemaBuilder()->hasTable($table));
        }
        self::assertFalse(DB::getSchemaBuilder()->hasTable('service_operational_authority_capability'));
        $this->migration()->up();
        self::assertTrue(DB::getSchemaBuilder()->hasTable('service_operational_authority_capability'));
        $this->app->make(ServiceOperationalAuthorityGuard::class)->assertFinalized();
        $guard = $this->serviceUpdateGuard();
        self::assertStringContainsString('service_import_attach_v1', $guard);
        self::assertStringContainsString('service_ownership_transfer_v1', $guard);
        self::assertStringContainsString('service_repair_v1', $guard);
    }

    public function test_operational_database_capability_is_present_and_immutable(): void
    {
        self::assertTrue(DB::getSchemaBuilder()->hasTable('service_operational_authority_capability'));
        $before = DB::table('service_operational_authority_capability')->where('id', 1)->value('capability_hash');
        self::assertIsString($before);
        self::assertSame(64, strlen($before));

        foreach (['update', 'delete', 'insert'] as $operation) {
            try {
                match ($operation) {
                    'update' => DB::table('service_operational_authority_capability')->where('id', 1)->update([
                        'capability_hash' => hash('sha256', 'forged-capability'),
                    ]),
                    'delete' => DB::table('service_operational_authority_capability')->where('id', 1)->delete(),
                    'insert' => DB::table('service_operational_authority_capability')->insert([
                        'id' => 2,
                        'capability_hash' => hash('sha256', 'forged-capability'),
                        'created_at' => now('UTC'),
                    ]),
                };
                self::fail('Service operational database capability must be immutable: '.$operation);
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service operational database capability is immutable.', $exception->getMessage());
            }
        }
        self::assertSame($before, DB::table('service_operational_authority_capability')->where('id', 1)->value('capability_hash'));
    }

    public function test_down_refuses_durable_batch_evidence_before_closing_or_dismantling_authority(): void
    {
        $offering = $this->activeBenefitOffering('service-operational-down-evidence');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $context = new ServiceOperationalContext(
            'service-operational-down-evidence',
            'svc-down-'.substr(hash('sha256', 'service-operational-down-evidence'), 0, 32),
            'service_operational_test',
            'Service operational rollback evidence test.',
            $ownerId,
        );
        $this->app->make(ServiceBatchGrantService::class)->create($context, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);

        try {
            $this->migration()->down();
            self::fail('Service operational rollback must refuse durable batch evidence.');
        } catch (RuntimeException $exception) {
            self::assertSame('Cannot roll back Service operational authority after durable operational evidence exists.', $exception->getMessage());
        }

        $this->app->make(ServiceOperationalAuthorityGuard::class)->assertFinalized();
        foreach ($this->bootstrapChecks() as $table => $constraint) {
            self::assertFalse($this->constraintExists($table, $constraint));
        }
        $guard = $this->serviceUpdateGuard();
        self::assertStringContainsString('service_import_attach_v1', $guard);
        self::assertStringContainsString('service_ownership_transfer_v1', $guard);
        self::assertStringContainsString('service_repair_v1', $guard);
        self::assertSame(1, DB::table('service_batch_grants')->count());
        self::assertSame(1, DB::table('service_batch_grant_items')->count());
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');

        return $migration;
    }

    /** @return array<string,string> */
    private function bootstrapChecks(): array
    {
        return [
            'service_imports' => 'service_imports_bootstrap_block_chk',
            'service_ownership_transfers' => 'service_transfers_bootstrap_block_chk',
            'service_reconciliation_cases' => 'service_reconciliation_bootstrap_block_chk',
            'service_reconciliation_changes' => 'service_reconciliation_changes_bootstrap_block_chk',
            'service_batch_grants' => 'service_batch_grants_bootstrap_block_chk',
            'service_batch_grant_items' => 'service_batch_items_bootstrap_block_chk',
        ];
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function serviceUpdateGuard(): string
    {
        $row = DB::selectOne(<<<'SQL'
SELECT ACTION_STATEMENT AS action_statement
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'service_subscriptions_update_guard'
SQL);
        if ($row === null || ! is_string($row->action_statement)) {
            throw new RuntimeException('Service Subscription update guard is unavailable.');
        }

        return $row->action_statement;
    }
}
