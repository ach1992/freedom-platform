<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const PREVIOUS_AUTHORITY_MIGRATIONS = [
        '2026_08_14_001162_create_provisioning_queue_authority.php',
        '2026_08_16_000100_enable_initial_provisioning_remote_effect.php',
        '2026_08_16_000102_enable_initial_provisioning_uncertain_recovery.php',
        '2026_08_16_000105_activate_initial_provisioning_remote_effect.php',
    ];

    /** @requirement SVC-004 PRV-001 PRV-002 PRV-003 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        $this->repairInterruptedRollbackIfNeeded();
        $this->assertPrerequisites();
        $this->failClosedDuringUpgrade();
        $this->addColumns();
        $this->replaceOperationShapeAuthority();
        $this->replaceServiceShapeAuthority();
        $this->createServiceInsertAuthority();
        $this->createHistoryAuthority();
        $this->createRemoteEffectEventAuthority();
        $this->createOperationUpdateAuthority();
        $this->createServiceUpdateAuthority();
        // Final enabling DDL: until this succeeds no new mutation operation can be created.
        $this->createOperationInsertAuthority();
    }

    public function down(): void
    {
        if (DB::table('provisioning_operations')->where('state', 'running')->exists()) {
            throw new RuntimeException('Cannot roll back Service mutation authority while a provisioning remote effect is running.');
        }
        if (DB::table('provisioning_operations')->where('operation_type', '<>', 'initial_provision')->exists()) {
            throw new RuntimeException('Cannot roll back Service mutation authority after mutation evidence exists.');
        }
        if ($this->hasServiceMutationEvidence()) {
            throw new RuntimeException('Cannot roll back Service mutation authority after Service mutation or identity evidence exists.');
        }

        $this->failClosedDuringUpgrade();
        $this->dropMutationShape();
        $this->restorePreviousInitialAuthority();
    }

    private function repairInterruptedRollbackIfNeeded(): void
    {
        if ($this->mutationShapeStarted()
            || $this->initialAuthorityIsExact()
            || ! $this->baseRemoteEffectSchemaAvailable()) {
            return;
        }

        $this->restorePreviousInitialAuthority();
    }

    private function hasServiceMutationEvidence(): bool
    {
        if (Schema::hasColumn('service_subscriptions', 'mutation_generation')
            && DB::table('service_subscriptions')->where('mutation_generation', '>', 0)->exists()) {
            return true;
        }
        if (Schema::hasColumn('service_subscriptions', 'remote_deleted_at')
            && DB::table('service_subscriptions')->whereNotNull('remote_deleted_at')->exists()) {
            return true;
        }
        if (Schema::hasColumn('service_subscriptions', 'lifecycle_version')
            && DB::table('service_subscriptions')->where('lifecycle_version', '>', 0)->exists()) {
            return true;
        }
        if (Schema::hasColumn('service_subscriptions', 'lifecycle_state')
            && DB::table('service_subscriptions')->where('lifecycle_state', '<>', 'active')->exists()) {
            return true;
        }

        return Schema::hasColumn('service_subscriptions', 'remote_identity_generation')
            && DB::table('service_subscriptions')->where('remote_identity_generation', '<>', 1)->exists();
    }

    private function mutationShapeStarted(): bool
    {
        return Schema::hasColumn('service_subscriptions', 'mutation_generation')
            || Schema::hasColumn('service_subscriptions', 'remote_deleted_at')
            || Schema::hasColumn('service_subscriptions', 'lifecycle_state')
            || Schema::hasColumn('service_subscriptions', 'lifecycle_version')
            || Schema::hasColumn('service_subscriptions', 'remote_identity_generation')
            || Schema::hasColumn('provisioning_operations', 'operation_generation')
            || Schema::hasColumn('provisioning_operations', 'target_remote_identity_generation')
            || Schema::hasColumn('provisioning_operations', 'target_lifecycle_version')
            || Schema::hasColumn('provisioning_operations', 'request_key_hash');
    }

    private function baseRemoteEffectSchemaAvailable(): bool
    {
        foreach (['provisioning_operations', 'service_subscriptions', 'provisioning_operation_histories', 'provisioning_remote_effect_events'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }
        foreach (['effect_fence_key', 'service_target_id', 'remote_service_id', 'remote_effect_started_at', 'remote_effect_completed_at'] as $column) {
            if (! Schema::hasColumn('provisioning_operations', $column)) {
                return false;
            }
        }
        foreach (['service_target_id', 'remote_service_id', 'provisioned_at'] as $column) {
            if (! Schema::hasColumn('service_subscriptions', $column)) {
                return false;
            }
        }

        return true;
    }

    private function initialAuthorityIsExact(): bool
    {
        return $this->triggerContains('provisioning_operations_update_guard', 'initial_remote_effect_v1')
            && $this->triggerContains('provisioning_operations_update_guard', 'recovery_transition');
    }

    private function restorePreviousInitialAuthority(): void
    {
        foreach (self::PREVIOUS_AUTHORITY_MIGRATIONS as $file) {
            $migration = require __DIR__.'/'.$file;
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                throw new RuntimeException('Previous initial provisioning authority migration is unavailable.');
            }
            $migration->up();
        }
    }

    private function dropMutationShape(): void
    {
        foreach ([
            'service_subscriptions_mutation_lifecycle_chk',
            'service_subscriptions_remote_identity_generation_chk',
            'service_subscriptions_lifecycle_version_chk',
            'service_subscriptions_lifecycle_state_chk',
        ] as $constraint) {
            if ($this->constraintExists('service_subscriptions', $constraint)) {
                DB::statement(match ($constraint) {
                    'service_subscriptions_mutation_lifecycle_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_mutation_lifecycle_chk',
                    'service_subscriptions_remote_identity_generation_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_remote_identity_generation_chk',
                    'service_subscriptions_lifecycle_version_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_lifecycle_version_chk',
                    'service_subscriptions_lifecycle_state_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_lifecycle_state_chk',
                });
            }
        }

        foreach (['provisioning_operations_mutation_shape_chk', 'provisioning_operations_generation_chk', 'provisioning_operations_type_chk'] as $constraint) {
            if ($this->constraintExists('provisioning_operations', $constraint)) {
                DB::statement(match ($constraint) {
                    'provisioning_operations_mutation_shape_chk' => 'ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_mutation_shape_chk',
                    'provisioning_operations_generation_chk' => 'ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_generation_chk',
                    'provisioning_operations_type_chk' => 'ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_type_chk',
                });
            }
        }
        DB::statement("ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_type_chk CHECK (`operation_type` = 'initial_provision')");

        foreach (['provisioning_operations_service_request_unique', 'provisioning_operations_service_generation_unique'] as $index) {
            if ($this->indexExists('provisioning_operations', $index)) {
                DB::statement(match ($index) {
                    'provisioning_operations_service_request_unique' => 'ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_service_request_unique',
                    'provisioning_operations_service_generation_unique' => 'ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_service_generation_unique',
                });
            }
        }
        if (! $this->indexExists('provisioning_operations', 'provisioning_operations_item_type_unique')) {
            DB::statement('ALTER TABLE provisioning_operations ADD UNIQUE INDEX provisioning_operations_item_type_unique (order_item_id, operation_type)');
        }

        foreach ([
            ['provisioning_operations', 'request_key_hash'],
            ['provisioning_operations', 'target_lifecycle_version'],
            ['provisioning_operations', 'target_remote_identity_generation'],
            ['provisioning_operations', 'operation_generation'],
            ['service_subscriptions', 'remote_deleted_at'],
            ['service_subscriptions', 'mutation_generation'],
            ['service_subscriptions', 'remote_identity_generation'],
            ['service_subscriptions', 'lifecycle_version'],
            ['service_subscriptions', 'lifecycle_state'],
        ] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }

    private function assertPrerequisites(): void
    {
        if (! $this->baseRemoteEffectSchemaAvailable()) {
            throw new RuntimeException('Service mutation authority prerequisites are incomplete.');
        }
        if (DB::table('provisioning_operations')->where('state', 'running')->exists()) {
            throw new RuntimeException('Cannot upgrade Service mutation authority while a provisioning remote effect is running.');
        }
        if (! $this->mutationShapeStarted() && ! $this->initialAuthorityIsExact()) {
            throw new RuntimeException('Initial provisioning exact remote-effect authority is not active.');
        }
    }

    private function failClosedDuringUpgrade(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_insert_guard
BEFORE INSERT ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning operation creation is disabled during Service mutation authority upgrade.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER provisioning_operations_update_guard
BEFORE UPDATE ON provisioning_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provisioning operation mutation is disabled during Service mutation authority upgrade.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_insert_guard
BEFORE INSERT ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service creation is disabled during Service mutation authority upgrade.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_subscriptions_update_guard
BEFORE UPDATE ON service_subscriptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service mutation is disabled during Service mutation authority upgrade.';
END
SQL);
    }

    private function addColumns(): void
    {
        if (! Schema::hasColumn('service_subscriptions', 'lifecycle_state')) {
            Schema::table('service_subscriptions', function (Blueprint $table): void {
                $table->string('lifecycle_state', 16)->default('active')->after('provisioned_at');
            });
        }
        if (! Schema::hasColumn('service_subscriptions', 'lifecycle_version')) {
            Schema::table('service_subscriptions', function (Blueprint $table): void {
                $table->unsignedBigInteger('lifecycle_version')->default(0)->after('lifecycle_state');
            });
        }
        if (! Schema::hasColumn('service_subscriptions', 'remote_identity_generation')) {
            Schema::table('service_subscriptions', function (Blueprint $table): void {
                $table->unsignedBigInteger('remote_identity_generation')->default(1)->after('lifecycle_version');
            });
        }
        if (! Schema::hasColumn('service_subscriptions', 'mutation_generation')) {
            Schema::table('service_subscriptions', function (Blueprint $table): void {
                $table->unsignedBigInteger('mutation_generation')->default(0)->after('remote_identity_generation');
            });
        }
        if (! Schema::hasColumn('service_subscriptions', 'remote_deleted_at')) {
            Schema::table('service_subscriptions', function (Blueprint $table): void {
                $table->dateTime('remote_deleted_at', 6)->nullable()->after('mutation_generation');
            });
        }
        if (! Schema::hasColumn('provisioning_operations', 'operation_generation')) {
            Schema::table('provisioning_operations', function (Blueprint $table): void {
                $table->unsignedBigInteger('operation_generation')->default(0)->after('operation_type');
            });
        }
        if (! Schema::hasColumn('provisioning_operations', 'target_remote_identity_generation')) {
            Schema::table('provisioning_operations', function (Blueprint $table): void {
                $table->unsignedBigInteger('target_remote_identity_generation')->default(0)->after('operation_generation');
            });
        }
        if (! Schema::hasColumn('provisioning_operations', 'target_lifecycle_version')) {
            Schema::table('provisioning_operations', function (Blueprint $table): void {
                $table->unsignedBigInteger('target_lifecycle_version')->default(0)->after('target_remote_identity_generation');
            });
        }
        if (! Schema::hasColumn('provisioning_operations', 'request_key_hash')) {
            Schema::table('provisioning_operations', function (Blueprint $table): void {
                $table->char('request_key_hash', 64)->nullable()->after('target_lifecycle_version');
            });
        }
    }

    private function replaceOperationShapeAuthority(): void
    {
        foreach (['provisioning_operations_mutation_shape_chk', 'provisioning_operations_type_chk'] as $constraint) {
            if ($this->constraintExists('provisioning_operations', $constraint)) {
                DB::statement(match ($constraint) {
                    'provisioning_operations_mutation_shape_chk' => 'ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_mutation_shape_chk',
                    'provisioning_operations_type_chk' => 'ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_type_chk',
                });
            }
        }
        DB::statement("ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_type_chk CHECK (`operation_type` IN ('initial_provision','reset_usage','suspend','activate','delete','rotate_subscription_link'))");

        if (! $this->constraintExists('provisioning_operations', 'provisioning_operations_generation_chk')) {
            DB::statement('ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_generation_chk CHECK (`operation_generation` >= 0)');
        }
        DB::statement(<<<'SQL'
ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_mutation_shape_chk CHECK (
    (`operation_type` = 'initial_provision'
        AND `operation_generation` = 0
        AND `target_remote_identity_generation` = 0
        AND `target_lifecycle_version` = 0
        AND `request_key_hash` IS NULL)
    OR
    (`operation_type` <> 'initial_provision'
        AND `operation_generation` >= 1
        AND `target_remote_identity_generation` >= 1
        AND `target_lifecycle_version` >= 0
        AND `request_key_hash` IS NOT NULL
        AND CHAR_LENGTH(`request_key_hash`) = 64)
)
SQL);

        if ($this->indexExists('provisioning_operations', 'provisioning_operations_item_type_unique')) {
            DB::statement('ALTER TABLE provisioning_operations DROP INDEX provisioning_operations_item_type_unique');
        }
        if (! $this->indexExists('provisioning_operations', 'provisioning_operations_service_generation_unique')) {
            DB::statement('ALTER TABLE provisioning_operations ADD UNIQUE INDEX provisioning_operations_service_generation_unique (service_subscription_id, operation_generation)');
        }
        if (! $this->indexExists('provisioning_operations', 'provisioning_operations_service_request_unique')) {
            DB::statement('ALTER TABLE provisioning_operations ADD UNIQUE INDEX provisioning_operations_service_request_unique (service_subscription_id, request_key_hash)');
        }
    }

    private function replaceServiceShapeAuthority(): void
    {
        foreach ([
            'service_subscriptions_mutation_lifecycle_chk',
            'service_subscriptions_remote_identity_generation_chk',
            'service_subscriptions_lifecycle_version_chk',
            'service_subscriptions_lifecycle_state_chk',
        ] as $constraint) {
            if ($this->constraintExists('service_subscriptions', $constraint)) {
                DB::statement(match ($constraint) {
                    'service_subscriptions_mutation_lifecycle_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_mutation_lifecycle_chk',
                    'service_subscriptions_remote_identity_generation_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_remote_identity_generation_chk',
                    'service_subscriptions_lifecycle_version_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_lifecycle_version_chk',
                    'service_subscriptions_lifecycle_state_chk' => 'ALTER TABLE service_subscriptions DROP CONSTRAINT service_subscriptions_lifecycle_state_chk',
                });
            }
        }

        DB::statement("ALTER TABLE service_subscriptions ADD CONSTRAINT service_subscriptions_lifecycle_state_chk CHECK (`lifecycle_state` IN ('active','suspended','retired'))");
        DB::statement('ALTER TABLE service_subscriptions ADD CONSTRAINT service_subscriptions_lifecycle_version_chk CHECK (`lifecycle_version` >= 0)');
        DB::statement('ALTER TABLE service_subscriptions ADD CONSTRAINT service_subscriptions_remote_identity_generation_chk CHECK (`remote_identity_generation` >= 1)');
        DB::statement(<<<'SQL'
ALTER TABLE service_subscriptions ADD CONSTRAINT service_subscriptions_mutation_lifecycle_chk CHECK (
    (`lifecycle_state` = 'retired' AND `remote_deleted_at` IS NOT NULL)
    OR
    (`lifecycle_state` IN ('active','suspended') AND `remote_deleted_at` IS NULL)
)
SQL);
    }

    private function createOperationInsertAuthority(): void
    {
        $this->installSql('operation-insert-guard.sql');
    }

    private function createServiceInsertAuthority(): void
    {
        $this->installSql('service-insert-guard.sql');
    }

    private function createServiceUpdateAuthority(): void
    {
        $this->installSql('service-update-guard.sql');
    }

    private function createOperationUpdateAuthority(): void
    {
        $this->installSql('operation-update-guard.sql');
    }

    private function createHistoryAuthority(): void
    {
        $this->installSql('history-insert-guard.sql');
        $this->installSql('history-after-insert.sql');
    }

    private function createRemoteEffectEventAuthority(): void
    {
        $this->installSql('remote-effect-event-insert-guard.sql');
    }

    private function installSql(string $file): void
    {
        $path = database_path('sql/service-mutation-authority/'.$file);
        $sql = file_get_contents($path);
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service mutation authority SQL asset is unavailable: '.$file);
        }
        DB::connection()->getPdo()->exec($sql);
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }

    private function triggerContains(string $trigger, string $needle): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND LOCATE(?, ACTION_STATEMENT) > 0',
            [$trigger, $needle],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
