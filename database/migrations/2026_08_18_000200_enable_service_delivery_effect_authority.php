<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SQL_DIRECTORY = 'migrations/support/service_delivery_effect_authority';

    /** @requirement SVC-002 SVC-014 PRV-002 PRV-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function up(): void
    {
        if (! Schema::hasTable('service_delivery_attempts')
            || ! Schema::hasTable('service_subscriptions')
            || ! Schema::hasTable('provisioning_operations')
            || ! Schema::hasTable('telegram_accounts')) {
            throw new RuntimeException('Service delivery effect authority requires Delivery Attempt, Service, mutation, and Telegram identity foundations.');
        }

        if (! Schema::hasTable('service_delivery_effects')) {
            $this->executeRepositorySql('01_create_table.sql');
        }
        if (! Schema::hasTable('service_initial_delivery_fences')) {
            $this->createInitialDeliveryFenceTable();
        }

        $this->createInitialDeliveryFenceGuards();
        $this->executeRepositorySql('02_insert_guard.sql');
        $this->executeRepositorySql('03_update_guard.sql');
        $this->executeRepositorySql('04_delete_guard.sql');
        $this->executeRepositorySql('05_mutation_insert_fence.sql');
        $this->executeRepositorySql('06_delivery_attempt_insert_fence.sql');
    }

    public function down(): void
    {
        if (Schema::hasTable('service_delivery_effects') && DB::table('service_delivery_effects')->exists()) {
            throw new RuntimeException('Cannot roll back Service delivery effect authority while effect evidence exists.');
        }
        if (Schema::hasTable('service_initial_delivery_fences') && DB::table('service_initial_delivery_fences')->exists()) {
            throw new RuntimeException('Cannot roll back Service delivery effect authority while initial delivery scheduling is fenced.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempts_effect_fence_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_delivery_effect_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_initial_delivery_fences_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_initial_delivery_fences_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_initial_delivery_fences_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_insert_guard');
        Schema::dropIfExists('service_initial_delivery_fences');
        Schema::dropIfExists('service_delivery_effects');
    }

    private function createInitialDeliveryFenceTable(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE service_initial_delivery_fences (
    service_subscription_id BIGINT UNSIGNED NOT NULL,
    provisioning_operation_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (service_subscription_id),
    UNIQUE KEY service_initial_delivery_fences_operation_unique (provisioning_operation_id),
    CONSTRAINT service_initial_delivery_fences_service_fk FOREIGN KEY (service_subscription_id) REFERENCES service_subscriptions (id) ON DELETE RESTRICT,
    CONSTRAINT service_initial_delivery_fences_operation_fk FOREIGN KEY (provisioning_operation_id) REFERENCES provisioning_operations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
    }

    private function createInitialDeliveryFenceGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_initial_delivery_fences_insert_guard
BEFORE INSERT ON service_initial_delivery_fences
FOR EACH ROW
BEGIN
    DECLARE valid_binding_count INT DEFAULT 0;

    IF COALESCE(@app_initial_delivery_fence_authority, '') <> 'initial_delivery_fence_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_initial_delivery_fence_service_id, 0)
       OR NEW.provisioning_operation_id <> COALESCE(@app_initial_delivery_fence_operation_id, 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial delivery scheduling fence creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_binding_count
    FROM provisioning_operations operation_row
    WHERE operation_row.id = NEW.provisioning_operation_id
      AND operation_row.service_subscription_id = NEW.service_subscription_id
      AND operation_row.operation_type = 'initial_provision';

    IF valid_binding_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial delivery scheduling fence must match one initial provisioning operation.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_initial_delivery_fences_update_guard
BEFORE UPDATE ON service_initial_delivery_fences
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial delivery scheduling fence evidence is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_initial_delivery_fences_delete_guard
BEFORE DELETE ON service_initial_delivery_fences
FOR EACH ROW
BEGIN
    IF COALESCE(@app_initial_delivery_fence_authority, '') <> 'initial_delivery_fence_v1'
       OR OLD.service_subscription_id <> COALESCE(@app_initial_delivery_fence_service_id, 0)
       OR OLD.provisioning_operation_id <> COALESCE(@app_initial_delivery_fence_operation_id, 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Initial delivery scheduling fence release authority is invalid.';
    END IF;
END
SQL);
    }

    private function executeRepositorySql(string $filename): void
    {
        if (DB::connection()->getPdo()->exec($this->sql($filename)) === false) {
            throw new RuntimeException('Service delivery effect migration SQL execution failed: '.$filename);
        }
    }

    private function sql(string $filename): string
    {
        $path = database_path(self::SQL_DIRECTORY.'/'.$filename);
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Service delivery effect migration SQL is unavailable: '.$filename);
        }

        return $sql;
    }
};
