<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-012 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        foreach ([
            'service_entitlement_grant_batches',
            'service_entitlement_grant_items',
            'service_delivery_attempts',
            'service_delivery_effects',
            'service_notification_delivery_bindings',
            'service_operational_authority_capability',
            'provisioning_operations',
            'service_subscriptions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    'Service entitlement grant notification authority requires the accepted grant and delivery foundations.',
                );
            }
        }

        if (! Schema::hasTable('service_entitlement_grant_notification_bindings')) {
            Schema::create('service_entitlement_grant_notification_bindings', function (Blueprint $table): void {
                $table->foreignId('service_delivery_attempt_id')->primary();
                $table->foreign('service_delivery_attempt_id', 'segnb_attempt_fk')
                    ->references('id')->on('service_delivery_attempts')->restrictOnDelete();
                $table->foreignId('service_entitlement_grant_item_id');
                $table->foreign('service_entitlement_grant_item_id', 'segnb_item_fk')
                    ->references('id')->on('service_entitlement_grant_items')->restrictOnDelete();
                $table->unsignedSmallInteger('retry_ordinal');
                $table->text('presentation_text');
                $table->char('presentation_hash', 64);
                $table->dateTime('created_at', 6);
                $table->unique(
                    ['service_entitlement_grant_item_id', 'retry_ordinal'],
                    'segnb_item_retry_uq',
                );
            });
        }

        if ($this->constraintExists('segnb_retry_chk')) {
            DB::statement(
                'ALTER TABLE service_entitlement_grant_notification_bindings DROP CONSTRAINT segnb_retry_chk',
            );
        }
        DB::statement(
            'ALTER TABLE service_entitlement_grant_notification_bindings ADD CONSTRAINT segnb_retry_chk CHECK (retry_ordinal BETWEEN 0 AND 2)',
        );

        if ($this->constraintExists('segnb_presentation_chk')) {
            DB::statement(
                'ALTER TABLE service_entitlement_grant_notification_bindings DROP CONSTRAINT segnb_presentation_chk',
            );
        }
        DB::statement(
            "ALTER TABLE service_entitlement_grant_notification_bindings ADD CONSTRAINT segnb_presentation_chk CHECK (presentation_hash REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)",
        );

        $this->installBindingGuards();
        $this->installSql('service-notification-binding-insert-guard-v2.sql');
        $this->installSql('delivery-effect-update-guard-v2.sql');
    }

    public function down(): void
    {
        if (Schema::hasTable('service_entitlement_grant_notification_bindings')
            && DB::table('service_entitlement_grant_notification_bindings')->exists()) {
            throw new RuntimeException(
                'Cannot roll back Service entitlement grant notification authority while delivery evidence exists.',
            );
        }

        $this->installSql('service-notification-binding-insert-guard-v1.sql');
        $this->installSql('delivery-effect-update-guard-v1.sql');

        DB::unprepared('DROP TRIGGER IF EXISTS service_entitlement_grant_notification_bindings_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_entitlement_grant_notification_bindings_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_entitlement_grant_notification_bindings_insert_guard');
        Schema::dropIfExists('service_entitlement_grant_notification_bindings');
    }

    private function installBindingGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_notification_bindings_insert_guard
BEFORE INSERT ON service_entitlement_grant_notification_bindings
FOR EACH ROW
BEGIN
    DECLARE valid_source_count INT DEFAULT 0;
    DECLARE previous_delivery_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1
        FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    )
       OR COALESCE(@app_service_entitlement_grant_notification_authority, '') <> 'service_entitlement_grant_notification_v1'
       OR NEW.service_entitlement_grant_item_id <> COALESCE(@app_service_entitlement_grant_notification_item_id, 0)
       OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_entitlement_grant_notification_attempt_id, 0)
       OR NEW.retry_ordinal <> COALESCE(@app_service_entitlement_grant_notification_retry_ordinal, 65535)
       OR BINARY NEW.presentation_hash <> BINARY COALESCE(@app_service_entitlement_grant_notification_presentation_hash, '')
       OR BINARY NEW.presentation_hash <> BINARY SHA2(NEW.presentation_text, 256)
       OR EXISTS (
           SELECT 1
           FROM service_notification_delivery_bindings threshold_binding
           WHERE threshold_binding.service_delivery_attempt_id = NEW.service_delivery_attempt_id
       ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Service entitlement grant notification binding authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_source_count
    FROM service_entitlement_grant_items item_row
    JOIN service_entitlement_grant_batches batch_row
      ON batch_row.id = item_row.service_entitlement_grant_batch_id
    JOIN provisioning_operations operation_row
      ON operation_row.id = item_row.provisioning_operation_id
    JOIN service_subscriptions service_row
      ON service_row.id = item_row.service_subscription_id
    JOIN service_delivery_attempts attempt_row
      ON attempt_row.id = NEW.service_delivery_attempt_id
    WHERE item_row.id = NEW.service_entitlement_grant_item_id
      AND item_row.state IN ('queued','succeeded')
      AND batch_row.notify_customers = 1
      AND operation_row.service_subscription_id = item_row.service_subscription_id
      AND operation_row.operation_type IN ('grant_data','grant_days','grant_data_days')
      AND operation_row.state = 'succeeded'
      AND operation_row.remote_effect_started_at IS NOT NULL
      AND operation_row.remote_effect_completed_at IS NOT NULL
      AND operation_row.last_result_code IS NOT NULL
      AND service_row.id = item_row.service_subscription_id
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_identity_generation = operation_row.target_remote_identity_generation
      AND service_row.lifecycle_version = operation_row.target_lifecycle_version
      AND attempt_row.service_subscription_id = item_row.service_subscription_id
      AND BINARY attempt_row.purpose = BINARY 'notification'
      AND BINARY attempt_row.request_key_hash = BINARY SHA2(
          CONCAT('grant-notification:', item_row.public_id, ':', NEW.retry_ordinal),
          256
      )
      AND BINARY attempt_row.correlation_id = BINARY CONCAT(
          'svc-grant-notify:',
          LEFT(SHA2(CONCAT(item_row.public_id, '|', NEW.retry_ordinal), 256), 40)
      )
      AND attempt_row.target_remote_identity_generation = service_row.remote_identity_generation
      AND attempt_row.target_lifecycle_version = service_row.lifecycle_version;

    IF valid_source_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Service entitlement grant notification must match one successful grant and current Service delivery authority.';
    END IF;

    IF NEW.retry_ordinal = 0 THEN
        SELECT COUNT(*) INTO previous_delivery_count
        FROM service_entitlement_grant_notification_bindings prior_binding
        WHERE prior_binding.service_entitlement_grant_item_id = NEW.service_entitlement_grant_item_id;

        IF previous_delivery_count <> 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Initial Service entitlement grant notification cannot follow prior delivery evidence.';
        END IF;
    ELSE
        SELECT COUNT(*) INTO previous_delivery_count
        FROM service_entitlement_grant_notification_bindings prior_binding
        JOIN service_delivery_effects prior_effect
          ON prior_effect.service_delivery_attempt_id = prior_binding.service_delivery_attempt_id
        WHERE prior_binding.service_entitlement_grant_item_id = NEW.service_entitlement_grant_item_id
          AND prior_binding.retry_ordinal = NEW.retry_ordinal - 1
          AND prior_effect.state = 'failed_final'
          AND prior_effect.completed_at IS NOT NULL
          AND prior_effect.retry_after_seconds IS NULL;

        IF previous_delivery_count <> 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Service entitlement grant notification retry requires one definitive prior delivery failure.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_notification_bindings_update_guard
BEFORE UPDATE ON service_entitlement_grant_notification_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service entitlement grant notification binding is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_entitlement_grant_notification_bindings_delete_guard
BEFORE DELETE ON service_entitlement_grant_notification_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Service entitlement grant notification binding is non-deletable.';
END
SQL);
    }

    private function constraintExists(string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            ['service_entitlement_grant_notification_bindings', $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function installSql(string $file): void
    {
        $sql = file_get_contents(
            database_path('sql/service-entitlement-grant-notification/'.$file),
        );
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException(
                'Service entitlement grant notification SQL asset is unavailable: '.$file,
            );
        }

        // @phpstan-ignore-next-line argument.type -- verified migration-owned SQL asset.
        DB::unprepared($sql);
    }
};
