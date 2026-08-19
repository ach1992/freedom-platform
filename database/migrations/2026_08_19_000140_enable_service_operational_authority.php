<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const BOOTSTRAP_CHECKS = [
        'service_imports' => 'service_imports_bootstrap_block_chk',
        'service_ownership_transfers' => 'service_transfers_bootstrap_block_chk',
        'service_reconciliation_cases' => 'service_reconciliation_bootstrap_block_chk',
        'service_reconciliation_changes' => 'service_reconciliation_changes_bootstrap_block_chk',
        'service_batch_grants' => 'service_batch_grants_bootstrap_block_chk',
        'service_batch_grant_items' => 'service_batch_items_bootstrap_block_chk',
    ];

    /** @var array<string, string> */
    private const READY_CHECKS = [
        'service_imports' => 'service_imports_authority_ready_v1_chk',
        'service_ownership_transfers' => 'service_transfers_authority_ready_v1_chk',
        'service_reconciliation_cases' => 'service_reconciliation_authority_ready_v1_chk',
        'service_reconciliation_changes' => 'service_reconciliation_changes_authority_ready_v1_chk',
        'service_batch_grants' => 'service_batch_grants_authority_ready_v1_chk',
        'service_batch_grant_items' => 'service_batch_items_authority_ready_v1_chk',
    ];

    /** @requirement SVC-008 SVC-009 SVC-010 SVC-011 SVC-012 PRV-003 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        if ($this->authorityFinalized()) {
            return;
        }

        foreach ([
            'service_subscriptions', 'provisioning_operations', 'order_source_authorizations', 'orders',
            'users', 'administrators', 'plan_offerings', 'panel_connections', 'panel_service_targets',
            'panel_protocol_profiles', 'panel_target_protocol_profiles', 'audit_logs',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service operational authority prerequisites are incomplete.');
            }
        }

        $this->ensureBlockedFoundation();
        $this->ensureRemoteIdentityUniqueness();
        $this->installEvidenceGuards();
        $this->installAuditGuard();
        $this->installServiceUpdateAuthority();

        foreach (self::READY_CHECKS as $table => $constraint) {
            if (! $this->constraintExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (1 = 1)");
            }
        }

        $this->assertAuthorityReadyBeforeRelease();

        // Release evidence tables only after every DB guard and the composed Service update
        // authority is ready. Application consumers additionally require every bootstrap barrier
        // to be absent, so an interruption during release remains fail-closed.
        foreach (array_reverse(self::BOOTSTRAP_CHECKS, true) as $table => $constraint) {
            if ($this->constraintExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            }
        }

        $this->assertAuthorityReady(false);
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            array_keys(self::BOOTSTRAP_CHECKS),
            static fn (string $table): bool => Schema::hasTable($table),
        ));
        if ($present === []) {
            return;
        }

        foreach ($present as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Service operational authority after durable operational evidence exists.');
            }
        }

        // Close every remaining evidence surface before restoring the predecessor Service update
        // guard or dropping any table. If a row races this close, CHECK installation fails and the
        // accepted final authority remains intact.
        foreach (self::BOOTSTRAP_CHECKS as $table => $constraint) {
            if (! Schema::hasTable($table) || $this->constraintExists($table, $constraint)) {
                continue;
            }
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (0 = 1)");
        }
        foreach ($present as $table) {
            if ($this->tableRowCount($table) > 0) {
                throw new RuntimeException('Cannot roll back Service operational authority after rollback barriers closed with durable evidence.');
            }
        }

        $this->restorePredecessorServiceUpdateAuthority();
        $this->dropEvidenceGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_operational_insert_guard');

        foreach (array_reverse(array_keys(self::BOOTSTRAP_CHECKS)) as $table) {
            Schema::dropIfExists($table);
        }
        if ($this->indexExists('service_subscriptions', 'service_subscriptions_target_remote_unique')) {
            DB::statement('ALTER TABLE service_subscriptions DROP INDEX service_subscriptions_target_remote_unique');
        }
    }

    private function ensureBlockedFoundation(): void
    {
        $definitions = $this->tableDefinitions();
        foreach ($definitions as $table => $sql) {
            $bootstrap = self::BOOTSTRAP_CHECKS[$table];
            if (! Schema::hasTable($table)) {
                DB::statement($sql);
                continue;
            }
            if ($this->constraintExists($table, $bootstrap)) {
                continue;
            }
            if ($this->constraintExists($table, self::READY_CHECKS[$table])) {
                // A process can terminate after one final bootstrap CHECK is released but before
                // the family release completes. Consumers require every table released, so this
                // state remains unusable. Keep the already-released empty table unchanged and
                // converge the remaining family rather than trying to re-block it.
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Partially released Service operational authority contains unexpected evidence.');
                }

                continue;
            }
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot normalize an unverified Service operational table containing rows.');
            }
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$bootstrap}` CHECK (0 = 1)");
        }
    }

    /** @return array<string, string> */
    private function tableDefinitions(): array
    {
        return [
            'service_imports' => <<<'SQL'
CREATE TABLE `service_imports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) NOT NULL,
  `request_key_hash` CHAR(64) NOT NULL,
  `actor_administrator_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_offering_id` BIGINT UNSIGNED NOT NULL,
  `service_target_id` BIGINT UNSIGNED NOT NULL,
  `subscription_link_hash` CHAR(64) NOT NULL,
  `registered_host` VARCHAR(253) NOT NULL,
  `remote_service_id` VARCHAR(191) NOT NULL,
  `remote_username` VARCHAR(191) NOT NULL,
  `remote_canonical_hash` CHAR(64) NOT NULL,
  `remote_status` VARCHAR(32) NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `order_source_authorization_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `service_subscription_id` BIGINT UNSIGNED NULL,
  `audit_log_id` BIGINT UNSIGNED NULL,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `attached_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_imports_public_unique` (`public_id`),
  UNIQUE KEY `service_imports_request_unique` (`request_key_hash`),
  UNIQUE KEY `service_imports_service_unique` (`service_subscription_id`),
  KEY `service_imports_user_created_idx` (`user_id`,`created_at`),
  KEY `service_imports_target_remote_idx` (`service_target_id`,`remote_service_id`),
  CONSTRAINT `service_imports_admin_fk` FOREIGN KEY (`actor_administrator_id`) REFERENCES `administrators` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_offering_fk` FOREIGN KEY (`plan_offering_id`) REFERENCES `plan_offerings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_target_fk` FOREIGN KEY (`service_target_id`) REFERENCES `panel_service_targets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_source_fk` FOREIGN KEY (`order_source_authorization_id`) REFERENCES `order_source_authorizations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_service_fk` FOREIGN KEY (`service_subscription_id`) REFERENCES `service_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_imports_state_chk` CHECK (`state` IN ('previewed','attaching','attached')),
  CONSTRAINT `service_imports_status_chk` CHECK (`remote_status` = 'active'),
  CONSTRAINT `service_imports_hash_chk` CHECK (CHAR_LENGTH(`request_key_hash`) = 64 AND CHAR_LENGTH(`subscription_link_hash`) = 64 AND CHAR_LENGTH(`remote_canonical_hash`) = 64),
  CONSTRAINT `service_imports_shape_chk` CHECK ((`state` = 'previewed' AND `order_source_authorization_id` IS NULL AND `order_id` IS NULL AND `service_subscription_id` IS NULL AND `audit_log_id` IS NULL AND `attached_at` IS NULL) OR (`state` = 'attaching' AND `order_source_authorization_id` IS NOT NULL AND `order_id` IS NOT NULL AND `service_subscription_id` IS NOT NULL AND `audit_log_id` IS NOT NULL AND `attached_at` IS NULL) OR (`state` = 'attached' AND `order_source_authorization_id` IS NOT NULL AND `order_id` IS NOT NULL AND `service_subscription_id` IS NOT NULL AND `audit_log_id` IS NOT NULL AND `attached_at` IS NOT NULL)),
  CONSTRAINT `service_imports_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'service_ownership_transfers' => <<<'SQL'
CREATE TABLE `service_ownership_transfers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) NOT NULL,
  `request_key_hash` CHAR(64) NOT NULL,
  `service_subscription_id` BIGINT UNSIGNED NOT NULL,
  `from_user_id` BIGINT UNSIGNED NOT NULL,
  `to_user_id` BIGINT UNSIGNED NOT NULL,
  `actor_administrator_id` BIGINT UNSIGNED NOT NULL,
  `target_remote_identity_generation` BIGINT UNSIGNED NOT NULL,
  `target_lifecycle_version` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `audit_log_id` BIGINT UNSIGNED NULL,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_transfers_public_unique` (`public_id`),
  UNIQUE KEY `service_transfers_request_unique` (`request_key_hash`),
  KEY `service_transfers_service_created_idx` (`service_subscription_id`,`created_at`),
  CONSTRAINT `service_transfers_service_fk` FOREIGN KEY (`service_subscription_id`) REFERENCES `service_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_from_user_fk` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_to_user_fk` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_admin_fk` FOREIGN KEY (`actor_administrator_id`) REFERENCES `administrators` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_transfers_state_chk` CHECK (`state` IN ('pending','applying','completed')),
  CONSTRAINT `service_transfers_identity_chk` CHECK (`from_user_id` <> `to_user_id` AND `target_remote_identity_generation` >= 1),
  CONSTRAINT `service_transfers_shape_chk` CHECK ((`state` = 'pending' AND `audit_log_id` IS NULL AND `completed_at` IS NULL) OR (`state` = 'applying' AND `audit_log_id` IS NOT NULL AND `completed_at` IS NULL) OR (`state` = 'completed' AND `audit_log_id` IS NOT NULL AND `completed_at` IS NOT NULL)),
  CONSTRAINT `service_transfers_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'service_reconciliation_cases' => <<<'SQL'
CREATE TABLE `service_reconciliation_cases` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) NOT NULL,
  `request_key_hash` CHAR(64) NOT NULL,
  `service_subscription_id` BIGINT UNSIGNED NOT NULL,
  `actor_administrator_id` BIGINT UNSIGNED NOT NULL,
  `service_target_id` BIGINT UNSIGNED NOT NULL,
  `before_remote_service_id` VARCHAR(191) NOT NULL,
  `proposed_remote_service_id` VARCHAR(191) NULL,
  `remote_disposition` VARCHAR(16) NOT NULL,
  `remote_canonical_hash` CHAR(64) NULL,
  `target_remote_identity_generation` BIGINT UNSIGNED NOT NULL,
  `target_lifecycle_version` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `audit_log_id` BIGINT UNSIGNED NULL,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `applied_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_reconciliation_public_unique` (`public_id`),
  UNIQUE KEY `service_reconciliation_request_unique` (`request_key_hash`),
  KEY `service_reconciliation_service_created_idx` (`service_subscription_id`,`created_at`),
  CONSTRAINT `service_reconciliation_service_fk` FOREIGN KEY (`service_subscription_id`) REFERENCES `service_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_admin_fk` FOREIGN KEY (`actor_administrator_id`) REFERENCES `administrators` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_target_fk` FOREIGN KEY (`service_target_id`) REFERENCES `panel_service_targets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_disposition_chk` CHECK (`remote_disposition` IN ('present','absent','uncertain')),
  CONSTRAINT `service_reconciliation_state_chk` CHECK (`state` IN ('previewed','applying','applied','closed')),
  CONSTRAINT `service_reconciliation_remote_chk` CHECK ((`remote_disposition` = 'present' AND `proposed_remote_service_id` IS NOT NULL AND `remote_canonical_hash` IS NOT NULL AND CHAR_LENGTH(`remote_canonical_hash`) = 64) OR (`remote_disposition` IN ('absent','uncertain') AND `remote_canonical_hash` IS NULL)),
  CONSTRAINT `service_reconciliation_shape_chk` CHECK ((`state` IN ('previewed','closed') AND `audit_log_id` IS NULL AND `applied_at` IS NULL) OR (`state` = 'applying' AND `audit_log_id` IS NOT NULL AND `applied_at` IS NULL) OR (`state` = 'applied' AND `audit_log_id` IS NOT NULL AND `applied_at` IS NOT NULL)),
  CONSTRAINT `service_reconciliation_generation_chk` CHECK (`target_remote_identity_generation` >= 1),
  CONSTRAINT `service_reconciliation_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'service_reconciliation_changes' => <<<'SQL'
CREATE TABLE `service_reconciliation_changes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_reconciliation_case_id` BIGINT UNSIGNED NOT NULL,
  `service_subscription_id` BIGINT UNSIGNED NOT NULL,
  `field_name` VARCHAR(64) NOT NULL,
  `before_value` VARCHAR(512) NULL,
  `after_value` VARCHAR(512) NULL,
  `before_remote_identity_generation` BIGINT UNSIGNED NOT NULL,
  `after_remote_identity_generation` BIGINT UNSIGNED NOT NULL,
  `before_lifecycle_version` BIGINT UNSIGNED NOT NULL,
  `after_lifecycle_version` BIGINT UNSIGNED NOT NULL,
  `audit_log_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_reconciliation_change_case_unique` (`service_reconciliation_case_id`),
  CONSTRAINT `service_reconciliation_changes_case_fk` FOREIGN KEY (`service_reconciliation_case_id`) REFERENCES `service_reconciliation_cases` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_changes_service_fk` FOREIGN KEY (`service_subscription_id`) REFERENCES `service_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_changes_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_reconciliation_changes_field_chk` CHECK (`field_name` = 'remote_service_id'),
  CONSTRAINT `service_reconciliation_changes_generation_chk` CHECK (`after_remote_identity_generation` = `before_remote_identity_generation` + 1 AND `after_lifecycle_version` = `before_lifecycle_version` + 1),
  CONSTRAINT `service_reconciliation_changes_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'service_batch_grants' => <<<'SQL'
CREATE TABLE `service_batch_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) NOT NULL,
  `request_key_hash` CHAR(64) NOT NULL,
  `actor_administrator_id` BIGINT UNSIGNED NOT NULL,
  `audit_log_id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `item_count` INT UNSIGNED NOT NULL,
  `succeeded_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_batch_grants_public_unique` (`public_id`),
  UNIQUE KEY `service_batch_grants_request_unique` (`request_key_hash`),
  KEY `service_batch_grants_state_created_idx` (`state`,`created_at`),
  CONSTRAINT `service_batch_grants_admin_fk` FOREIGN KEY (`actor_administrator_id`) REFERENCES `administrators` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_grants_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_grants_state_chk` CHECK (`state` IN ('active','paused','cancelled','completed')),
  CONSTRAINT `service_batch_grants_count_chk` CHECK (`item_count` BETWEEN 1 AND 50 AND `succeeded_count` + `failed_count` <= `item_count`),
  CONSTRAINT `service_batch_grants_completion_chk` CHECK ((`state` = 'completed' AND `succeeded_count` = `item_count` AND `failed_count` = 0 AND `completed_at` IS NOT NULL) OR (`state` <> 'completed' AND `completed_at` IS NULL)),
  CONSTRAINT `service_batch_grants_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'service_batch_grant_items' => <<<'SQL'
CREATE TABLE `service_batch_grant_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` CHAR(26) NOT NULL,
  `service_batch_grant_id` BIGINT UNSIGNED NOT NULL,
  `position` INT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `plan_offering_id` BIGINT UNSIGNED NOT NULL,
  `request_key_hash` CHAR(64) NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `claim_token` CHAR(26) NULL,
  `claim_expires_at` DATETIME(6) NULL,
  `order_source_authorization_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `service_subscription_id` BIGINT UNSIGNED NULL,
  `provisioning_operation_id` BIGINT UNSIGNED NULL,
  `error_code` VARCHAR(64) NULL,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_batch_items_public_unique` (`public_id`),
  UNIQUE KEY `service_batch_items_request_unique` (`request_key_hash`),
  UNIQUE KEY `service_batch_items_position_unique` (`service_batch_grant_id`,`position`),
  KEY `service_batch_items_state_idx` (`service_batch_grant_id`,`state`,`position`),
  CONSTRAINT `service_batch_items_batch_fk` FOREIGN KEY (`service_batch_grant_id`) REFERENCES `service_batch_grants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_offering_fk` FOREIGN KEY (`plan_offering_id`) REFERENCES `plan_offerings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_source_fk` FOREIGN KEY (`order_source_authorization_id`) REFERENCES `order_source_authorizations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_service_fk` FOREIGN KEY (`service_subscription_id`) REFERENCES `service_subscriptions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_operation_fk` FOREIGN KEY (`provisioning_operation_id`) REFERENCES `provisioning_operations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_items_state_chk` CHECK (`state` IN ('pending','processing','succeeded','failed','cancelled')),
  CONSTRAINT `service_batch_items_attempt_chk` CHECK (`attempt_count` >= 0),
  CONSTRAINT `service_batch_items_claim_chk` CHECK ((`state` = 'processing' AND `claim_token` IS NOT NULL AND `claim_expires_at` IS NOT NULL) OR (`state` <> 'processing' AND `claim_token` IS NULL AND `claim_expires_at` IS NULL)),
  CONSTRAINT `service_batch_items_result_chk` CHECK ((`state` = 'succeeded' AND `order_source_authorization_id` IS NOT NULL AND `order_id` IS NOT NULL AND `service_subscription_id` IS NOT NULL AND `provisioning_operation_id` IS NOT NULL AND `error_code` IS NULL) OR (`state` = 'failed' AND `error_code` IS NOT NULL) OR (`state` IN ('pending','processing','cancelled') AND `error_code` IS NULL)),
  CONSTRAINT `service_batch_items_bootstrap_block_chk` CHECK (0 = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    private function ensureRemoteIdentityUniqueness(): void
    {
        if ($this->indexExists('service_subscriptions', 'service_subscriptions_target_remote_unique')) {
            $rows = DB::select(<<<'SQL'
SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'service_subscriptions'
  AND INDEX_NAME = 'service_subscriptions_target_remote_unique'
ORDER BY SEQ_IN_INDEX
SQL);
            if (count($rows) !== 2
                || (string) $rows[0]->column_name !== 'service_target_id'
                || (string) $rows[1]->column_name !== 'remote_service_id'
                || (int) $rows[0]->non_unique !== 0
                || $rows[0]->sub_part !== null
                || $rows[1]->sub_part !== null) {
                throw new RuntimeException('Stored Service remote identity uniqueness index is incompatible.');
            }

            return;
        }

        DB::statement('ALTER TABLE service_subscriptions ADD UNIQUE INDEX service_subscriptions_target_remote_unique (service_target_id, remote_service_id)');
    }

    private function installEvidenceGuards(): void
    {
        foreach ($this->operationalEvidenceGuardStatements() as $statement) {
            DB::unprepared($statement);
        }
        foreach ($this->batchEvidenceGuardStatements() as $statement) {
            DB::unprepared($statement);
        }
    }

    private function dropEvidenceGuards(): void
    {
        foreach ([
            'DROP TRIGGER IF EXISTS service_imports_delete_guard',
            'DROP TRIGGER IF EXISTS service_imports_update_guard',
            'DROP TRIGGER IF EXISTS service_imports_insert_guard',
            'DROP TRIGGER IF EXISTS service_ownership_transfers_delete_guard',
            'DROP TRIGGER IF EXISTS service_ownership_transfers_update_guard',
            'DROP TRIGGER IF EXISTS service_ownership_transfers_insert_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_cases_delete_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_cases_update_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_cases_insert_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_changes_delete_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_changes_update_guard',
            'DROP TRIGGER IF EXISTS service_reconciliation_changes_insert_guard',
            'DROP TRIGGER IF EXISTS service_batch_grants_delete_guard',
            'DROP TRIGGER IF EXISTS service_batch_grants_update_guard',
            'DROP TRIGGER IF EXISTS service_batch_grants_insert_guard',
            'DROP TRIGGER IF EXISTS service_batch_grant_items_delete_guard',
            'DROP TRIGGER IF EXISTS service_batch_grant_items_update_guard',
            'DROP TRIGGER IF EXISTS service_batch_grant_items_insert_guard',
        ] as $statement) {
            DB::unprepared($statement);
        }
    }

    /** @return list<string> */
    private function operationalEvidenceGuardStatements(): array
    {
        return [
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_insert_guard BEFORE INSERT ON service_imports FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence insert authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_update_guard BEFORE UPDATE ON service_imports FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence update authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_delete_guard BEFORE DELETE ON service_imports FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_insert_guard BEFORE INSERT ON service_ownership_transfers FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence insert authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_update_guard BEFORE UPDATE ON service_ownership_transfers FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence update authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_delete_guard BEFORE DELETE ON service_ownership_transfers FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_insert_guard BEFORE INSERT ON service_reconciliation_cases FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence insert authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_update_guard BEFORE UPDATE ON service_reconciliation_cases FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence update authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_delete_guard BEFORE DELETE ON service_reconciliation_cases FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_insert_guard BEFORE INSERT ON service_reconciliation_changes FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence insert authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_update_guard BEFORE UPDATE ON service_reconciliation_changes FOR EACH ROW BEGIN IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence update authority is invalid.'; END IF; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_delete_guard BEFORE DELETE ON service_reconciliation_changes FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
        ];
    }

    /** @return list<string> */
    private function batchEvidenceGuardStatements(): array
    {
        return [
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grants_insert_guard
BEFORE INSERT ON service_batch_grants
FOR EACH ROW
BEGIN
    DECLARE valid_audit INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_audit
    FROM audit_logs audit_row
    WHERE audit_row.id = NEW.audit_log_id
      AND audit_row.action = 'service.operational.batch.created'
      AND audit_row.actor_type = 'administrator'
      AND audit_row.actor_id = CAST(NEW.actor_administrator_id AS CHAR)
      AND audit_row.target_type = 'service_batch_grant'
      AND BINARY audit_row.target_id = BINARY NEW.public_id
      AND BINARY audit_row.request_fingerprint = BINARY NEW.request_key_hash
      AND BINARY audit_row.correlation_id = BINARY NEW.correlation_id;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR valid_audit <> 1
       OR NEW.state <> 'active'
       OR NEW.succeeded_count <> 0
       OR NEW.failed_count <> 0
       OR NEW.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant insert authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grants_update_guard
BEFORE UPDATE ON service_batch_grants
FOR EACH ROW
BEGIN
    DECLARE succeeded_items INT DEFAULT 0;
    DECLARE failed_items INT DEFAULT 0;

    SELECT
        SUM(item_row.state = 'succeeded'),
        SUM(item_row.state = 'failed')
      INTO succeeded_items, failed_items
    FROM service_batch_grant_items item_row
    WHERE item_row.service_batch_grant_id = OLD.id;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.audit_log_id <> OLD.audit_log_id
       OR NEW.item_count <> OLD.item_count
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR NEW.succeeded_count <> COALESCE(succeeded_items, 0)
       OR NEW.failed_count <> COALESCE(failed_items, 0)
       OR NOT (
            (NEW.state = OLD.state AND NEW.state IN ('active','paused','cancelled'))
            OR (OLD.state = 'active' AND NEW.state = 'paused')
            OR (OLD.state = 'paused' AND NEW.state = 'active')
            OR (OLD.state IN ('active','paused') AND NEW.state = 'cancelled')
            OR (OLD.state = 'active' AND NEW.state = 'completed'
                AND NEW.succeeded_count = NEW.item_count AND NEW.failed_count = 0)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant update authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grants_delete_guard BEFORE DELETE ON service_batch_grants FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grant_items_insert_guard
BEFORE INSERT ON service_batch_grant_items
FOR EACH ROW
BEGIN
    DECLARE valid_parent INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_parent
    FROM service_batch_grants batch_row
    WHERE batch_row.id = NEW.service_batch_grant_id
      AND batch_row.state = 'active'
      AND batch_row.actor_administrator_id > 0;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR valid_parent <> 1
       OR NEW.state <> 'pending'
       OR NEW.attempt_count <> 0
       OR NEW.claim_token IS NOT NULL
       OR NEW.claim_expires_at IS NOT NULL
       OR NEW.order_source_authorization_id IS NOT NULL
       OR NEW.order_id IS NOT NULL
       OR NEW.service_subscription_id IS NOT NULL
       OR NEW.provisioning_operation_id IS NOT NULL
       OR NEW.error_code IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant item insert authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grant_items_update_guard
BEFORE UPDATE ON service_batch_grant_items
FOR EACH ROW
BEGIN
    DECLARE valid_result INT DEFAULT 0;

    IF NEW.state = 'succeeded' THEN
        SELECT COUNT(*) INTO valid_result
        FROM service_batch_grants batch_row
        INNER JOIN order_source_authorizations source_row
            ON source_row.id = NEW.order_source_authorization_id
        INNER JOIN orders order_row
            ON order_row.id = NEW.order_id
           AND order_row.order_source_authorization_id = source_row.id
        INNER JOIN order_items item_row
            ON item_row.order_id = order_row.id
           AND item_row.line_number = 1
           AND item_row.plan_offering_id = NEW.plan_offering_id
        INNER JOIN service_subscriptions service_row
            ON service_row.id = NEW.service_subscription_id
           AND service_row.order_id = order_row.id
           AND service_row.order_item_id = item_row.id
           AND service_row.user_id = NEW.user_id
        INNER JOIN provisioning_operations operation_row
            ON operation_row.id = NEW.provisioning_operation_id
           AND operation_row.operation_type = 'initial_provision'
           AND operation_row.order_id = order_row.id
           AND operation_row.order_item_id = item_row.id
           AND operation_row.service_subscription_id = service_row.id
           AND operation_row.user_id = NEW.user_id
        WHERE batch_row.id = NEW.service_batch_grant_id
          AND source_row.source_type = 'admin_grant'
          AND source_row.user_id = NEW.user_id
          AND source_row.plan_offering_id = NEW.plan_offering_id
          AND source_row.actor_type = 'administrator'
          AND source_row.actor_id = batch_row.actor_administrator_id
          AND order_row.source_type = 'admin_grant'
          AND order_row.user_id = NEW.user_id
          AND order_row.total_amount_irr = 0
          AND order_row.purchase_settlement_id IS NULL
          AND order_row.payment_intent_id IS NULL
          AND order_row.state = 'provisioning_queued'
          AND operation_row.state = 'queued';
    END IF;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR NEW.service_batch_grant_id <> OLD.service_batch_grant_id
       OR NEW.position <> OLD.position
       OR NEW.user_id <> OLD.user_id
       OR NEW.plan_offering_id <> OLD.plan_offering_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR NOT (
            (OLD.state IN ('pending','failed','processing') AND NEW.state = 'processing'
                AND NEW.attempt_count = OLD.attempt_count + 1
                AND NEW.claim_token IS NOT NULL AND NEW.claim_expires_at IS NOT NULL
                AND NEW.order_source_authorization_id <=> OLD.order_source_authorization_id
                AND NEW.order_id <=> OLD.order_id
                AND NEW.service_subscription_id <=> OLD.service_subscription_id
                AND NEW.provisioning_operation_id <=> OLD.provisioning_operation_id
                AND NEW.error_code IS NULL)
            OR (OLD.state = 'processing' AND NEW.state = 'succeeded'
                AND NEW.attempt_count = OLD.attempt_count
                AND NEW.claim_token IS NULL AND NEW.claim_expires_at IS NULL
                AND valid_result = 1)
            OR (OLD.state = 'processing' AND NEW.state = 'failed'
                AND NEW.attempt_count = OLD.attempt_count
                AND NEW.claim_token IS NULL AND NEW.claim_expires_at IS NULL
                AND NEW.error_code IN ('domain_rejected','processing_failed'))
            OR (OLD.state IN ('pending','failed','processing') AND NEW.state = 'cancelled'
                AND NEW.attempt_count = OLD.attempt_count
                AND NEW.claim_token IS NULL AND NEW.claim_expires_at IS NULL
                AND NEW.error_code IS NULL)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant item update authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_batch_grant_items_delete_guard BEFORE DELETE ON service_batch_grant_items FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service batch grant evidence is non-deletable.'; END
SQL,
        ];
    }

    private function installAuditGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER audit_logs_service_operational_insert_guard
BEFORE INSERT ON audit_logs
FOR EACH ROW
BEGIN
    IF NEW.action LIKE 'service.operational.%' THEN
        IF COALESCE(@app_service_operational_audit_authority, '') <> 'service_operational_audit_v1'
           OR NEW.actor_type <> 'administrator'
           OR NEW.actor_id IS NULL
           OR NEW.actor_id NOT REGEXP '^[1-9][0-9]*$'
           OR NEW.target_type IS NULL
           OR NEW.target_id IS NULL
           OR NEW.request_fingerprint IS NULL
           OR CHAR_LENGTH(NEW.request_fingerprint) <> 64
           OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_service_operational_request_hash, '')
           OR NEW.reason_code IS NULL
           OR NEW.reason IS NULL
           OR NEW.before_safe_data IS NULL
           OR NEW.after_safe_data IS NULL
           OR JSON_VALID(NEW.before_safe_data) <> 1
           OR JSON_VALID(NEW.after_safe_data) <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit authority is invalid.';
        END IF;
    END IF;
END
SQL);
    }

    private function installServiceUpdateAuthority(): void
    {
        $path = database_path('sql/service-operational-authority/service-update-guard.sql');
        $sql = file_get_contents($path);
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service operational update authority SQL asset is unavailable.');
        }
        DB::connection()->getPdo()->exec($sql);
    }

    private function restorePredecessorServiceUpdateAuthority(): void
    {
        $path = database_path('sql/service-mutation-authority/service-update-guard.sql');
        $sql = file_get_contents($path);
        if (! is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Service mutation predecessor update authority SQL asset is unavailable.');
        }
        DB::connection()->getPdo()->exec($sql);
    }

    private function authorityFinalized(): bool
    {
        foreach (self::READY_CHECKS as $table => $ready) {
            if (! Schema::hasTable($table)
                || ! $this->constraintExists($table, $ready)
                || $this->constraintExists($table, self::BOOTSTRAP_CHECKS[$table])) {
                return false;
            }
        }
        if (! $this->indexExists('service_subscriptions', 'service_subscriptions_target_remote_unique')) {
            return false;
        }

        if (! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerExists('audit_logs_service_operational_insert_guard')) {
            return false;
        }
        foreach (array_keys(self::BOOTSTRAP_CHECKS) as $table) {
            foreach (['insert', 'update', 'delete'] as $event) {
                if (! $this->triggerExists("{$table}_{$event}_guard")) {
                    return false;
                }
            }
        }

        return true;
    }

    private function assertAuthorityReadyBeforeRelease(): void
    {
        foreach (self::READY_CHECKS as $table => $ready) {
            if (! $this->constraintExists($table, $ready)) {
                throw new RuntimeException('Service operational authority ready marker is missing: '.$table);
            }
            foreach (['insert', 'update', 'delete'] as $event) {
                if (! $this->triggerExists("{$table}_{$event}_guard")) {
                    throw new RuntimeException('Service operational evidence guard is missing: '.$table.'.'.$event);
                }
            }
        }
        if (! $this->indexExists('service_subscriptions', 'service_subscriptions_target_remote_unique')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerExists('audit_logs_service_operational_insert_guard')) {
            throw new RuntimeException('Service operational authority is incomplete before release.');
        }
    }

    private function assertAuthorityReady(bool $blocked): void
    {
        foreach (self::READY_CHECKS as $table => $ready) {
            if (! $this->constraintExists($table, $ready)) {
                throw new RuntimeException('Service operational authority ready marker is missing: '.$table);
            }
            $hasBootstrap = $this->constraintExists($table, self::BOOTSTRAP_CHECKS[$table]);
            if ($hasBootstrap !== $blocked) {
                throw new RuntimeException('Service operational bootstrap state is inconsistent: '.$table);
            }
            foreach (['insert', 'update', 'delete'] as $event) {
                if (! $this->triggerExists("{$table}_{$event}_guard")) {
                    throw new RuntimeException('Service operational evidence guard is missing: '.$table.'.'.$event);
                }
            }
        }
        if (! $this->indexExists('service_subscriptions', 'service_subscriptions_target_remote_unique')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerExists('audit_logs_service_operational_insert_guard')) {
            throw new RuntimeException('Service operational authority is incomplete.');
        }
    }

    private function tableRowCount(string $table): int
    {
        $row = match ($table) {
            'service_imports' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_imports'),
            'service_ownership_transfers' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_ownership_transfers'),
            'service_reconciliation_cases' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_reconciliation_cases'),
            'service_reconciliation_changes' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_reconciliation_changes'),
            'service_batch_grants' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_batch_grants'),
            'service_batch_grant_items' => DB::selectOne('SELECT COUNT(*) AS aggregate FROM service_batch_grant_items'),
            default => throw new RuntimeException('Unknown Service operational evidence table.'),
        };

        return $row === null ? 0 : (int) $row->aggregate;
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
            'SELECT ACTION_STATEMENT AS action_statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
            [$trigger],
        );

        return $row !== null && is_string($row->action_statement) && str_contains($row->action_statement, $needle);
    }
};
