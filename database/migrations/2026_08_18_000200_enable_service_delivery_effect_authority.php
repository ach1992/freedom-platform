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

        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_attempts_effect_fence_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS provisioning_operations_delivery_effect_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_delivery_effects_insert_guard');
        Schema::dropIfExists('service_delivery_effects');
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
