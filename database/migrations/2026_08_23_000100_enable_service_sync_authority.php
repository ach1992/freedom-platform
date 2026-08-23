<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-001 SVC-010 SVC-013 PRV-003 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        if (! Schema::hasTable('service_operational_authority_capability')) {
            throw new RuntimeException('Service synchronization authority requires the operational database capability foundation.');
        }

        if (! Schema::hasTable('service_sync_runs')) {
            Schema::create('service_sync_runs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->char('run_key_hash', 64)->unique();
                $table->string('scope', 16);
                $table->foreignId('service_subscription_id')->nullable()->constrained('service_subscriptions', indexName: 'service_sync_runs_service_fk')->restrictOnDelete();
                $table->string('state', 32);
                $table->unsignedInteger('candidate_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('anomaly_count')->default(0);
                $table->unsignedInteger('failure_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->string('correlation_id', 64);
                $table->dateTime('started_at', 6);
                $table->dateTime('completed_at', 6)->nullable();
                $table->index(['state', 'started_at'], 'service_sync_runs_state_started_idx');
            });
        }

        if (! Schema::hasTable('service_sync_leases')) {
            Schema::create('service_sync_leases', function (Blueprint $table): void {
                $table->foreignId('service_subscription_id')->primary()->constrained('service_subscriptions', indexName: 'service_sync_leases_service_fk')->cascadeOnDelete();
                $table->char('lease_token_hash', 64);
                $table->dateTime('claimed_at', 6);
                $table->dateTime('expires_at', 6);
            });
        }

        if (! Schema::hasTable('service_sync_snapshots')) {
            Schema::create('service_sync_snapshots', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('service_sync_run_id')->constrained('service_sync_runs', indexName: 'service_sync_snapshots_run_fk')->restrictOnDelete();
                $table->foreignId('service_subscription_id')->constrained('service_subscriptions', indexName: 'service_sync_snapshots_service_fk')->restrictOnDelete();
                $table->unsignedBigInteger('service_target_id');
                $table->string('local_lifecycle_state', 16);
                $table->unsignedBigInteger('local_lifecycle_version');
                $table->unsignedBigInteger('local_remote_identity_generation');
                $table->unsignedBigInteger('local_mutation_generation');
                $table->char('expected_remote_id_hash', 64);
                $table->string('remote_disposition', 24);
                $table->char('remote_id_hash', 64)->nullable();
                $table->string('remote_status', 16)->nullable();
                $table->unsignedBigInteger('remote_data_limit_bytes')->nullable();
                $table->unsignedBigInteger('remote_used_bytes')->nullable();
                $table->dateTime('remote_expires_at', 6)->nullable();
                $table->char('remote_canonical_hash', 64)->nullable();
                $table->dateTime('observed_at', 6);
                $table->unique(['service_sync_run_id', 'service_subscription_id'], 'service_sync_snapshot_run_service_unique');
                $table->index(['service_subscription_id', 'observed_at'], 'service_sync_snapshot_service_observed_idx');
                $table->foreign('service_target_id', 'service_sync_snapshots_target_fk')->references('id')->on('panel_service_targets')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('service_sync_anomalies')) {
            Schema::create('service_sync_anomalies', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('service_subscription_id')->constrained('service_subscriptions', indexName: 'service_sync_anomalies_service_fk')->restrictOnDelete();
                $table->char('anomaly_key', 64)->unique();
                $table->string('classification', 48);
                $table->string('severity', 16);
                $table->foreignId('first_snapshot_id')->constrained('service_sync_snapshots', indexName: 'service_sync_anomaly_first_snapshot_fk')->restrictOnDelete();
                $table->foreignId('latest_snapshot_id')->constrained('service_sync_snapshots', indexName: 'service_sync_anomaly_latest_snapshot_fk')->restrictOnDelete();
                $table->string('state', 24);
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->string('resolution_action', 32)->nullable();
                $table->foreignId('resolution_actor_administrator_id')->nullable()->constrained('administrators', indexName: 'service_sync_anomaly_actor_fk')->restrictOnDelete();
                $table->char('resolution_request_hash', 64)->nullable()->unique();
                $table->string('resolution_reason_code', 64)->nullable();
                $table->string('resolution_reason', 1000)->nullable();
                $table->string('resolution_correlation_id', 64)->nullable();
                $table->dateTime('first_detected_at', 6);
                $table->dateTime('last_detected_at', 6);
                $table->dateTime('resolved_at', 6)->nullable();
                $table->index(['service_subscription_id', 'state', 'last_detected_at'], 'service_sync_anomaly_service_state_idx');
                $table->index(['classification', 'severity', 'state'], 'service_sync_anomaly_class_severity_idx');
            });
        }

        if (! Schema::hasTable('service_sync_anomaly_events')) {
            Schema::create('service_sync_anomaly_events', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('service_sync_anomaly_id')->constrained('service_sync_anomalies', indexName: 'service_sync_events_anomaly_fk')->restrictOnDelete();
                $table->foreignId('service_sync_snapshot_id')->nullable()->constrained('service_sync_snapshots', indexName: 'service_sync_events_snapshot_fk')->restrictOnDelete();
                $table->string('event_type', 32);
                $table->string('from_state', 24)->nullable();
                $table->string('to_state', 24);
                $table->unsignedInteger('occurrence_count');
                $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators', indexName: 'service_sync_events_actor_fk')->restrictOnDelete();
                $table->string('reason_code', 64)->nullable();
                $table->string('correlation_id', 64);
                $table->dateTime('created_at', 6);
                $table->index(['service_sync_anomaly_id', 'created_at'], 'service_sync_events_anomaly_created_idx');
            });
        }

        $this->installConstraints();
        $this->installGuards();
    }

    public function down(): void
    {
        foreach (['service_sync_anomaly_events', 'service_sync_anomalies', 'service_sync_snapshots', 'service_sync_runs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Service synchronization authority while durable synchronization evidence exists.');
            }
        }

        $this->dropGuards();
        Schema::dropIfExists('service_sync_anomaly_events');
        Schema::dropIfExists('service_sync_anomalies');
        Schema::dropIfExists('service_sync_snapshots');
        Schema::dropIfExists('service_sync_leases');
        Schema::dropIfExists('service_sync_runs');
    }

    private function installConstraints(): void
    {
        foreach ([
            "ALTER TABLE service_sync_runs ADD CONSTRAINT service_sync_runs_key_chk CHECK (`run_key_hash` REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_sync_runs ADD CONSTRAINT service_sync_runs_scope_chk CHECK (`scope` IN ('service','batch','full'))",
            "ALTER TABLE service_sync_runs ADD CONSTRAINT service_sync_runs_state_chk CHECK (`state` IN ('running','completed','completed_with_anomalies','failed'))",
            "ALTER TABLE service_sync_runs ADD CONSTRAINT service_sync_runs_shape_chk CHECK ((`scope` = 'service' AND `service_subscription_id` IS NOT NULL) OR (`scope` IN ('batch','full') AND `service_subscription_id` IS NULL))",
            "ALTER TABLE service_sync_runs ADD CONSTRAINT service_sync_runs_completion_chk CHECK ((`state` = 'running' AND `completed_at` IS NULL) OR (`state` <> 'running' AND `completed_at` IS NOT NULL))",
            "ALTER TABLE service_sync_leases ADD CONSTRAINT service_sync_leases_token_chk CHECK (`lease_token_hash` REGEXP '^[0-9a-f]{64}$' AND `expires_at` > `claimed_at`)",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_lifecycle_chk CHECK (`local_lifecycle_state` IN ('active','suspended'))",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_generation_chk CHECK (`local_lifecycle_version` >= 0 AND `local_remote_identity_generation` >= 1 AND `local_mutation_generation` >= 0)",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_expected_id_chk CHECK (`expected_remote_id_hash` REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_disposition_chk CHECK (`remote_disposition` IN ('present','missing','unavailable','identity_mismatch'))",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_status_chk CHECK (`remote_status` IS NULL OR `remote_status` IN ('active','suspended','expired','disabled','unknown'))",
            "ALTER TABLE service_sync_snapshots ADD CONSTRAINT service_sync_snapshots_remote_shape_chk CHECK (((`remote_disposition` IN ('present','identity_mismatch')) AND `remote_id_hash` REGEXP '^[0-9a-f]{64}$' AND `remote_status` IS NOT NULL AND `remote_canonical_hash` REGEXP '^[0-9a-f]{64}$') OR ((`remote_disposition` IN ('missing','unavailable')) AND `remote_id_hash` IS NULL AND `remote_status` IS NULL AND `remote_data_limit_bytes` IS NULL AND `remote_used_bytes` IS NULL AND `remote_expires_at` IS NULL AND `remote_canonical_hash` IS NULL))",
            "ALTER TABLE service_sync_anomalies ADD CONSTRAINT service_sync_anomaly_key_chk CHECK (`anomaly_key` REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_sync_anomalies ADD CONSTRAINT service_sync_anomaly_class_chk CHECK (`classification` IN ('missing_remote','expired_local_active_remote','lifecycle_mismatch','remote_identity_mismatch','unexpected_entitlement'))",
            "ALTER TABLE service_sync_anomalies ADD CONSTRAINT service_sync_anomaly_severity_chk CHECK (`severity` IN ('informational','warning','critical'))",
            "ALTER TABLE service_sync_anomalies ADD CONSTRAINT service_sync_anomaly_state_chk CHECK (`state` IN ('open','resolved','manual_review','action_requested'))",
            "ALTER TABLE service_sync_anomalies ADD CONSTRAINT service_sync_anomaly_resolution_chk CHECK ((`state` = 'open' AND `resolution_action` IS NULL AND `resolution_actor_administrator_id` IS NULL AND `resolution_request_hash` IS NULL AND `resolution_reason_code` IS NULL AND `resolution_reason` IS NULL AND `resolution_correlation_id` IS NULL AND `resolved_at` IS NULL) OR (`state` <> 'open' AND `resolution_action` IN ('adopt_remote_state','reprovision','flag_manual_review','ignore') AND `resolution_actor_administrator_id` IS NOT NULL AND `resolution_request_hash` REGEXP '^[0-9a-f]{64}$' AND `resolution_reason_code` IS NOT NULL AND `resolution_reason` IS NOT NULL AND `resolution_correlation_id` IS NOT NULL AND `resolved_at` IS NOT NULL))",
            "ALTER TABLE service_sync_anomaly_events ADD CONSTRAINT service_sync_events_type_chk CHECK (`event_type` IN ('detected','seen','resolved','manual_review','action_requested'))",
            "ALTER TABLE service_sync_anomaly_events ADD CONSTRAINT service_sync_events_state_chk CHECK ((`from_state` IS NULL OR `from_state` IN ('open','resolved','manual_review','action_requested')) AND `to_state` IN ('open','resolved','manual_review','action_requested'))",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_runs_insert_guard
BEFORE INSERT ON service_sync_runs
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_run_create_v1'
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_sync_correlation_id, '')
       OR NEW.state <> 'running' OR NEW.candidate_count <> 0 OR NEW.processed_count <> 0
       OR NEW.anomaly_count <> 0 OR NEW.failure_count <> 0 OR NEW.skipped_count <> 0
       OR NEW.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync run creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_runs_update_guard
BEFORE UPDATE ON service_sync_runs
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_run_finalize_v1'
       OR NEW.id <> COALESCE(@app_service_sync_run_id, 0)
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_sync_correlation_id, '')
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR BINARY OLD.run_key_hash <> BINARY NEW.run_key_hash OR BINARY OLD.scope <> BINARY NEW.scope
       OR NOT (OLD.service_subscription_id <=> NEW.service_subscription_id)
       OR BINARY OLD.correlation_id <> BINARY NEW.correlation_id OR OLD.started_at <> NEW.started_at
       OR OLD.state <> 'running' OR NEW.state NOT IN ('completed','completed_with_anomalies','failed')
       OR NEW.completed_at IS NULL
       OR NEW.processed_count + NEW.skipped_count > NEW.candidate_count THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync run finalization authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_runs_delete_guard
BEFORE DELETE ON service_sync_runs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync run evidence is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_leases_insert_guard
BEFORE INSERT ON service_sync_leases
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_lease_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
       OR BINARY NEW.lease_token_hash <> BINARY SHA2(COALESCE(@app_service_sync_lease_token, ''), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync lease creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_leases_update_guard
BEFORE UPDATE ON service_sync_leases
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_lease_v1'
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
       OR BINARY NEW.lease_token_hash <> BINARY SHA2(COALESCE(@app_service_sync_lease_token, ''), 256)
       OR OLD.expires_at >= CURRENT_TIMESTAMP(6) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync lease takeover authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_leases_delete_guard
BEFORE DELETE ON service_sync_leases
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_lease_v1'
       OR OLD.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
       OR BINARY OLD.lease_token_hash <> BINARY SHA2(COALESCE(@app_service_sync_lease_token, ''), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync lease release authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_snapshots_insert_guard
BEFORE INSERT ON service_sync_snapshots
FOR EACH ROW
BEGIN
    DECLARE valid_service_count INT DEFAULT 0;
    DECLARE valid_run_count INT DEFAULT 0;
    DECLARE valid_lease_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_snapshot_v1'
       OR NEW.service_sync_run_id <> COALESCE(@app_service_sync_run_id, 0)
       OR NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshot authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_run_count
    FROM service_sync_runs run_row
    WHERE run_row.id = NEW.service_sync_run_id AND run_row.state = 'running';
    IF valid_run_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshot requires a live sync run.';
    END IF;

    SELECT COUNT(*) INTO valid_lease_count
    FROM service_sync_leases lease_row
    WHERE lease_row.service_subscription_id = NEW.service_subscription_id
      AND BINARY lease_row.lease_token_hash = BINARY SHA2(COALESCE(@app_service_sync_lease_token, ''), 256)
      AND lease_row.expires_at > CURRENT_TIMESTAMP(6);
    IF valid_lease_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshot requires the current unexpired Service lease.';
    END IF;

    SELECT COUNT(*) INTO valid_service_count
    FROM service_subscriptions service_row
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.service_target_id = NEW.service_target_id
      AND service_row.remote_service_id IS NOT NULL
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state = NEW.local_lifecycle_state
      AND service_row.lifecycle_version = NEW.local_lifecycle_version
      AND service_row.remote_identity_generation = NEW.local_remote_identity_generation
      AND service_row.mutation_generation = NEW.local_mutation_generation
      AND BINARY LOWER(SHA2(service_row.remote_service_id, 256)) = BINARY NEW.expected_remote_id_hash;
    IF valid_service_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshot lost current Service authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_snapshots_update_guard
BEFORE UPDATE ON service_sync_snapshots
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshots are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_snapshots_delete_guard
BEFORE DELETE ON service_sync_snapshots
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync snapshots are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_anomalies_insert_guard
BEFORE INSERT ON service_sync_anomalies
FOR EACH ROW
BEGIN
    DECLARE valid_snapshot_count INT DEFAULT 0;
    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') <> 'service_sync_anomaly_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
       OR NEW.state <> 'open' OR NEW.occurrence_count <> 1 OR NEW.resolution_action IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_snapshot_count
    FROM service_sync_snapshots snapshot_row
    WHERE snapshot_row.id = NEW.first_snapshot_id
      AND snapshot_row.id = NEW.latest_snapshot_id
      AND snapshot_row.service_subscription_id = NEW.service_subscription_id
      AND snapshot_row.service_sync_run_id = COALESCE(@app_service_sync_run_id, 0);
    IF valid_snapshot_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly snapshot evidence is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_anomalies_update_guard
BEFORE UPDATE ON service_sync_anomalies
FOR EACH ROW
BEGIN
    DECLARE valid_snapshot_count INT DEFAULT 0;
    DECLARE valid_actor_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR BINARY OLD.anomaly_key <> BINARY NEW.anomaly_key
       OR BINARY OLD.classification <> BINARY NEW.classification
       OR BINARY OLD.severity <> BINARY NEW.severity
       OR OLD.first_snapshot_id <> NEW.first_snapshot_id
       OR OLD.first_detected_at <> NEW.first_detected_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly identity is immutable.';
    END IF;

    IF COALESCE(@app_service_sync_authority, '') = 'service_sync_anomaly_v1' THEN
        IF NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
           OR BINARY NEW.state <> BINARY OLD.state
           OR NOT (NEW.resolution_action <=> OLD.resolution_action)
           OR NOT (NEW.resolution_actor_administrator_id <=> OLD.resolution_actor_administrator_id)
           OR NOT (NEW.resolution_request_hash <=> OLD.resolution_request_hash)
           OR NOT (NEW.resolution_reason_code <=> OLD.resolution_reason_code)
           OR NOT (NEW.resolution_reason <=> OLD.resolution_reason)
           OR NOT (NEW.resolution_correlation_id <=> OLD.resolution_correlation_id)
           OR NOT (NEW.resolved_at <=> OLD.resolved_at)
           OR NEW.occurrence_count <> OLD.occurrence_count + 1
           OR NEW.last_detected_at < OLD.last_detected_at THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly recurrence authority is invalid.';
        END IF;
        SELECT COUNT(*) INTO valid_snapshot_count
        FROM service_sync_snapshots snapshot_row
        WHERE snapshot_row.id = NEW.latest_snapshot_id
          AND snapshot_row.service_subscription_id = NEW.service_subscription_id
          AND snapshot_row.service_sync_run_id = COALESCE(@app_service_sync_run_id, 0);
        IF valid_snapshot_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly recurrence snapshot is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_sync_authority, '') = 'service_sync_resolution_v1' THEN
        IF NEW.service_subscription_id <> COALESCE(@app_service_sync_service_id, 0)
           OR OLD.state <> 'open' OR NEW.state NOT IN ('resolved','manual_review','action_requested')
           OR NEW.latest_snapshot_id <> OLD.latest_snapshot_id
           OR NEW.occurrence_count <> OLD.occurrence_count
           OR NEW.last_detected_at <> OLD.last_detected_at
           OR NEW.resolution_actor_administrator_id <> COALESCE(@app_service_sync_actor_id, 0)
           OR BINARY NEW.resolution_request_hash <> BINARY COALESCE(@app_service_sync_request_hash, '')
           OR BINARY NEW.resolution_correlation_id <> BINARY COALESCE(@app_service_sync_correlation_id, '')
           OR NEW.resolved_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly resolution authority is invalid.';
        END IF;
        SELECT COUNT(*) INTO valid_actor_count
        FROM administrators administrator_row
        WHERE administrator_row.id = NEW.resolution_actor_administrator_id
          AND administrator_row.status = 'active';
        IF valid_actor_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly resolution actor is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly update authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_anomalies_delete_guard
BEFORE DELETE ON service_sync_anomalies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly evidence is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_events_insert_guard
BEFORE INSERT ON service_sync_anomaly_events
FOR EACH ROW
BEGIN
    DECLARE valid_anomaly_count INT DEFAULT 0;
    DECLARE current_state VARCHAR(24);
    DECLARE current_occurrence_count INT;
    DECLARE current_reason_code VARCHAR(64);

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_sync_authority, '') NOT IN ('service_sync_anomaly_v1','service_sync_resolution_v1')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_sync_correlation_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly event authority is invalid.';
    END IF;

    SELECT COUNT(*), MAX(anomaly_row.state), MAX(anomaly_row.occurrence_count), MAX(anomaly_row.resolution_reason_code)
      INTO valid_anomaly_count, current_state, current_occurrence_count, current_reason_code
    FROM service_sync_anomalies anomaly_row
    WHERE anomaly_row.id = NEW.service_sync_anomaly_id
      AND anomaly_row.service_subscription_id = COALESCE(@app_service_sync_service_id, 0);
    IF valid_anomaly_count <> 1 OR BINARY NEW.to_state <> BINARY current_state OR NEW.occurrence_count <> current_occurrence_count THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly event binding is invalid.';
    END IF;

    IF COALESCE(@app_service_sync_authority, '') = 'service_sync_anomaly_v1' THEN
        IF NEW.event_type NOT IN ('detected','seen')
           OR NEW.service_sync_snapshot_id IS NULL
           OR NEW.actor_administrator_id IS NOT NULL
           OR NEW.reason_code IS NOT NULL
           OR (NEW.event_type = 'detected' AND (NEW.from_state IS NOT NULL OR NEW.to_state <> 'open' OR NEW.occurrence_count <> 1))
           OR (NEW.event_type = 'seen' AND (NEW.from_state IS NULL OR BINARY NEW.from_state <> BINARY NEW.to_state)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly observation event is invalid.';
        END IF;
    ELSE
        IF NEW.event_type NOT IN ('resolved','manual_review','action_requested')
           OR NEW.service_sync_snapshot_id IS NOT NULL
           OR NEW.from_state <> 'open'
           OR NEW.actor_administrator_id <> COALESCE(@app_service_sync_actor_id, 0)
           OR BINARY COALESCE(NEW.reason_code, '') <> BINARY COALESCE(current_reason_code, '') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync resolution event is invalid.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_events_update_guard
BEFORE UPDATE ON service_sync_anomaly_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly events are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_sync_events_delete_guard
BEFORE DELETE ON service_sync_anomaly_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync anomaly events are non-deletable.';
END
SQL);
    }

    private function dropGuards(): void
    {
        foreach ([
            'service_sync_runs_insert_guard', 'service_sync_runs_update_guard', 'service_sync_runs_delete_guard',
            'service_sync_leases_insert_guard', 'service_sync_leases_update_guard', 'service_sync_leases_delete_guard',
            'service_sync_snapshots_insert_guard', 'service_sync_snapshots_update_guard', 'service_sync_snapshots_delete_guard',
            'service_sync_anomalies_insert_guard', 'service_sync_anomalies_update_guard', 'service_sync_anomalies_delete_guard',
            'service_sync_events_insert_guard', 'service_sync_events_update_guard', 'service_sync_events_delete_guard',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.$trigger.'`');
        }
    }
};
