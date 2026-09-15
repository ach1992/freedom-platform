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
            'panel_protocol_profiles', 'panel_target_protocol_profiles', 'audit_logs', 'order_items',
            'permissions', 'administrator_permission_overrides', 'administrator_role_assignments', 'roles', 'role_permissions',
            'service_delivery_attempts', 'service_delivery_effects',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service operational authority prerequisites are incomplete.');
            }
        }

        $this->ensureOperationalCapability();
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
            $this->restorePredecessorServiceUpdateAuthority();
            DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_service_operational_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_insert_guard');
            Schema::dropIfExists('service_operational_authority_capability');

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
        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_insert_guard');
        Schema::dropIfExists('service_operational_authority_capability');
    }

    private function ensureOperationalCapability(): void
    {
        $expectedHash = $this->operationalCapabilityHash();
        if (! Schema::hasTable('service_operational_authority_capability')) {
            DB::statement(<<<'SQL'
CREATE TABLE service_operational_authority_capability (
  `id` TINYINT UNSIGNED NOT NULL,
  `capability_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `service_operational_capability_singleton_chk` CHECK (`id` = 1),
  CONSTRAINT `service_operational_capability_hash_chk` CHECK (CHAR_LENGTH(`capability_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
            DB::table('service_operational_authority_capability')->insert([
                'id' => 1,
                'capability_hash' => $expectedHash,
                'created_at' => now('UTC'),
            ]);
        } else {
            $rows = DB::table('service_operational_authority_capability')->get(['id', 'capability_hash']);
            if ($rows->count() === 0) {
                // TRUNCATE does not fire row triggers. An empty capability table is fail-closed,
                // so re-entry may safely reconstruct its one immutable row before releasing any
                // operational evidence surface.
                DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_delete_guard');
                DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_update_guard');
                DB::unprepared('DROP TRIGGER IF EXISTS service_operational_capability_insert_guard');
                DB::table('service_operational_authority_capability')->insert([
                    'id' => 1,
                    'capability_hash' => $expectedHash,
                    'created_at' => now('UTC'),
                ]);
            } elseif ($rows->count() !== 1
                || (int) $rows->first()->id !== 1
                || ! is_string($rows->first()->capability_hash)
                || ! hash_equals($expectedHash, $rows->first()->capability_hash)) {
                throw new RuntimeException('Service operational database capability does not match the application key.');
            }
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_operational_capability_insert_guard BEFORE INSERT ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_operational_capability_update_guard BEFORE UPDATE ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_operational_capability_delete_guard BEFORE DELETE ON service_operational_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational database capability is immutable.'; END
SQL);
    }

    private function operationalCapabilityHash(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Service operational database capability key is unavailable.');
        }

        return hash('sha256', hash_hmac('sha256', 'service-operational-database-authority-v1', $key));
    }

    private function operationalCapabilityReady(): bool
    {
        if (! Schema::hasTable('service_operational_authority_capability')) {
            return false;
        }
        $row = DB::table('service_operational_authority_capability')->where('id', 1)->first(['capability_hash']);
        if ($row === null || ! is_string($row->capability_hash) || ! hash_equals($this->operationalCapabilityHash(), $row->capability_hash)) {
            return false;
        }
        foreach ([
            'service_operational_capability_insert_guard',
            'service_operational_capability_update_guard',
            'service_operational_capability_delete_guard',
        ] as $trigger) {
            if (! $this->triggerExists($trigger)) {
                return false;
            }
        }

        return true;
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
  `payload_hash` CHAR(64) NOT NULL,
  `reason_code` VARCHAR(64) NOT NULL,
  `actor_administrator_id` BIGINT UNSIGNED NOT NULL,
  `audit_log_id` BIGINT UNSIGNED NOT NULL,
  `state` VARCHAR(16) NOT NULL,
  `item_count` INT UNSIGNED NOT NULL,
  `succeeded_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `correlation_id` VARCHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  `updated_at` DATETIME(6) NOT NULL,
  `items_committed_at` DATETIME(6) NULL,
  `completed_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_batch_grants_public_unique` (`public_id`),
  UNIQUE KEY `service_batch_grants_request_unique` (`request_key_hash`),
  KEY `service_batch_grants_state_created_idx` (`state`,`created_at`),
  CONSTRAINT `service_batch_grants_admin_fk` FOREIGN KEY (`actor_administrator_id`) REFERENCES `administrators` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_grants_audit_fk` FOREIGN KEY (`audit_log_id`) REFERENCES `audit_logs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_batch_grants_state_chk` CHECK (`state` IN ('active','paused','cancelled','completed')),
  CONSTRAINT `service_batch_grants_count_chk` CHECK (`item_count` BETWEEN 1 AND 50 AND `succeeded_count` + `failed_count` <= `item_count`),
  CONSTRAINT `service_batch_grants_identity_chk` CHECK (CHAR_LENGTH(`request_key_hash`) = 64 AND CHAR_LENGTH(`payload_hash`) = 64 AND `reason_code` REGEXP '^[a-z0-9_.-]{1,64}$'),
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
            if (! $this->remoteIdentityIndexCompatible()) {
                throw new RuntimeException('Stored Service remote identity uniqueness index is incompatible.');
            }

            return;
        }

        DB::statement('ALTER TABLE service_subscriptions ADD UNIQUE INDEX service_subscriptions_target_remote_unique (service_target_id, remote_service_id)');
    }

    private function remoteIdentityIndexCompatible(): bool
    {
        /** @var list<object{column_name:string,non_unique:int|string,sub_part:int|string|null}> $rows */
        $rows = DB::select(<<<'SQL'
SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique, SUB_PART AS sub_part
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'service_subscriptions'
  AND INDEX_NAME = 'service_subscriptions_target_remote_unique'
ORDER BY SEQ_IN_INDEX
SQL);

        return count($rows) === 2
            && $rows[0]->column_name === 'service_target_id'
            && $rows[1]->column_name === 'remote_service_id'
            && (int) $rows[0]->non_unique === 0
            && (int) $rows[1]->non_unique === 0
            && $rows[0]->sub_part === null
            && $rows[1]->sub_part === null;
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

    /** @return list<literal-string> */
    private function operationalEvidenceGuardStatements(): array
    {
        return [
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_insert_guard
BEFORE INSERT ON service_imports
FOR EACH ROW
BEGIN
    DECLARE valid_context INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_context
    FROM administrators administrator_row
    INNER JOIN users user_row ON user_row.id = NEW.user_id
    INNER JOIN plan_offerings offering_row ON offering_row.id = NEW.plan_offering_id
    INNER JOIN panel_service_targets target_row ON target_row.id = NEW.service_target_id
    INNER JOIN panel_connections connection_row ON connection_row.id = target_row.panel_connection_id
    WHERE administrator_row.id = NEW.actor_administrator_id
      AND administrator_row.status = 'active'
      AND user_row.account_status = 'active'
      AND user_row.account_type IN ('customer','agent')
      AND offering_row.state = 'active'
      AND offering_row.panel_service_target_id = NEW.service_target_id
      AND target_row.state = 'active'
      AND connection_row.state = 'active'
      AND EXISTS (
          SELECT 1
          FROM panel_target_protocol_profiles assignment_row
          INNER JOIN panel_protocol_profiles profile_row ON profile_row.id = assignment_row.panel_protocol_profile_id
          WHERE assignment_row.panel_service_target_id = NEW.service_target_id
            AND profile_row.state = 'active'
            AND (
                BINARY LOWER(TRIM(TRAILING '.' FROM profile_row.host)) = BINARY NEW.registered_host
                OR BINARY LOWER(TRIM(TRAILING '.' FROM profile_row.sni)) = BINARY NEW.registered_host
            )
      );

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_context <> 1
       OR NEW.state <> 'previewed'
       OR NEW.order_source_authorization_id IS NOT NULL
       OR NEW.order_id IS NOT NULL
       OR NEW.service_subscription_id IS NOT NULL
       OR NEW.audit_log_id IS NOT NULL
       OR NEW.attached_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service import evidence insert authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_update_guard
BEFORE UPDATE ON service_imports
FOR EACH ROW
BEGIN
    DECLARE valid_transition INT DEFAULT 0;

    IF OLD.state = 'previewed' AND NEW.state = 'attaching' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM order_source_authorizations source_row
        INNER JOIN orders order_row
            ON order_row.id = NEW.order_id
           AND order_row.order_source_authorization_id = source_row.id
        INNER JOIN order_items item_row
            ON item_row.order_id = order_row.id
           AND item_row.line_number = 1
           AND item_row.plan_offering_id = OLD.plan_offering_id
        INNER JOIN service_subscriptions service_row
            ON service_row.id = NEW.service_subscription_id
           AND service_row.order_id = order_row.id
           AND service_row.order_item_id = item_row.id
           AND service_row.user_id = OLD.user_id
        INNER JOIN audit_logs audit_row ON audit_row.id = NEW.audit_log_id
        WHERE source_row.id = NEW.order_source_authorization_id
          AND source_row.source_type = 'admin_grant'
          AND source_row.user_id = OLD.user_id
          AND source_row.plan_offering_id = OLD.plan_offering_id
          AND source_row.actor_type = 'administrator'
          AND source_row.actor_id = OLD.actor_administrator_id
          AND BINARY source_row.authorization_key = BINARY CONCAT('service-import:', OLD.public_id)
          AND BINARY source_row.correlation_id = BINARY OLD.correlation_id
          AND order_row.source_type = 'admin_grant'
          AND order_row.user_id = OLD.user_id
          AND order_row.total_amount_irr = 0
          AND order_row.purchase_settlement_id IS NULL
          AND order_row.payment_intent_id IS NULL
          AND service_row.service_target_id IS NULL
          AND service_row.remote_service_id IS NULL
          AND audit_row.action = 'service.operational.import.attached'
          AND audit_row.actor_type = 'administrator'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND audit_row.target_type = 'service_subscription'
          AND BINARY audit_row.target_id = BINARY service_row.public_id
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id
          AND BINARY audit_row.reason_code = BINARY source_row.reason_code
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.user_id')) AS UNSIGNED) = OLD.user_id
          AND JSON_TYPE(JSON_EXTRACT(audit_row.before_safe_data, '$.service_target_id')) = 'NULL'
          AND JSON_TYPE(JSON_EXTRACT(audit_row.before_safe_data, '$.remote_service_id')) = 'NULL'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.user_id')) AS UNSIGNED) = OLD.user_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.service_target_id')) AS UNSIGNED) = OLD.service_target_id
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(OLD.remote_service_id, 256)
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_canonical_hash')) = BINARY OLD.remote_canonical_hash;
    ELSEIF OLD.state = 'attaching' AND NEW.state = 'attached' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM service_subscriptions service_row
        INNER JOIN audit_logs audit_row ON audit_row.id = OLD.audit_log_id
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.user_id = OLD.user_id
          AND service_row.service_target_id = OLD.service_target_id
          AND BINARY service_row.remote_service_id = BINARY OLD.remote_service_id
          AND audit_row.action = 'service.operational.import.attached'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id;
    END IF;

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.user_id <> OLD.user_id
       OR NEW.plan_offering_id <> OLD.plan_offering_id
       OR NEW.service_target_id <> OLD.service_target_id
       OR BINARY NEW.subscription_link_hash <> BINARY OLD.subscription_link_hash
       OR BINARY NEW.registered_host <> BINARY OLD.registered_host
       OR BINARY NEW.remote_service_id <> BINARY OLD.remote_service_id
       OR BINARY NEW.remote_username <> BINARY OLD.remote_username
       OR BINARY NEW.remote_canonical_hash <> BINARY OLD.remote_canonical_hash
       OR BINARY NEW.remote_status <> BINARY OLD.remote_status
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR valid_transition <> 1
       OR NOT (
            (OLD.state = 'previewed' AND NEW.state = 'attaching'
                AND OLD.order_source_authorization_id IS NULL AND NEW.order_source_authorization_id IS NOT NULL
                AND OLD.order_id IS NULL AND NEW.order_id IS NOT NULL
                AND OLD.service_subscription_id IS NULL AND NEW.service_subscription_id IS NOT NULL
                AND OLD.audit_log_id IS NULL AND NEW.audit_log_id IS NOT NULL
                AND OLD.attached_at IS NULL AND NEW.attached_at IS NULL)
            OR
            (OLD.state = 'attaching' AND NEW.state = 'attached'
                AND NEW.order_source_authorization_id = OLD.order_source_authorization_id
                AND NEW.order_id = OLD.order_id
                AND NEW.service_subscription_id = OLD.service_subscription_id
                AND NEW.audit_log_id = OLD.audit_log_id
                AND OLD.attached_at IS NULL AND NEW.attached_at IS NOT NULL)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service import evidence update authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_imports_delete_guard BEFORE DELETE ON service_imports FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_insert_guard
BEFORE INSERT ON service_ownership_transfers
FOR EACH ROW
BEGIN
    DECLARE valid_context INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_context
    FROM service_subscriptions service_row
    INNER JOIN administrators administrator_row ON administrator_row.id = NEW.actor_administrator_id
    INNER JOIN users target_user ON target_user.id = NEW.to_user_id
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.user_id = NEW.from_user_id
      AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
      AND service_row.lifecycle_version = NEW.target_lifecycle_version
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.service_target_id IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND service_row.provisioned_at IS NOT NULL
      AND administrator_row.status = 'active'
      AND target_user.account_status = 'active'
      AND target_user.account_type IN ('customer','agent');

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_context <> 1
       OR NEW.state <> 'pending'
       OR NEW.audit_log_id IS NOT NULL
       OR NEW.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service ownership transfer evidence insert authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_update_guard
BEFORE UPDATE ON service_ownership_transfers
FOR EACH ROW
BEGIN
    DECLARE valid_transition INT DEFAULT 0;

    IF OLD.state = 'pending' AND NEW.state = 'applying' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM service_subscriptions service_row
        INNER JOIN audit_logs audit_row ON audit_row.id = NEW.audit_log_id
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.user_id = OLD.from_user_id
          AND service_row.remote_identity_generation = OLD.target_remote_identity_generation
          AND service_row.lifecycle_version = OLD.target_lifecycle_version
          AND audit_row.action = 'service.operational.ownership.transferred'
          AND audit_row.actor_type = 'administrator'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND audit_row.target_type = 'service_subscription'
          AND BINARY audit_row.target_id = BINARY service_row.public_id
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.user_id')) AS UNSIGNED) = OLD.from_user_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = OLD.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = OLD.target_lifecycle_version
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.user_id')) AS UNSIGNED) = OLD.to_user_id
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = OLD.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = OLD.target_lifecycle_version + 1;
    ELSEIF OLD.state = 'applying' AND NEW.state = 'completed' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM service_subscriptions service_row
        INNER JOIN audit_logs audit_row ON audit_row.id = OLD.audit_log_id
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.user_id = OLD.to_user_id
          AND service_row.remote_identity_generation = OLD.target_remote_identity_generation
          AND service_row.lifecycle_version = OLD.target_lifecycle_version + 1
          AND audit_row.action = 'service.operational.ownership.transferred'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id;
    END IF;

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.from_user_id <> OLD.from_user_id
       OR NEW.to_user_id <> OLD.to_user_id
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.target_remote_identity_generation <> OLD.target_remote_identity_generation
       OR NEW.target_lifecycle_version <> OLD.target_lifecycle_version
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR valid_transition <> 1
       OR NOT (
            (OLD.state = 'pending' AND NEW.state = 'applying'
                AND OLD.audit_log_id IS NULL AND NEW.audit_log_id IS NOT NULL
                AND OLD.completed_at IS NULL AND NEW.completed_at IS NULL)
            OR
            (OLD.state = 'applying' AND NEW.state = 'completed'
                AND NEW.audit_log_id = OLD.audit_log_id
                AND OLD.completed_at IS NULL AND NEW.completed_at IS NOT NULL)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service ownership transfer evidence update authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_ownership_transfers_delete_guard BEFORE DELETE ON service_ownership_transfers FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_insert_guard
BEFORE INSERT ON service_reconciliation_cases
FOR EACH ROW
BEGIN
    DECLARE valid_context INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_context
    FROM service_subscriptions service_row
    INNER JOIN administrators administrator_row ON administrator_row.id = NEW.actor_administrator_id
    INNER JOIN panel_service_targets target_row ON target_row.id = NEW.service_target_id
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.service_target_id = NEW.service_target_id
      AND BINARY service_row.remote_service_id = BINARY NEW.before_remote_service_id
      AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
      AND service_row.lifecycle_version = NEW.target_lifecycle_version
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND administrator_row.status = 'active'
      AND target_row.state = 'active'
      AND (NEW.proposed_remote_service_id IS NULL OR BINARY NEW.proposed_remote_service_id <> BINARY NEW.before_remote_service_id);

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_context <> 1
       OR NEW.state <> 'previewed'
       OR NEW.audit_log_id IS NOT NULL
       OR NEW.applied_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconciliation evidence insert authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_update_guard
BEFORE UPDATE ON service_reconciliation_cases
FOR EACH ROW
BEGIN
    DECLARE valid_transition INT DEFAULT 0;

    IF OLD.state = 'previewed' AND NEW.state = 'applying' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM service_subscriptions service_row
        INNER JOIN audit_logs audit_row ON audit_row.id = NEW.audit_log_id
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.service_target_id = OLD.service_target_id
          AND BINARY service_row.remote_service_id = BINARY OLD.before_remote_service_id
          AND service_row.remote_identity_generation = OLD.target_remote_identity_generation
          AND service_row.lifecycle_version = OLD.target_lifecycle_version
          AND OLD.remote_disposition = 'present'
          AND OLD.proposed_remote_service_id IS NOT NULL
          AND OLD.remote_canonical_hash IS NOT NULL
          AND audit_row.action = 'service.operational.repair.applied'
          AND audit_row.actor_type = 'administrator'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND audit_row.target_type = 'service_subscription'
          AND BINARY audit_row.target_id = BINARY service_row.public_id
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(OLD.before_remote_service_id, 256)
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = OLD.target_remote_identity_generation
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = OLD.target_lifecycle_version
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(OLD.proposed_remote_service_id, 256)
          AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_canonical_hash')) = BINARY OLD.remote_canonical_hash
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = OLD.target_remote_identity_generation + 1
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = OLD.target_lifecycle_version + 1;
    ELSEIF OLD.state = 'applying' AND NEW.state = 'applied' THEN
        SELECT COUNT(*) INTO valid_transition
        FROM service_subscriptions service_row
        INNER JOIN service_reconciliation_changes change_row ON change_row.service_reconciliation_case_id = OLD.id
        INNER JOIN audit_logs audit_row ON audit_row.id = OLD.audit_log_id
        WHERE service_row.id = OLD.service_subscription_id
          AND service_row.service_target_id = OLD.service_target_id
          AND BINARY service_row.remote_service_id = BINARY OLD.proposed_remote_service_id
          AND service_row.remote_identity_generation = OLD.target_remote_identity_generation + 1
          AND service_row.lifecycle_version = OLD.target_lifecycle_version + 1
          AND change_row.service_subscription_id = OLD.service_subscription_id
          AND change_row.audit_log_id = OLD.audit_log_id
          AND BINARY change_row.before_value = BINARY OLD.before_remote_service_id
          AND BINARY change_row.after_value = BINARY OLD.proposed_remote_service_id
          AND change_row.before_remote_identity_generation = OLD.target_remote_identity_generation
          AND change_row.after_remote_identity_generation = OLD.target_remote_identity_generation + 1
          AND change_row.before_lifecycle_version = OLD.target_lifecycle_version
          AND change_row.after_lifecycle_version = OLD.target_lifecycle_version + 1
          AND audit_row.action = 'service.operational.repair.applied'
          AND audit_row.actor_id = CAST(OLD.actor_administrator_id AS CHAR)
          AND BINARY audit_row.request_fingerprint = BINARY OLD.request_key_hash
          AND BINARY audit_row.correlation_id = BINARY OLD.correlation_id;
    END IF;

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR NEW.service_subscription_id <> OLD.service_subscription_id
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.service_target_id <> OLD.service_target_id
       OR BINARY NEW.before_remote_service_id <> BINARY OLD.before_remote_service_id
       OR NOT (NEW.proposed_remote_service_id <=> OLD.proposed_remote_service_id)
       OR BINARY NEW.remote_disposition <> BINARY OLD.remote_disposition
       OR NOT (NEW.remote_canonical_hash <=> OLD.remote_canonical_hash)
       OR NEW.target_remote_identity_generation <> OLD.target_remote_identity_generation
       OR NEW.target_lifecycle_version <> OLD.target_lifecycle_version
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR valid_transition <> 1
       OR NOT (
            (OLD.state = 'previewed' AND NEW.state = 'applying'
                AND OLD.remote_disposition = 'present'
                AND OLD.audit_log_id IS NULL AND NEW.audit_log_id IS NOT NULL
                AND OLD.applied_at IS NULL AND NEW.applied_at IS NULL)
            OR
            (OLD.state = 'applying' AND NEW.state = 'applied'
                AND NEW.audit_log_id = OLD.audit_log_id
                AND OLD.applied_at IS NULL AND NEW.applied_at IS NOT NULL)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconciliation evidence update authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_cases_delete_guard BEFORE DELETE ON service_reconciliation_cases FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_insert_guard
BEFORE INSERT ON service_reconciliation_changes
FOR EACH ROW
BEGIN
    DECLARE valid_context INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_context
    FROM service_reconciliation_cases case_row
    INNER JOIN service_subscriptions service_row ON service_row.id = case_row.service_subscription_id
    INNER JOIN audit_logs audit_row ON audit_row.id = case_row.audit_log_id
    WHERE case_row.id = NEW.service_reconciliation_case_id
      AND case_row.state = 'applying'
      AND case_row.remote_disposition = 'present'
      AND case_row.service_subscription_id = NEW.service_subscription_id
      AND BINARY case_row.before_remote_service_id = BINARY NEW.before_value
      AND BINARY case_row.proposed_remote_service_id = BINARY NEW.after_value
      AND case_row.target_remote_identity_generation = NEW.before_remote_identity_generation
      AND case_row.target_remote_identity_generation + 1 = NEW.after_remote_identity_generation
      AND case_row.target_lifecycle_version = NEW.before_lifecycle_version
      AND case_row.target_lifecycle_version + 1 = NEW.after_lifecycle_version
      AND case_row.audit_log_id = NEW.audit_log_id
      AND service_row.service_target_id = case_row.service_target_id
      AND BINARY service_row.remote_service_id = BINARY case_row.proposed_remote_service_id
      AND service_row.remote_identity_generation = NEW.after_remote_identity_generation
      AND service_row.lifecycle_version = NEW.after_lifecycle_version
      AND audit_row.action = 'service.operational.repair.applied'
      AND audit_row.actor_id = CAST(case_row.actor_administrator_id AS CHAR)
      AND BINARY audit_row.request_fingerprint = BINARY case_row.request_key_hash
      AND BINARY audit_row.correlation_id = BINARY case_row.correlation_id;

    IF COALESCE(@app_service_operational_evidence_authority, '') <> 'service_operational_evidence_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_context <> 1
       OR NEW.field_name <> 'remote_service_id' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconciliation change authority is invalid.';
    END IF;
END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_update_guard BEFORE UPDATE ON service_reconciliation_changes FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service reconciliation change evidence is immutable.'; END
SQL,
            <<<'SQL'
CREATE OR REPLACE TRIGGER service_reconciliation_changes_delete_guard BEFORE DELETE ON service_reconciliation_changes FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational evidence is non-deletable.'; END
SQL,
        ];
    }

    /** @return list<literal-string> */
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
      AND BINARY audit_row.correlation_id = BINARY NEW.correlation_id
      AND BINARY audit_row.reason_code = BINARY NEW.reason_code
      AND CAST(JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.item_count')) AS UNSIGNED) = NEW.item_count
      AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.state')) = BINARY 'active'
      AND BINARY JSON_UNQUOTE(JSON_EXTRACT(audit_row.after_safe_data, '$.payload_hash')) = BINARY NEW.payload_hash;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_audit <> 1
       OR NEW.state <> 'active'
       OR NEW.succeeded_count <> 0
       OR NEW.failed_count <> 0
       OR NEW.items_committed_at IS NOT NULL
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
    DECLARE child_count INT DEFAULT 0;
    DECLARE succeeded_items INT DEFAULT 0;
    DECLARE failed_items INT DEFAULT 0;
    DECLARE unfinished_items INT DEFAULT 0;
    DECLARE live_claims INT DEFAULT 0;

    SELECT
        COUNT(*),
        COALESCE(SUM(item_row.state = 'succeeded'), 0),
        COALESCE(SUM(item_row.state = 'failed'), 0),
        COALESCE(SUM(item_row.state NOT IN ('succeeded','cancelled')), 0),
        COALESCE(SUM(item_row.state = 'processing' AND item_row.claim_expires_at > CURRENT_TIMESTAMP(6)), 0)
      INTO child_count, succeeded_items, failed_items, unfinished_items, live_claims
    FROM service_batch_grant_items item_row
    WHERE item_row.service_batch_grant_id = OLD.id;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR NEW.id <> OLD.id
       OR BINARY NEW.public_id <> BINARY OLD.public_id
       OR BINARY NEW.request_key_hash <> BINARY OLD.request_key_hash
       OR BINARY NEW.payload_hash <> BINARY OLD.payload_hash
       OR BINARY NEW.reason_code <> BINARY OLD.reason_code
       OR NEW.actor_administrator_id <> OLD.actor_administrator_id
       OR NEW.audit_log_id <> OLD.audit_log_id
       OR NEW.item_count <> OLD.item_count
       OR BINARY NEW.correlation_id <> BINARY OLD.correlation_id
       OR NEW.created_at <> OLD.created_at
       OR NEW.succeeded_count <> COALESCE(succeeded_items, 0)
       OR NEW.failed_count <> COALESCE(failed_items, 0)
       OR NOT (
            (OLD.items_committed_at IS NULL
                AND NEW.items_committed_at IS NOT NULL
                AND NEW.state = OLD.state AND NEW.state = 'active'
                AND child_count = OLD.item_count
                AND COALESCE(unfinished_items, 0) = OLD.item_count
                AND NEW.completed_at IS NULL)
            OR
            (OLD.items_committed_at IS NOT NULL
                AND NEW.items_committed_at <=> OLD.items_committed_at
                AND child_count = OLD.item_count
                AND (
                    (NEW.state = OLD.state AND NEW.state IN ('active','paused','cancelled'))
                    OR (OLD.state = 'active' AND NEW.state = 'paused' AND COALESCE(live_claims, 0) = 0)
                    OR (OLD.state = 'paused' AND NEW.state = 'active')
                    OR (OLD.state IN ('active','paused') AND NEW.state = 'cancelled'
                        AND COALESCE(unfinished_items, 0) = 0
                        AND COALESCE(succeeded_items, 0) < OLD.item_count)
                    OR (OLD.state = 'active' AND NEW.state = 'completed'
                        AND NEW.succeeded_count = NEW.item_count AND NEW.failed_count = 0
                        AND NEW.completed_at IS NOT NULL)
                ))
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
    DECLARE parent_item_count INT DEFAULT 0;
    DECLARE parent_request_hash CHAR(64) DEFAULT NULL;
    DECLARE parent_payload_hash CHAR(64) DEFAULT NULL;
    DECLARE parent_correlation_id VARCHAR(64) DEFAULT NULL;

    SELECT COUNT(*), MAX(batch_row.item_count), MAX(batch_row.request_key_hash), MAX(batch_row.payload_hash), MAX(batch_row.correlation_id)
      INTO valid_parent, parent_item_count, parent_request_hash, parent_payload_hash, parent_correlation_id
    FROM service_batch_grants batch_row
    WHERE batch_row.id = NEW.service_batch_grant_id
      AND batch_row.state = 'active'
      AND batch_row.items_committed_at IS NULL
      AND batch_row.actor_administrator_id > 0;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_parent <> 1
       OR NEW.position < 1 OR NEW.position > parent_item_count
       OR BINARY NEW.correlation_id <> BINARY parent_correlation_id
       OR BINARY NEW.request_key_hash <> BINARY SHA2(CONCAT(parent_request_hash, ':', parent_payload_hash, ':', NEW.position, ':', NEW.user_id, ':', NEW.plan_offering_id), 256)
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
    DECLARE valid_parent_family INT DEFAULT 0;
    DECLARE valid_result INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_parent_family
    FROM service_batch_grants batch_row
    WHERE batch_row.id = OLD.service_batch_grant_id
      AND batch_row.items_committed_at IS NOT NULL
      AND BINARY batch_row.correlation_id = BINARY OLD.correlation_id;

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
          AND BINARY source_row.authorization_key = BINARY CONCAT('service-batch:', batch_row.public_id, ':', NEW.public_id)
          AND BINARY source_row.correlation_id = BINARY NEW.correlation_id
          AND order_row.source_type = 'admin_grant'
          AND order_row.user_id = NEW.user_id
          AND order_row.total_amount_irr = 0
          AND order_row.purchase_settlement_id IS NULL
          AND order_row.payment_intent_id IS NULL
          AND order_row.state IN ('provisioning_queued','provisioning','completed','needs_review');
    END IF;

    IF COALESCE(@app_service_batch_authority, '') <> 'service_batch_grant_v1'
       OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
       OR valid_parent_family <> 1
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
            OR (OLD.state = 'pending' AND NEW.state = 'cancelled'
                AND OLD.attempt_count = 0 AND NEW.attempt_count = 0
                AND OLD.claim_token IS NULL AND OLD.claim_expires_at IS NULL
                AND NEW.claim_token IS NULL AND NEW.claim_expires_at IS NULL
                AND OLD.order_source_authorization_id IS NULL AND NEW.order_source_authorization_id IS NULL
                AND OLD.order_id IS NULL AND NEW.order_id IS NULL
                AND OLD.service_subscription_id IS NULL AND NEW.service_subscription_id IS NULL
                AND OLD.provisioning_operation_id IS NULL AND NEW.provisioning_operation_id IS NULL
                AND OLD.error_code IS NULL AND NEW.error_code IS NULL)
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
    DECLARE permission_code VARCHAR(64) DEFAULT NULL;
    DECLARE permission_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE administrator_count INT DEFAULT 0;
    DECLARE administrator_is_owner INT DEFAULT 0;
    DECLARE explicit_deny_count INT DEFAULT 0;
    DECLARE explicit_allow_count INT DEFAULT 0;
    DECLARE role_grant_count INT DEFAULT 0;
    DECLARE valid_context INT DEFAULT 0;

    IF NEW.action LIKE 'service.operational.%' THEN
        IF COALESCE(@app_service_operational_audit_authority, '') <> 'service_operational_audit_v1'
           OR NOT EXISTS (SELECT 1 FROM service_operational_authority_capability capability_row WHERE capability_row.id = 1 AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256))
           OR NEW.actor_type <> 'administrator'
           OR NEW.actor_id IS NULL
           OR NEW.actor_id NOT REGEXP '^[1-9][0-9]*$'
           OR NEW.target_type IS NULL
           OR NEW.target_id IS NULL
           OR NEW.request_fingerprint IS NULL
           OR CHAR_LENGTH(NEW.request_fingerprint) <> 64
           OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_service_operational_request_hash, '')
           OR NEW.correlation_id IS NULL
           OR NEW.reason_code IS NULL
           OR NEW.reason_code NOT REGEXP '^[a-z0-9_.-]{1,64}$'
           OR NEW.reason IS NULL
           OR CHAR_LENGTH(TRIM(NEW.reason)) = 0
           OR CHAR_LENGTH(NEW.reason) > 1000
           OR NEW.before_safe_data IS NULL
           OR NEW.after_safe_data IS NULL
           OR JSON_VALID(NEW.before_safe_data) <> 1
           OR JSON_VALID(NEW.after_safe_data) <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit authority is invalid.';
        END IF;

        SET permission_code = CASE NEW.action
            WHEN 'service.operational.import.attached' THEN 'services.import'
            WHEN 'service.operational.ownership.transferred' THEN 'services.transfer_ownership'
            WHEN 'service.operational.repair.applied' THEN 'services.repair'
            WHEN 'service.operational.batch.created' THEN 'services.grant_batch'
            ELSE NULL
        END;
        IF permission_code IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit action is not recognized.';
        END IF;

        SELECT COUNT(*), COALESCE(MAX(administrator_row.is_owner), 0)
          INTO administrator_count, administrator_is_owner
        FROM administrators administrator_row
        WHERE administrator_row.id = CAST(NEW.actor_id AS UNSIGNED)
          AND administrator_row.status = 'active';
        IF administrator_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit administrator is not active.';
        END IF;

        IF administrator_is_owner = 0 THEN
            SELECT MAX(permission_row.id) INTO permission_id
            FROM permissions permission_row
            WHERE permission_row.code = permission_code;
            IF permission_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit permission is not registered.';
            END IF;

            SELECT COUNT(*) INTO explicit_deny_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND override_row.permission_id = permission_id
              AND override_row.effect = 'deny';
            IF explicit_deny_count > 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit is denied by an explicit permission override.';
            END IF;

            SELECT COUNT(*) INTO explicit_allow_count
            FROM administrator_permission_overrides override_row
            WHERE override_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND override_row.permission_id = permission_id
              AND override_row.effect = 'allow';

            SELECT COUNT(*) INTO role_grant_count
            FROM administrator_role_assignments assignment_row
            INNER JOIN roles role_row ON role_row.id = assignment_row.role_id
            INNER JOIN role_permissions role_permission_row ON role_permission_row.role_id = role_row.id
            WHERE assignment_row.administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND assignment_row.revoked_at IS NULL
              AND role_row.is_active = 1
              AND role_permission_row.permission_id = permission_id;
            IF explicit_allow_count = 0 AND role_grant_count = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit administrator lacks required permission.';
            END IF;
        END IF;

        IF NEW.action = 'service.operational.import.attached' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_imports import_row
            INNER JOIN order_source_authorizations source_row
                ON source_row.authorization_key = CONCAT('service-import:', import_row.public_id)
               AND source_row.source_type = 'admin_grant'
               AND source_row.user_id = import_row.user_id
               AND source_row.plan_offering_id = import_row.plan_offering_id
               AND source_row.actor_type = 'administrator'
               AND source_row.actor_id = import_row.actor_administrator_id
               AND BINARY source_row.correlation_id = BINARY import_row.correlation_id
            INNER JOIN orders order_row
                ON order_row.order_source_authorization_id = source_row.id
               AND order_row.source_type = 'admin_grant'
               AND order_row.user_id = import_row.user_id
               AND order_row.total_amount_irr = 0
               AND order_row.purchase_settlement_id IS NULL
               AND order_row.payment_intent_id IS NULL
            INNER JOIN order_items item_row
                ON item_row.order_id = order_row.id
               AND item_row.line_number = 1
               AND item_row.plan_offering_id = import_row.plan_offering_id
            INNER JOIN service_subscriptions service_row
                ON service_row.order_id = order_row.id
               AND service_row.order_item_id = item_row.id
               AND service_row.user_id = import_row.user_id
               AND service_row.service_target_id IS NULL
               AND service_row.remote_service_id IS NULL
            WHERE import_row.state = 'previewed'
              AND BINARY import_row.request_key_hash = BINARY NEW.request_fingerprint
              AND import_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY import_row.correlation_id = BINARY NEW.correlation_id
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND BINARY NEW.reason_code = BINARY source_row.reason_code
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.user_id')) AS UNSIGNED) = import_row.user_id
              AND JSON_TYPE(JSON_EXTRACT(NEW.before_safe_data, '$.service_target_id')) = 'NULL'
              AND JSON_TYPE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_service_id')) = 'NULL'
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.user_id')) AS UNSIGNED) = import_row.user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.service_target_id')) AS UNSIGNED) = import_row.service_target_id
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(import_row.remote_service_id, 256)
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_canonical_hash')) = BINARY import_row.remote_canonical_hash;
        ELSEIF NEW.action = 'service.operational.ownership.transferred' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_ownership_transfers transfer_row
            INNER JOIN service_subscriptions service_row ON service_row.id = transfer_row.service_subscription_id
            WHERE transfer_row.state = 'pending'
              AND transfer_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY transfer_row.request_key_hash = BINARY NEW.request_fingerprint
              AND BINARY transfer_row.correlation_id = BINARY NEW.correlation_id
              AND service_row.user_id = transfer_row.from_user_id
              AND service_row.remote_identity_generation = transfer_row.target_remote_identity_generation
              AND service_row.lifecycle_version = transfer_row.target_lifecycle_version
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.user_id')) AS UNSIGNED) = transfer_row.from_user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = transfer_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = transfer_row.target_lifecycle_version
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.user_id')) AS UNSIGNED) = transfer_row.to_user_id
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = transfer_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = transfer_row.target_lifecycle_version + 1;
        ELSEIF NEW.action = 'service.operational.repair.applied' THEN
            SELECT COUNT(*) INTO valid_context
            FROM service_reconciliation_cases case_row
            INNER JOIN service_subscriptions service_row ON service_row.id = case_row.service_subscription_id
            WHERE case_row.state = 'previewed'
              AND case_row.remote_disposition = 'present'
              AND case_row.proposed_remote_service_id IS NOT NULL
              AND case_row.remote_canonical_hash IS NOT NULL
              AND case_row.actor_administrator_id = CAST(NEW.actor_id AS UNSIGNED)
              AND BINARY case_row.request_key_hash = BINARY NEW.request_fingerprint
              AND BINARY case_row.correlation_id = BINARY NEW.correlation_id
              AND service_row.service_target_id = case_row.service_target_id
              AND BINARY service_row.remote_service_id = BINARY case_row.before_remote_service_id
              AND service_row.remote_identity_generation = case_row.target_remote_identity_generation
              AND service_row.lifecycle_version = case_row.target_lifecycle_version
              AND NEW.target_type = 'service_subscription'
              AND BINARY NEW.target_id = BINARY service_row.public_id
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(case_row.before_remote_service_id, 256)
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = case_row.target_remote_identity_generation
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.before_safe_data, '$.lifecycle_version')) AS UNSIGNED) = case_row.target_lifecycle_version
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_service_id_hash')) = BINARY SHA2(case_row.proposed_remote_service_id, 256)
              AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_canonical_hash')) = BINARY case_row.remote_canonical_hash
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.remote_identity_generation')) AS UNSIGNED) = case_row.target_remote_identity_generation + 1
              AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.lifecycle_version')) AS UNSIGNED) = case_row.target_lifecycle_version + 1;
        ELSEIF NEW.action = 'service.operational.batch.created' THEN
            IF NEW.target_type = 'service_batch_grant'
               AND NEW.target_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
               AND BINARY JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.state')) = BINARY 'active'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.item_count')) AS UNSIGNED) BETWEEN 1 AND 50
               AND JSON_UNQUOTE(JSON_EXTRACT(NEW.after_safe_data, '$.payload_hash')) REGEXP '^[0-9a-f]{64}$' THEN
                SET valid_context = 1;
            END IF;
        END IF;

        IF valid_context <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service operational audit is detached from exact operational authority.';
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
        if (! $this->operationalCapabilityReady() || ! $this->remoteIdentityIndexCompatible()) {
            return false;
        }

        if (! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'case_row.before_remote_service_id')
            || ! $this->triggerContains('service_imports_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_ownership_transfers_update_guard', "audit_row.action = 'service.operational.ownership.transferred'")
            || ! $this->triggerContains('service_reconciliation_cases_update_guard', 'service_reconciliation_changes change_row')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'permission_code')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'service_operational_authority_capability')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'unfinished_items')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'live_claims')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'child_count = OLD.item_count')
            || ! $this->triggerContains('service_batch_grant_items_insert_guard', 'batch_row.items_committed_at IS NULL')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'valid_parent_family')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'OLD.attempt_count = 0')
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
        if (! $this->operationalCapabilityReady()
            || ! $this->remoteIdentityIndexCompatible()
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'case_row.before_remote_service_id')
            || ! $this->triggerContains('service_imports_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_ownership_transfers_update_guard', "audit_row.action = 'service.operational.ownership.transferred'")
            || ! $this->triggerContains('service_reconciliation_cases_update_guard', 'service_reconciliation_changes change_row')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'permission_code')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'service_operational_authority_capability')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'unfinished_items')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'live_claims')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'child_count = OLD.item_count')
            || ! $this->triggerContains('service_batch_grant_items_insert_guard', 'batch_row.items_committed_at IS NULL')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'valid_parent_family')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'OLD.attempt_count = 0')
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
        if (! $this->operationalCapabilityReady()
            || ! $this->remoteIdentityIndexCompatible()
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_ownership_transfer_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_repair_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'service_import_attach_v1')
            || ! $this->triggerContains('service_subscriptions_update_guard', 'case_row.before_remote_service_id')
            || ! $this->triggerContains('service_imports_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_ownership_transfers_update_guard', "audit_row.action = 'service.operational.ownership.transferred'")
            || ! $this->triggerContains('service_reconciliation_cases_update_guard', 'service_reconciliation_changes change_row')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'permission_code')
            || ! $this->triggerContains('audit_logs_service_operational_insert_guard', 'service_operational_authority_capability')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'unfinished_items')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'live_claims')
            || ! $this->triggerContains('service_batch_grants_update_guard', 'child_count = OLD.item_count')
            || ! $this->triggerContains('service_batch_grant_items_insert_guard', 'batch_row.items_committed_at IS NULL')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'valid_parent_family')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'source_row.authorization_key')
            || ! $this->triggerContains('service_batch_grant_items_update_guard', 'OLD.attempt_count = 0')
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
