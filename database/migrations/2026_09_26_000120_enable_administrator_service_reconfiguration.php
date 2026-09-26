<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-005 ADM-002 ACL-002 ARCH-003 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        foreach ([
            'service_reconfiguration_previews',
            'service_reconfiguration_authorities',
            'administrators',
            'audit_logs',
            'service_subscriptions',
            'service_operational_authority_capability',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    'Administrator Service reconfiguration requires the accepted reconfiguration and operational authority foundations.',
                );
            }
        }

        Schema::table('service_reconfiguration_previews', function (Blueprint $table): void {
            $table->foreignId('actor_administrator_id')->nullable()->after('actor_user_id');
            $table->foreign('actor_administrator_id', 'srp_admin_actor_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->string('administrator_reason_code', 64)->nullable()->after('actor_administrator_id');
            $table->text('administrator_reason')->nullable()->after('administrator_reason_code');
            $table->index(['actor_administrator_id', 'created_at'], 'srp_admin_created_idx');
        });

        Schema::table('service_reconfiguration_authorities', function (Blueprint $table): void {
            $table->foreignId('actor_administrator_id')->nullable()->after('authorization_mode');
            $table->foreign('actor_administrator_id', 'sra_admin_actor_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->foreignId('audit_log_id')->nullable()->after('actor_administrator_id');
            $table->foreign('audit_log_id', 'sra_admin_audit_fk')
                ->references('id')->on('audit_logs')->restrictOnDelete();
        });

        $this->replaceCheck(
            'service_reconfiguration_previews',
            'srp_admin_shape_chk',
            "((actor_administrator_id IS NULL AND administrator_reason_code IS NULL AND administrator_reason IS NULL) OR (actor_administrator_id IS NOT NULL AND administrator_reason_code REGEXP '^[a-z0-9_.-]{1,64}$' AND administrator_reason IS NOT NULL AND CHAR_LENGTH(TRIM(administrator_reason)) BETWEEN 1 AND 1000))",
        );
        $this->replaceCheck(
            'service_reconfiguration_authorities',
            'sra_authorization_shape_chk',
            "((BINARY authorization_mode = BINARY 'paid_purchase' AND source_quote_id IS NOT NULL AND purchase_order_id IS NOT NULL AND purchase_order_item_id IS NOT NULL AND purchase_settlement_id IS NOT NULL AND payment_intent_id IS NOT NULL AND actor_administrator_id IS NULL AND audit_log_id IS NULL) OR (BINARY authorization_mode = BINARY 'no_charge' AND source_quote_id IS NULL AND purchase_order_id IS NULL AND purchase_order_item_id IS NULL AND purchase_settlement_id IS NULL AND payment_intent_id IS NULL AND actor_administrator_id IS NULL AND audit_log_id IS NULL) OR (BINARY authorization_mode = BINARY 'administrator_no_charge' AND source_quote_id IS NULL AND purchase_order_id IS NULL AND purchase_order_item_id IS NULL AND purchase_settlement_id IS NULL AND payment_intent_id IS NULL AND actor_administrator_id IS NOT NULL AND audit_log_id IS NOT NULL))",
        );

        foreach ([
            'preview-insert-guard-v2.sql',
            'authority-insert-guard-v2.sql',
            'authority-update-guard-v2.sql',
            'audit-insert-guard-v2.sql',
        ] as $file) {
            $this->installSql($file);
        }
    }

    public function down(): void
    {
        if (DB::table('service_reconfiguration_previews')->whereNotNull('actor_administrator_id')->exists()
            || DB::table('service_reconfiguration_authorities')
                ->where('authorization_mode', 'administrator_no_charge')
                ->exists()) {
            throw new RuntimeException(
                'Cannot roll back administrator Service reconfiguration while administrator authority evidence exists.',
            );
        }

        foreach ([
            'preview-insert-guard-v1.sql',
            'authority-insert-guard-v1.sql',
            'authority-update-guard-v1.sql',
            'audit-insert-guard-v1.sql',
        ] as $file) {
            $this->installSql($file);
        }

        $this->replaceCheck(
            'service_reconfiguration_authorities',
            'sra_authorization_shape_chk',
            "((BINARY authorization_mode = BINARY 'paid_purchase' AND source_quote_id IS NOT NULL AND purchase_order_id IS NOT NULL AND purchase_order_item_id IS NOT NULL AND purchase_settlement_id IS NOT NULL AND payment_intent_id IS NOT NULL) OR (BINARY authorization_mode = BINARY 'no_charge' AND source_quote_id IS NULL AND purchase_order_id IS NULL AND purchase_order_item_id IS NULL AND purchase_settlement_id IS NULL AND payment_intent_id IS NULL))",
        );
        if ($this->constraintExists('service_reconfiguration_previews', 'srp_admin_shape_chk')) {
            DB::statement(
                'ALTER TABLE service_reconfiguration_previews DROP CONSTRAINT srp_admin_shape_chk',
            );
        }

        Schema::table('service_reconfiguration_authorities', function (Blueprint $table): void {
            $table->dropForeign('sra_admin_audit_fk');
            $table->dropForeign('sra_admin_actor_fk');
            $table->dropColumn(['audit_log_id', 'actor_administrator_id']);
        });
        Schema::table('service_reconfiguration_previews', function (Blueprint $table): void {
            $table->dropForeign('srp_admin_actor_fk');
            $table->dropIndex('srp_admin_created_idx');
            $table->dropColumn([
                'administrator_reason',
                'administrator_reason_code',
                'actor_administrator_id',
            ]);
        });
    }

    private function replaceCheck(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$definition})");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function installSql(string $file): void
    {
        $sql = file_get_contents(database_path('sql/service-administrator-reconfiguration/'.$file));
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException(
                'Administrator Service reconfiguration SQL asset is unavailable: '.$file,
            );
        }

        // @phpstan-ignore-next-line argument.type -- verified migration-owned SQL asset.
        DB::unprepared($sql);
    }
};
