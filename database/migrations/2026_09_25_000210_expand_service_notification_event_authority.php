<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-013 DAT-003 QUA-004 */
    public function up(): void
    {
        foreach ([
            'service_notification_states',
            'service_sync_snapshots',
            'service_sync_anomalies',
            'provisioning_operations',
            'service_subscriptions',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service notification event authority requires the accepted notification, sync, mutation, and Service foundations.');
            }
        }

        if (! Schema::hasColumn('service_notification_states', 'sync_snapshot_max_age_seconds')) {
            Schema::table('service_notification_states', function (Blueprint $table): void {
                $table->unsignedInteger('sync_snapshot_max_age_seconds')
                    ->nullable()
                    ->after('expiry_snapshot_max_age_seconds');
            });
        }

        $this->replaceTypeConstraint(false);
        if (! $this->constraintExists('service_notification_states', 'sns_sync_freshness_chk')) {
            DB::statement("ALTER TABLE service_notification_states ADD CONSTRAINT sns_sync_freshness_chk CHECK ((notification_type = 'usage' AND sync_snapshot_max_age_seconds BETWEEN 60 AND 86400) OR (notification_type <> 'usage' AND sync_snapshot_max_age_seconds IS NULL))");
        }

        DB::unprepared($this->insertGuardV2());
        DB::unprepared($this->updateGuardV2());
        DB::unprepared($this->deliveryAttemptInsertGuardV2());
    }

    public function down(): void
    {
        if (! Schema::hasTable('service_notification_states')
            || ! Schema::hasColumn('service_notification_states', 'sync_snapshot_max_age_seconds')) {
            return;
        }
        if (DB::table('service_notification_states')
            ->whereIn('notification_type', ['usage', 'service_state', 'sync_issue'])
            ->exists()) {
            throw new RuntimeException('Cannot roll back Service notification event authority while expanded notification evidence exists.');
        }
        if (DB::table('service_notification_states')->whereNotNull('sync_snapshot_max_age_seconds')->exists()) {
            throw new RuntimeException('Cannot roll back Service notification event authority while sync freshness evidence exists.');
        }

        DB::unprepared($this->deliveryAttemptInsertGuardV1());
        DB::unprepared($this->insertGuardV1());
        DB::unprepared($this->updateGuardV1());
        if ($this->constraintExists('service_notification_states', 'sns_sync_freshness_chk')) {
            DB::statement('ALTER TABLE service_notification_states DROP CONSTRAINT sns_sync_freshness_chk');
        }
        $this->replaceTypeConstraint(true);
        Schema::table('service_notification_states', function (Blueprint $table): void {
            $table->dropColumn('sync_snapshot_max_age_seconds');
        });
    }

    private function replaceTypeConstraint(bool $legacy): void
    {
        if ($this->constraintExists('service_notification_states', 'sns_type_chk')) {
            DB::statement('ALTER TABLE service_notification_states DROP CONSTRAINT sns_type_chk');
        }
        if ($legacy) {
            DB::statement("ALTER TABLE service_notification_states ADD CONSTRAINT sns_type_chk CHECK (notification_type IN ('expiry','low_balance','renewal_failure'))");

            return;
        }
        DB::statement("ALTER TABLE service_notification_states ADD CONSTRAINT sns_type_chk CHECK (notification_type IN ('expiry','usage','low_balance','renewal_failure','service_state','sync_issue'))");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND CONSTRAINT_NAME = ?
  AND CONSTRAINT_TYPE = 'CHECK'
SQL, [$table, $constraint]);

        return $row !== null && (int) $row->aggregate === 1;
    }

    /** @return literal-string */
    private function insertGuardV2(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_insert_guard
BEFORE INSERT ON service_notification_states
FOR EACH ROW
BEGIN
    DECLARE valid_source_count INT DEFAULT 0;
    DECLARE expected_cycle CHAR(64) DEFAULT NULL;
    DECLARE expected_threshold VARCHAR(64) DEFAULT NULL;
    DECLARE low_balance_threshold BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_credit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_debit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_active_holds BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_available BIGINT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_create_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.episode_key_hash <> BINARY COALESCE(@app_service_notification_episode_key, '')
       OR BINARY NEW.notification_type <> BINARY COALESCE(@app_service_notification_type, '')
       OR BINARY NEW.threshold_code <> BINARY COALESCE(@app_service_notification_threshold_code, '')
       OR BINARY NEW.cycle_key_hash <> BINARY COALESCE(@app_service_notification_cycle_key, '')
       OR BINARY NEW.source_type <> BINARY COALESCE(@app_service_notification_source_type, '')
       OR NOT (NEW.source_id <=> @app_service_notification_source_id)
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR NEW.triggered_at <> @app_service_notification_timestamp
       OR NOT (NEW.low_balance_threshold_irr <=> @app_service_notification_low_balance_threshold_irr)
       OR NOT (NEW.expiry_snapshot_max_age_seconds <=> @app_service_notification_expiry_snapshot_max_age_seconds)
       OR NOT (NEW.sync_snapshot_max_age_seconds <=> @app_service_notification_sync_snapshot_max_age_seconds)
       OR NEW.max_retries <> COALESCE(@app_service_notification_max_retries, 65535)
       OR NEW.updated_at <> @app_service_notification_timestamp
       OR NEW.state <> 'triggered'
       OR NEW.latest_delivery_attempt_id IS NOT NULL OR NEW.latest_retry_ordinal IS NOT NULL
       OR NEW.next_retry_at IS NOT NULL OR NEW.notified_at IS NOT NULL OR NEW.acknowledged_at IS NOT NULL
       OR NEW.escalated_at IS NOT NULL OR NEW.expired_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state creation authority is invalid.';
    END IF;

    IF NEW.notification_type = 'expiry' THEN
        IF NEW.source_type <> 'service_sync_snapshot'
           OR NEW.source_id IS NULL
           OR NEW.expiry_snapshot_max_age_seconds NOT BETWEEN 60 AND 86400
           OR NEW.threshold_code NOT IN ('expiry_due','expiry_1d','expiry_3d','expiry_7d') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service expiry notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-expiry-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version,
                   DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
               ), 256))
          INTO valid_source_count, expected_cycle
        FROM service_sync_snapshots snapshot_row
        JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
        WHERE snapshot_row.id = NEW.source_id
          AND snapshot_row.service_subscription_id = NEW.service_subscription_id
          AND snapshot_row.remote_disposition = 'present'
          AND snapshot_row.remote_expires_at IS NOT NULL
          AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
          AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
          AND snapshot_row.local_mutation_generation = service_row.mutation_generation
          AND snapshot_row.observed_at <= NEW.triggered_at
          AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -NEW.expiry_snapshot_max_age_seconds, NEW.triggered_at)
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND NOT EXISTS (
              SELECT 1
              FROM service_sync_snapshots newer_snapshot
              WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                AND (
                    newer_snapshot.observed_at > snapshot_row.observed_at
                    OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id)
                )
          );
    ELSEIF NEW.notification_type = 'usage' THEN
        IF NEW.source_type <> 'service_sync_snapshot'
           OR NEW.source_id IS NULL
           OR NEW.sync_snapshot_max_age_seconds NOT BETWEEN 60 AND 86400
           OR NEW.threshold_code NOT IN ('usage_exhausted','usage_10pct','usage_20pct') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service usage notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(CASE
                   WHEN snapshot_row.remote_used_bytes >= snapshot_row.remote_data_limit_bytes THEN 'usage_exhausted'
                   WHEN (GREATEST(CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) - CAST(snapshot_row.remote_used_bytes AS DECIMAL(30,0)), 0) * 100)
                        <= (CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) * 10) THEN 'usage_10pct'
                   WHEN (GREATEST(CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) - CAST(snapshot_row.remote_used_bytes AS DECIMAL(30,0)), 0) * 100)
                        <= (CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) * 20) THEN 'usage_20pct'
                   ELSE NULL
               END),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-usage-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version,
                   snapshot_row.remote_data_limit_bytes
               ), 256))
          INTO valid_source_count, expected_threshold, expected_cycle
        FROM service_sync_snapshots snapshot_row
        JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
        WHERE snapshot_row.id = NEW.source_id
          AND snapshot_row.service_subscription_id = NEW.service_subscription_id
          AND snapshot_row.remote_disposition = 'present'
          AND snapshot_row.remote_data_limit_bytes IS NOT NULL
          AND snapshot_row.remote_data_limit_bytes > 0
          AND snapshot_row.remote_used_bytes IS NOT NULL
          AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
          AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
          AND snapshot_row.local_mutation_generation = service_row.mutation_generation
          AND snapshot_row.observed_at <= NEW.triggered_at
          AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -NEW.sync_snapshot_max_age_seconds, NEW.triggered_at)
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND NOT EXISTS (
              SELECT 1
              FROM service_sync_snapshots newer_snapshot
              WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                AND (
                    newer_snapshot.observed_at > snapshot_row.observed_at
                    OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id)
                )
          );
        IF BINARY NEW.threshold_code <> BINARY COALESCE(expected_threshold, '') THEN
            SET valid_source_count = 0;
        END IF;
    ELSEIF NEW.notification_type = 'renewal_failure' THEN
        IF NEW.source_type <> 'auto_renew_notification_intent' OR NEW.source_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service renewal notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(CONCAT('renewal_', intent_row.outcome)),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-renewal-cycle-v1',
                   attempt_row.service_subscription_id,
                   intent_row.id,
                   intent_row.outcome,
                   COALESCE(intent_row.reason_code, '')
               ), 256))
          INTO valid_source_count, expected_threshold, expected_cycle
        FROM service_auto_renew_notification_intents intent_row
        JOIN service_auto_renew_attempts attempt_row ON attempt_row.id = intent_row.auto_renew_attempt_id
        WHERE intent_row.id = NEW.source_id
          AND attempt_row.service_subscription_id = NEW.service_subscription_id
          AND (
              (intent_row.outcome = 'insufficient_wallet' AND attempt_row.state = 'insufficient_wallet')
              OR (intent_row.outcome = 'price_change_blocked' AND attempt_row.state = 'price_change_blocked')
              OR (intent_row.outcome = 'failure' AND attempt_row.state = 'failed')
          );
        IF BINARY NEW.threshold_code <> BINARY COALESCE(expected_threshold, '') THEN
            SET valid_source_count = 0;
        END IF;
    ELSEIF NEW.notification_type = 'low_balance' THEN
        SET low_balance_threshold = COALESCE(@app_service_notification_low_balance_threshold_irr, 0);
        IF NEW.source_type <> 'wallet_balance'
           OR NEW.source_id IS NULL OR NEW.source_id < 1
           OR NEW.threshold_code <> 'low_balance'
           OR low_balance_threshold < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service low-balance notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-low-balance-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version,
                   low_balance_threshold
               ), 256))
          INTO valid_source_count, expected_cycle
        FROM service_subscriptions service_row
        JOIN ledger_accounts wallet_row ON wallet_row.id = NEW.source_id
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND wallet_row.owner_user_id = service_row.user_id
          AND wallet_row.wallet_bucket = 'cash'
          AND wallet_row.account_class = 'liability'
          AND wallet_row.currency = 'IRR'
          AND wallet_row.is_active = 1;

        IF valid_source_count = 1 THEN
            SELECT COALESCE(SUM(CASE WHEN entry_row.direction = 'credit' THEN entry_row.amount_irr ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN entry_row.direction = 'debit' THEN entry_row.amount_irr ELSE 0 END), 0)
              INTO wallet_credit, wallet_debit
            FROM ledger_entries entry_row
            JOIN ledger_transactions transaction_row ON transaction_row.id = entry_row.ledger_transaction_id
            WHERE entry_row.ledger_account_id = NEW.source_id
              AND transaction_row.finalized_at IS NOT NULL;

            SELECT COALESCE(SUM(hold_row.amount_irr), 0)
              INTO wallet_active_holds
            FROM wallet_holds hold_row
            WHERE hold_row.ledger_account_id = NEW.source_id
              AND hold_row.status = 'active';

            IF wallet_debit > wallet_credit THEN
                SET valid_source_count = 0;
            ELSEIF wallet_active_holds > wallet_credit - wallet_debit THEN
                SET valid_source_count = 0;
            ELSE
                SET wallet_available = wallet_credit - wallet_debit - wallet_active_holds;
                IF wallet_available >= low_balance_threshold THEN
                    SET valid_source_count = 0;
                END IF;
            END IF;
        END IF;
    ELSEIF NEW.notification_type = 'service_state' THEN
        IF NEW.source_type <> 'provisioning_operation'
           OR NEW.source_id IS NULL
           OR NEW.threshold_code NOT IN ('state_suspended','state_deleted') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service state notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(CASE operation_row.operation_type
                   WHEN 'suspend' THEN 'state_suspended'
                   WHEN 'delete' THEN 'state_deleted'
                   ELSE NULL
               END),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-state-cycle-v1',
                   service_row.id,
                   operation_row.id,
                   operation_row.operation_generation,
                   service_row.lifecycle_version
               ), 256))
          INTO valid_source_count, expected_threshold, expected_cycle
        FROM provisioning_operations operation_row
        JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
        WHERE operation_row.id = NEW.source_id
          AND operation_row.service_subscription_id = NEW.service_subscription_id
          AND operation_row.operation_type IN ('suspend','delete')
          AND operation_row.state = 'succeeded'
          AND operation_row.operation_generation = service_row.mutation_generation
          AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
          AND operation_row.target_lifecycle_version + 1 = service_row.lifecycle_version
          AND (
              (operation_row.operation_type = 'suspend' AND service_row.lifecycle_state = 'suspended' AND service_row.remote_deleted_at IS NULL)
              OR (operation_row.operation_type = 'delete' AND service_row.lifecycle_state = 'retired' AND service_row.remote_deleted_at IS NOT NULL)
          );
        IF BINARY NEW.threshold_code <> BINARY COALESCE(expected_threshold, '') THEN
            SET valid_source_count = 0;
        END IF;
    ELSEIF NEW.notification_type = 'sync_issue' THEN
        IF NEW.source_type <> 'service_sync_anomaly'
           OR NEW.source_id IS NULL
           OR NEW.threshold_code <> 'sync_issue' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service sync issue notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-sync-issue-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version
               ), 256))
          INTO valid_source_count, expected_cycle
        FROM service_sync_anomalies anomaly_row
        JOIN service_subscriptions service_row ON service_row.id = anomaly_row.service_subscription_id
        WHERE anomaly_row.id = NEW.source_id
          AND anomaly_row.service_subscription_id = NEW.service_subscription_id
          AND anomaly_row.severity IN ('warning','critical')
          AND anomaly_row.state IN ('open','manual_review','action_requested')
          AND BINARY anomaly_row.anomaly_key = BINARY SHA2(CONCAT_WS('|',
              'service-sync-anomaly-v1',
              service_row.id,
              anomaly_row.classification,
              service_row.remote_identity_generation,
              service_row.mutation_generation,
              service_row.lifecycle_version
          ), 256);
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification type authority is invalid.';
    END IF;

    IF valid_source_count <> 1
       OR BINARY NEW.cycle_key_hash <> BINARY COALESCE(expected_cycle, '')
       OR BINARY NEW.episode_key_hash <> BINARY SHA2(CONCAT_WS('|',
           'service-notification-episode-v1',
           NEW.service_subscription_id,
           NEW.notification_type,
           NEW.threshold_code,
           NEW.cycle_key_hash
       ), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification source evidence is invalid.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function updateGuardV2(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_update_guard
BEFORE UPDATE ON service_notification_states
FOR EACH ROW
BEGIN
    DECLARE valid_terminal_count INT DEFAULT 0;
    DECLARE current_source_count INT DEFAULT 0;
    DECLARE current_remote_expires_at DATETIME(6) DEFAULT NULL;
    DECLARE wallet_credit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_debit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_active_holds BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_available BIGINT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR NEW.id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR BINARY OLD.episode_key_hash <> BINARY NEW.episode_key_hash
       OR BINARY OLD.notification_type <> BINARY NEW.notification_type
       OR BINARY OLD.threshold_code <> BINARY NEW.threshold_code
       OR BINARY OLD.cycle_key_hash <> BINARY NEW.cycle_key_hash
       OR BINARY OLD.source_type <> BINARY NEW.source_type
       OR NOT (OLD.source_id <=> NEW.source_id)
       OR NOT (OLD.low_balance_threshold_irr <=> NEW.low_balance_threshold_irr)
       OR NOT (OLD.expiry_snapshot_max_age_seconds <=> NEW.expiry_snapshot_max_age_seconds)
       OR NOT (OLD.sync_snapshot_max_age_seconds <=> NEW.sync_snapshot_max_age_seconds)
       OR OLD.max_retries <> NEW.max_retries
       OR OLD.triggered_at <> NEW.triggered_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state identity is immutable.';
    END IF;

    IF COALESCE(@app_service_notification_authority, '') = 'service_notification_bind_v1' THEN
        IF OLD.state <> 'triggered' OR NEW.state <> 'triggered'
           OR NEW.latest_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
           OR NEW.latest_retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
           OR NEW.next_retry_at IS NOT NULL
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.acknowledged_at <=> NEW.acknowledged_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at)
           OR (OLD.latest_retry_ordinal IS NULL AND NEW.latest_retry_ordinal <> 0)
           OR (OLD.latest_retry_ordinal IS NOT NULL AND NEW.latest_retry_ordinal <> OLD.latest_retry_ordinal + 1)
           OR NEW.latest_retry_ordinal > OLD.max_retries
           OR NEW.updated_at < OLD.updated_at THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding transition is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_schedule_retry_v1' THEN
        IF OLD.state <> 'triggered' OR NEW.state <> 'triggered'
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at <> @app_service_notification_next_retry_at
           OR NEW.next_retry_at IS NULL
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.acknowledged_at <=> NEW.acknowledged_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at)
           OR NEW.updated_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification retry schedule authority is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_terminal_v2' THEN
        IF BINARY OLD.state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR BINARY NEW.state <> BINARY COALESCE(@app_service_notification_to_state, '')
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at IS NOT NULL
           OR NEW.updated_at <> @app_service_notification_timestamp
           OR NOT (
               (OLD.state = 'triggered' AND NEW.state = 'notified'
                   AND COALESCE(@app_service_notification_transition_cause, '') = 'delivery_succeeded'
                   AND OLD.notified_at IS NULL
                   AND NEW.notified_at = @app_service_notification_timestamp
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.escalated_at <=> NEW.escalated_at)
                   AND (OLD.expired_at <=> NEW.expired_at))
               OR (OLD.state = 'triggered' AND NEW.state = 'escalated'
                   AND COALESCE(@app_service_notification_transition_cause, '') IN (
                       'delivery_uncertain','provider_retry_fenced','retry_exhausted','outbox_review_required'
                   )
                   AND OLD.escalated_at IS NULL
                   AND NEW.escalated_at = @app_service_notification_timestamp
                   AND (OLD.notified_at <=> NEW.notified_at)
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.expired_at <=> NEW.expired_at))
               OR (OLD.state = 'triggered' AND NEW.state = 'expired'
                   AND COALESCE(@app_service_notification_transition_cause, '') = 'source_invalidated'
                   AND OLD.expired_at IS NULL
                   AND NEW.expired_at = @app_service_notification_timestamp
                   AND (OLD.notified_at <=> NEW.notified_at)
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.escalated_at <=> NEW.escalated_at))
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification exact state transition authority is invalid.';
        END IF;
        IF NEW.state = 'notified' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN service_delivery_effects effect_row ON effect_row.service_delivery_attempt_id = attempt_row.id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND effect_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY effect_row.state = BINARY 'succeeded'
              AND effect_row.completed_at IS NOT NULL
              AND effect_row.telegram_message_id IS NOT NULL
              AND BINARY effect_row.result_code = BINARY 'telegram_success';
        ELSEIF NEW.state = 'escalated' AND COALESCE(@app_service_notification_transition_cause, '') = 'outbox_review_required' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN outbox_messages outbox_row ON outbox_row.id = attempt_row.outbox_event_id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND BINARY outbox_row.dispatch_state = BINARY 'review_required';
        ELSEIF NEW.state = 'escalated' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN service_delivery_effects effect_row ON effect_row.service_delivery_attempt_id = attempt_row.id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND effect_row.service_subscription_id = OLD.service_subscription_id
              AND effect_row.completed_at IS NOT NULL
              AND (
                  (COALESCE(@app_service_notification_transition_cause, '') = 'delivery_uncertain'
                      AND BINARY effect_row.state = BINARY 'uncertain')
                  OR (COALESCE(@app_service_notification_transition_cause, '') = 'provider_retry_fenced'
                      AND BINARY effect_row.state = BINARY 'failed_final' AND effect_row.retry_after_seconds IS NOT NULL)
                  OR (COALESCE(@app_service_notification_transition_cause, '') = 'retry_exhausted'
                      AND BINARY effect_row.state = BINARY 'failed_final'
                      AND effect_row.retry_after_seconds IS NULL
                      AND OLD.latest_retry_ordinal IS NOT NULL
                      AND OLD.latest_retry_ordinal = OLD.max_retries)
              );
        ELSEIF NEW.state = 'expired' THEN
            IF OLD.notification_type = 'low_balance' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_subscriptions service_row
                JOIN ledger_accounts wallet_row ON wallet_row.id = OLD.source_id
                WHERE service_row.id = OLD.service_subscription_id
                  AND service_row.provisioned_at IS NOT NULL
                  AND service_row.remote_deleted_at IS NULL
                  AND service_row.lifecycle_state IN ('active','suspended')
                  AND wallet_row.owner_user_id = service_row.user_id
                  AND wallet_row.wallet_bucket = 'cash'
                  AND wallet_row.account_class = 'liability'
                  AND wallet_row.currency = 'IRR'
                  AND wallet_row.is_active = 1;
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                ELSE
                    SELECT COALESCE(SUM(CASE WHEN entry_row.direction = 'credit' THEN entry_row.amount_irr ELSE 0 END), 0),
                           COALESCE(SUM(CASE WHEN entry_row.direction = 'debit' THEN entry_row.amount_irr ELSE 0 END), 0)
                      INTO wallet_credit, wallet_debit
                    FROM ledger_entries entry_row
                    JOIN ledger_transactions transaction_row ON transaction_row.id = entry_row.ledger_transaction_id
                    WHERE entry_row.ledger_account_id = OLD.source_id
                      AND transaction_row.finalized_at IS NOT NULL;
                    SELECT COALESCE(SUM(hold_row.amount_irr), 0) INTO wallet_active_holds
                    FROM wallet_holds hold_row
                    WHERE hold_row.ledger_account_id = OLD.source_id AND hold_row.status = 'active';
                    IF wallet_debit <= wallet_credit
                       AND wallet_active_holds <= wallet_credit - wallet_debit THEN
                        SET wallet_available = wallet_credit - wallet_debit - wallet_active_holds;
                        IF wallet_available >= OLD.low_balance_threshold_irr THEN
                            SET valid_terminal_count = 1;
                        END IF;
                    END IF;
                END IF;
            ELSEIF OLD.notification_type = 'renewal_failure' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_auto_renew_notification_intents intent_row
                JOIN service_auto_renew_attempts attempt_row ON attempt_row.id = intent_row.auto_renew_attempt_id
                WHERE intent_row.id = OLD.source_id
                  AND attempt_row.service_subscription_id = OLD.service_subscription_id
                  AND BINARY CONCAT('renewal_', intent_row.outcome) = BINARY OLD.threshold_code
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-renewal-cycle-v1',
                      attempt_row.service_subscription_id,
                      intent_row.id,
                      intent_row.outcome,
                      COALESCE(intent_row.reason_code, '')
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND (
                      (intent_row.outcome = 'insufficient_wallet' AND attempt_row.state = 'insufficient_wallet')
                      OR (intent_row.outcome = 'price_change_blocked' AND attempt_row.state = 'price_change_blocked')
                      OR (intent_row.outcome = 'failure' AND attempt_row.state = 'failed')
                  );
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                END IF;
            ELSEIF OLD.notification_type = 'usage' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_sync_snapshots snapshot_row
                JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
                WHERE snapshot_row.service_subscription_id = OLD.service_subscription_id
                  AND snapshot_row.remote_disposition = 'present'
                  AND snapshot_row.remote_data_limit_bytes IS NOT NULL
                  AND snapshot_row.remote_data_limit_bytes > 0
                  AND snapshot_row.remote_used_bytes IS NOT NULL
                  AND OLD.sync_snapshot_max_age_seconds BETWEEN 60 AND 86400
                  AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                  AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                  AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                  AND service_row.provisioned_at IS NOT NULL
                  AND service_row.remote_deleted_at IS NULL
                  AND service_row.lifecycle_state IN ('active','suspended')
                  AND snapshot_row.observed_at <= @app_service_notification_timestamp
                  AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -OLD.sync_snapshot_max_age_seconds, @app_service_notification_timestamp)
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-usage-cycle-v1',
                      service_row.id,
                      service_row.remote_identity_generation,
                      service_row.mutation_generation,
                      service_row.lifecycle_version,
                      snapshot_row.remote_data_limit_bytes
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND (
                      (OLD.threshold_code = 'usage_exhausted' AND snapshot_row.remote_used_bytes >= snapshot_row.remote_data_limit_bytes)
                      OR (OLD.threshold_code = 'usage_10pct'
                          AND snapshot_row.remote_used_bytes < snapshot_row.remote_data_limit_bytes
                          AND (GREATEST(CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) - CAST(snapshot_row.remote_used_bytes AS DECIMAL(30,0)), 0) * 100)
                              <= (CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) * 10))
                      OR (OLD.threshold_code = 'usage_20pct'
                          AND (GREATEST(CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) - CAST(snapshot_row.remote_used_bytes AS DECIMAL(30,0)), 0) * 100)
                              <= (CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) * 20)
                          AND (GREATEST(CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) - CAST(snapshot_row.remote_used_bytes AS DECIMAL(30,0)), 0) * 100)
                              > (CAST(snapshot_row.remote_data_limit_bytes AS DECIMAL(30,0)) * 10))
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM service_sync_snapshots newer_snapshot
                      WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                        AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                        AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                        AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                        AND (newer_snapshot.observed_at > snapshot_row.observed_at
                            OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id))
                  );
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                END IF;
            ELSEIF OLD.notification_type = 'service_state' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM provisioning_operations operation_row
                JOIN service_subscriptions service_row ON service_row.id = operation_row.service_subscription_id
                WHERE operation_row.id = OLD.source_id
                  AND operation_row.service_subscription_id = OLD.service_subscription_id
                  AND operation_row.state = 'succeeded'
                  AND operation_row.operation_generation = service_row.mutation_generation
                  AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
                  AND operation_row.target_lifecycle_version + 1 = service_row.lifecycle_version
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-state-cycle-v1',
                      service_row.id,
                      operation_row.id,
                      operation_row.operation_generation,
                      service_row.lifecycle_version
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND (
                      (OLD.threshold_code = 'state_suspended' AND operation_row.operation_type = 'suspend'
                          AND service_row.lifecycle_state = 'suspended' AND service_row.remote_deleted_at IS NULL)
                      OR (OLD.threshold_code = 'state_deleted' AND operation_row.operation_type = 'delete'
                          AND service_row.lifecycle_state = 'retired' AND service_row.remote_deleted_at IS NOT NULL)
                  );
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                END IF;
            ELSEIF OLD.notification_type = 'sync_issue' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_sync_anomalies anomaly_row
                JOIN service_subscriptions service_row ON service_row.id = anomaly_row.service_subscription_id
                WHERE anomaly_row.id = OLD.source_id
                  AND anomaly_row.service_subscription_id = OLD.service_subscription_id
                  AND OLD.threshold_code = 'sync_issue'
                  AND anomaly_row.severity IN ('warning','critical')
                  AND anomaly_row.state IN ('open','manual_review','action_requested')
                  AND BINARY anomaly_row.anomaly_key = BINARY SHA2(CONCAT_WS('|',
                      'service-sync-anomaly-v1',
                      service_row.id,
                      anomaly_row.classification,
                      service_row.remote_identity_generation,
                      service_row.mutation_generation,
                      service_row.lifecycle_version
                  ), 256)
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-sync-issue-cycle-v1',
                      service_row.id,
                      service_row.remote_identity_generation,
                      service_row.mutation_generation,
                      service_row.lifecycle_version
                  ), 256) = BINARY OLD.cycle_key_hash;
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                END IF;
            ELSEIF OLD.notification_type = 'expiry' THEN
                SELECT COUNT(*), MAX(snapshot_row.remote_expires_at)
                  INTO current_source_count, current_remote_expires_at
                FROM service_sync_snapshots snapshot_row
                JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
                WHERE snapshot_row.service_subscription_id = OLD.service_subscription_id
                  AND snapshot_row.remote_disposition = 'present'
                  AND snapshot_row.remote_expires_at IS NOT NULL
                  AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                  AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                  AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                  AND service_row.provisioned_at IS NOT NULL
                  AND service_row.remote_deleted_at IS NULL
                  AND service_row.lifecycle_state IN ('active','suspended')
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-expiry-cycle-v1',
                      service_row.id,
                      service_row.remote_identity_generation,
                      service_row.mutation_generation,
                      service_row.lifecycle_version,
                      DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND NOT EXISTS (
                      SELECT 1 FROM service_sync_snapshots newer_snapshot
                      WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                        AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                        AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                        AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                        AND (newer_snapshot.observed_at > snapshot_row.observed_at
                            OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id))
                  );
                IF current_source_count = 0
                   OR (OLD.threshold_code = 'expiry_7d' AND current_remote_expires_at <= @app_service_notification_timestamp + INTERVAL 3 DAY)
                   OR (OLD.threshold_code = 'expiry_3d' AND current_remote_expires_at <= @app_service_notification_timestamp + INTERVAL 1 DAY)
                   OR (OLD.threshold_code = 'expiry_1d' AND current_remote_expires_at <= @app_service_notification_timestamp) THEN
                    SET valid_terminal_count = 1;
                END IF;
            END IF;
        END IF;
        IF valid_terminal_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification terminal transition causal evidence is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_acknowledge_v1' THEN
        SELECT COUNT(*) INTO valid_terminal_count
        FROM administrators administrator_row
        WHERE administrator_row.id = COALESCE(@app_service_notification_actor_administrator_id, 0)
          AND BINARY administrator_row.status = BINARY 'active';
        IF BINARY OLD.state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR OLD.state NOT IN ('notified','escalated') OR NEW.state <> 'acknowledged'
           OR COALESCE(@app_service_notification_transition_cause, '') <> 'administrator_acknowledgment'
           OR valid_terminal_count <> 1
           OR COALESCE(@app_service_notification_request_hash, '') NOT REGEXP '^[0-9a-f]{64}$'
           OR COALESCE(@app_service_notification_reason_code, '') = ''
           OR COALESCE(@app_service_notification_reason, '') = ''
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at IS NOT NULL
           OR NEW.acknowledged_at <> @app_service_notification_timestamp
           OR NEW.updated_at <> @app_service_notification_timestamp
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification acknowledgment authority is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state update authority is invalid.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function insertGuardV1(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_insert_guard
BEFORE INSERT ON service_notification_states
FOR EACH ROW
BEGIN
    DECLARE valid_source_count INT DEFAULT 0;
    DECLARE expected_cycle CHAR(64) DEFAULT NULL;
    DECLARE expected_threshold VARCHAR(64) DEFAULT NULL;
    DECLARE low_balance_threshold BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_credit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_debit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_active_holds BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_available BIGINT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_create_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.episode_key_hash <> BINARY COALESCE(@app_service_notification_episode_key, '')
       OR BINARY NEW.notification_type <> BINARY COALESCE(@app_service_notification_type, '')
       OR BINARY NEW.threshold_code <> BINARY COALESCE(@app_service_notification_threshold_code, '')
       OR BINARY NEW.cycle_key_hash <> BINARY COALESCE(@app_service_notification_cycle_key, '')
       OR BINARY NEW.source_type <> BINARY COALESCE(@app_service_notification_source_type, '')
       OR NOT (NEW.source_id <=> @app_service_notification_source_id)
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR NEW.triggered_at <> @app_service_notification_timestamp
       OR NOT (NEW.low_balance_threshold_irr <=> @app_service_notification_low_balance_threshold_irr)
       OR NOT (NEW.expiry_snapshot_max_age_seconds <=> @app_service_notification_expiry_snapshot_max_age_seconds)
       OR NEW.max_retries <> COALESCE(@app_service_notification_max_retries, 65535)
       OR NEW.updated_at <> @app_service_notification_timestamp
       OR NEW.state <> 'triggered'
       OR NEW.latest_delivery_attempt_id IS NOT NULL OR NEW.latest_retry_ordinal IS NOT NULL
       OR NEW.next_retry_at IS NOT NULL OR NEW.notified_at IS NOT NULL OR NEW.acknowledged_at IS NOT NULL
       OR NEW.escalated_at IS NOT NULL OR NEW.expired_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state creation authority is invalid.';
    END IF;

    IF NEW.notification_type = 'expiry' THEN
        IF NEW.source_type <> 'service_sync_snapshot'
           OR NEW.source_id IS NULL
           OR NEW.expiry_snapshot_max_age_seconds NOT BETWEEN 60 AND 86400
           OR NEW.threshold_code NOT IN ('expiry_due','expiry_1d','expiry_3d','expiry_7d') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service expiry notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-expiry-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version,
                   DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
               ), 256))
          INTO valid_source_count, expected_cycle
        FROM service_sync_snapshots snapshot_row
        JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
        WHERE snapshot_row.id = NEW.source_id
          AND snapshot_row.service_subscription_id = NEW.service_subscription_id
          AND snapshot_row.remote_disposition = 'present'
          AND snapshot_row.remote_expires_at IS NOT NULL
          AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
          AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
          AND snapshot_row.local_mutation_generation = service_row.mutation_generation
          AND snapshot_row.observed_at <= NEW.triggered_at
          AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -NEW.expiry_snapshot_max_age_seconds, NEW.triggered_at)
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND NOT EXISTS (
              SELECT 1
              FROM service_sync_snapshots newer_snapshot
              WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                AND (
                    newer_snapshot.observed_at > snapshot_row.observed_at
                    OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id)
                )
          );
    ELSEIF NEW.notification_type = 'renewal_failure' THEN
        IF NEW.source_type <> 'auto_renew_notification_intent' OR NEW.source_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service renewal notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(CONCAT('renewal_', intent_row.outcome)),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-renewal-cycle-v1',
                   attempt_row.service_subscription_id,
                   intent_row.id,
                   intent_row.outcome,
                   COALESCE(intent_row.reason_code, '')
               ), 256))
          INTO valid_source_count, expected_threshold, expected_cycle
        FROM service_auto_renew_notification_intents intent_row
        JOIN service_auto_renew_attempts attempt_row ON attempt_row.id = intent_row.auto_renew_attempt_id
        WHERE intent_row.id = NEW.source_id
          AND attempt_row.service_subscription_id = NEW.service_subscription_id
          AND (
              (intent_row.outcome = 'insufficient_wallet' AND attempt_row.state = 'insufficient_wallet')
              OR (intent_row.outcome = 'price_change_blocked' AND attempt_row.state = 'price_change_blocked')
              OR (intent_row.outcome = 'failure' AND attempt_row.state = 'failed')
          );
        IF BINARY NEW.threshold_code <> BINARY COALESCE(expected_threshold, '') THEN
            SET valid_source_count = 0;
        END IF;
    ELSEIF NEW.notification_type = 'low_balance' THEN
        SET low_balance_threshold = COALESCE(@app_service_notification_low_balance_threshold_irr, 0);
        IF NEW.source_type <> 'wallet_balance'
           OR NEW.source_id IS NULL OR NEW.source_id < 1
           OR NEW.threshold_code <> 'low_balance'
           OR low_balance_threshold < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service low-balance notification source shape is invalid.';
        END IF;

        SELECT COUNT(*),
               MAX(SHA2(CONCAT_WS('|',
                   'service-notification-low-balance-cycle-v1',
                   service_row.id,
                   service_row.remote_identity_generation,
                   service_row.mutation_generation,
                   service_row.lifecycle_version,
                   low_balance_threshold
               ), 256))
          INTO valid_source_count, expected_cycle
        FROM service_subscriptions service_row
        JOIN ledger_accounts wallet_row ON wallet_row.id = NEW.source_id
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.remote_deleted_at IS NULL
          AND service_row.lifecycle_state IN ('active','suspended')
          AND wallet_row.owner_user_id = service_row.user_id
          AND wallet_row.wallet_bucket = 'cash'
          AND wallet_row.account_class = 'liability'
          AND wallet_row.currency = 'IRR'
          AND wallet_row.is_active = 1;

        IF valid_source_count = 1 THEN
            SELECT COALESCE(SUM(CASE WHEN entry_row.direction = 'credit' THEN entry_row.amount_irr ELSE 0 END), 0),
                   COALESCE(SUM(CASE WHEN entry_row.direction = 'debit' THEN entry_row.amount_irr ELSE 0 END), 0)
              INTO wallet_credit, wallet_debit
            FROM ledger_entries entry_row
            JOIN ledger_transactions transaction_row ON transaction_row.id = entry_row.ledger_transaction_id
            WHERE entry_row.ledger_account_id = NEW.source_id
              AND transaction_row.finalized_at IS NOT NULL;

            SELECT COALESCE(SUM(hold_row.amount_irr), 0)
              INTO wallet_active_holds
            FROM wallet_holds hold_row
            WHERE hold_row.ledger_account_id = NEW.source_id
              AND hold_row.status = 'active';

            IF wallet_debit > wallet_credit THEN
                SET valid_source_count = 0;
            ELSEIF wallet_active_holds > wallet_credit - wallet_debit THEN
                SET valid_source_count = 0;
            ELSE
                SET wallet_available = wallet_credit - wallet_debit - wallet_active_holds;
                IF wallet_available >= low_balance_threshold THEN
                    SET valid_source_count = 0;
                END IF;
            END IF;
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification type authority is invalid.';
    END IF;

    IF valid_source_count <> 1
       OR BINARY NEW.cycle_key_hash <> BINARY COALESCE(expected_cycle, '')
       OR BINARY NEW.episode_key_hash <> BINARY SHA2(CONCAT_WS('|',
           'service-notification-episode-v1',
           NEW.service_subscription_id,
           NEW.notification_type,
           NEW.threshold_code,
           NEW.cycle_key_hash
       ), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification source evidence is invalid.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function updateGuardV1(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_update_guard
BEFORE UPDATE ON service_notification_states
FOR EACH ROW
BEGIN
    DECLARE valid_terminal_count INT DEFAULT 0;
    DECLARE current_source_count INT DEFAULT 0;
    DECLARE current_remote_expires_at DATETIME(6) DEFAULT NULL;
    DECLARE wallet_credit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_debit BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_active_holds BIGINT UNSIGNED DEFAULT 0;
    DECLARE wallet_available BIGINT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR NEW.id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_subscription_id <> COALESCE(@app_service_notification_service_id, 0)
       OR BINARY NEW.last_correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '')
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR BINARY OLD.episode_key_hash <> BINARY NEW.episode_key_hash
       OR BINARY OLD.notification_type <> BINARY NEW.notification_type
       OR BINARY OLD.threshold_code <> BINARY NEW.threshold_code
       OR BINARY OLD.cycle_key_hash <> BINARY NEW.cycle_key_hash
       OR BINARY OLD.source_type <> BINARY NEW.source_type
       OR NOT (OLD.source_id <=> NEW.source_id)
       OR NOT (OLD.low_balance_threshold_irr <=> NEW.low_balance_threshold_irr)
       OR NOT (OLD.expiry_snapshot_max_age_seconds <=> NEW.expiry_snapshot_max_age_seconds)
       OR OLD.max_retries <> NEW.max_retries
       OR OLD.triggered_at <> NEW.triggered_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state identity is immutable.';
    END IF;

    IF COALESCE(@app_service_notification_authority, '') = 'service_notification_bind_v1' THEN
        IF OLD.state <> 'triggered' OR NEW.state <> 'triggered'
           OR NEW.latest_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
           OR NEW.latest_retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
           OR NEW.next_retry_at IS NOT NULL
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.acknowledged_at <=> NEW.acknowledged_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at)
           OR (OLD.latest_retry_ordinal IS NULL AND NEW.latest_retry_ordinal <> 0)
           OR (OLD.latest_retry_ordinal IS NOT NULL AND NEW.latest_retry_ordinal <> OLD.latest_retry_ordinal + 1)
           OR NEW.latest_retry_ordinal > OLD.max_retries
           OR NEW.updated_at < OLD.updated_at THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding transition is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_schedule_retry_v1' THEN
        IF OLD.state <> 'triggered' OR NEW.state <> 'triggered'
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at <> @app_service_notification_next_retry_at
           OR NEW.next_retry_at IS NULL
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.acknowledged_at <=> NEW.acknowledged_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at)
           OR NEW.updated_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification retry schedule authority is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_terminal_v2' THEN
        IF BINARY OLD.state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR BINARY NEW.state <> BINARY COALESCE(@app_service_notification_to_state, '')
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at IS NOT NULL
           OR NEW.updated_at <> @app_service_notification_timestamp
           OR NOT (
               (OLD.state = 'triggered' AND NEW.state = 'notified'
                   AND COALESCE(@app_service_notification_transition_cause, '') = 'delivery_succeeded'
                   AND OLD.notified_at IS NULL
                   AND NEW.notified_at = @app_service_notification_timestamp
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.escalated_at <=> NEW.escalated_at)
                   AND (OLD.expired_at <=> NEW.expired_at))
               OR (OLD.state = 'triggered' AND NEW.state = 'escalated'
                   AND COALESCE(@app_service_notification_transition_cause, '') IN (
                       'delivery_uncertain','provider_retry_fenced','retry_exhausted','outbox_review_required'
                   )
                   AND OLD.escalated_at IS NULL
                   AND NEW.escalated_at = @app_service_notification_timestamp
                   AND (OLD.notified_at <=> NEW.notified_at)
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.expired_at <=> NEW.expired_at))
               OR (OLD.state = 'triggered' AND NEW.state = 'expired'
                   AND COALESCE(@app_service_notification_transition_cause, '') = 'source_invalidated'
                   AND OLD.expired_at IS NULL
                   AND NEW.expired_at = @app_service_notification_timestamp
                   AND (OLD.notified_at <=> NEW.notified_at)
                   AND (OLD.acknowledged_at <=> NEW.acknowledged_at)
                   AND (OLD.escalated_at <=> NEW.escalated_at))
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification exact state transition authority is invalid.';
        END IF;
        IF NEW.state = 'notified' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN service_delivery_effects effect_row ON effect_row.service_delivery_attempt_id = attempt_row.id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND effect_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY effect_row.state = BINARY 'succeeded'
              AND effect_row.completed_at IS NOT NULL
              AND effect_row.telegram_message_id IS NOT NULL
              AND BINARY effect_row.result_code = BINARY 'telegram_success';
        ELSEIF NEW.state = 'escalated' AND COALESCE(@app_service_notification_transition_cause, '') = 'outbox_review_required' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN outbox_messages outbox_row ON outbox_row.id = attempt_row.outbox_event_id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND BINARY outbox_row.dispatch_state = BINARY 'review_required';
        ELSEIF NEW.state = 'escalated' THEN
            SELECT COUNT(*) INTO valid_terminal_count
            FROM service_notification_delivery_bindings binding_row
            JOIN service_delivery_attempts attempt_row ON attempt_row.id = binding_row.service_delivery_attempt_id
            JOIN service_delivery_effects effect_row ON effect_row.service_delivery_attempt_id = attempt_row.id
            WHERE binding_row.service_notification_state_id = OLD.id
              AND binding_row.service_delivery_attempt_id = OLD.latest_delivery_attempt_id
              AND binding_row.retry_ordinal = OLD.latest_retry_ordinal
              AND attempt_row.service_subscription_id = OLD.service_subscription_id
              AND BINARY attempt_row.purpose = BINARY 'notification'
              AND effect_row.service_subscription_id = OLD.service_subscription_id
              AND effect_row.completed_at IS NOT NULL
              AND (
                  (COALESCE(@app_service_notification_transition_cause, '') = 'delivery_uncertain'
                      AND BINARY effect_row.state = BINARY 'uncertain')
                  OR (COALESCE(@app_service_notification_transition_cause, '') = 'provider_retry_fenced'
                      AND BINARY effect_row.state = BINARY 'failed_final' AND effect_row.retry_after_seconds IS NOT NULL)
                  OR (COALESCE(@app_service_notification_transition_cause, '') = 'retry_exhausted'
                      AND BINARY effect_row.state = BINARY 'failed_final'
                      AND effect_row.retry_after_seconds IS NULL
                      AND OLD.latest_retry_ordinal IS NOT NULL
                      AND OLD.latest_retry_ordinal = OLD.max_retries)
              );
        ELSEIF NEW.state = 'expired' THEN
            IF OLD.notification_type = 'low_balance' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_subscriptions service_row
                JOIN ledger_accounts wallet_row ON wallet_row.id = OLD.source_id
                WHERE service_row.id = OLD.service_subscription_id
                  AND service_row.provisioned_at IS NOT NULL
                  AND service_row.remote_deleted_at IS NULL
                  AND service_row.lifecycle_state IN ('active','suspended')
                  AND wallet_row.owner_user_id = service_row.user_id
                  AND wallet_row.wallet_bucket = 'cash'
                  AND wallet_row.account_class = 'liability'
                  AND wallet_row.currency = 'IRR'
                  AND wallet_row.is_active = 1;
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                ELSE
                    SELECT COALESCE(SUM(CASE WHEN entry_row.direction = 'credit' THEN entry_row.amount_irr ELSE 0 END), 0),
                           COALESCE(SUM(CASE WHEN entry_row.direction = 'debit' THEN entry_row.amount_irr ELSE 0 END), 0)
                      INTO wallet_credit, wallet_debit
                    FROM ledger_entries entry_row
                    JOIN ledger_transactions transaction_row ON transaction_row.id = entry_row.ledger_transaction_id
                    WHERE entry_row.ledger_account_id = OLD.source_id
                      AND transaction_row.finalized_at IS NOT NULL;
                    SELECT COALESCE(SUM(hold_row.amount_irr), 0) INTO wallet_active_holds
                    FROM wallet_holds hold_row
                    WHERE hold_row.ledger_account_id = OLD.source_id AND hold_row.status = 'active';
                    IF wallet_debit <= wallet_credit
                       AND wallet_active_holds <= wallet_credit - wallet_debit THEN
                        SET wallet_available = wallet_credit - wallet_debit - wallet_active_holds;
                        IF wallet_available >= OLD.low_balance_threshold_irr THEN
                            SET valid_terminal_count = 1;
                        END IF;
                    END IF;
                END IF;
            ELSEIF OLD.notification_type = 'renewal_failure' THEN
                SELECT COUNT(*) INTO current_source_count
                FROM service_auto_renew_notification_intents intent_row
                JOIN service_auto_renew_attempts attempt_row ON attempt_row.id = intent_row.auto_renew_attempt_id
                WHERE intent_row.id = OLD.source_id
                  AND attempt_row.service_subscription_id = OLD.service_subscription_id
                  AND BINARY CONCAT('renewal_', intent_row.outcome) = BINARY OLD.threshold_code
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-renewal-cycle-v1',
                      attempt_row.service_subscription_id,
                      intent_row.id,
                      intent_row.outcome,
                      COALESCE(intent_row.reason_code, '')
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND (
                      (intent_row.outcome = 'insufficient_wallet' AND attempt_row.state = 'insufficient_wallet')
                      OR (intent_row.outcome = 'price_change_blocked' AND attempt_row.state = 'price_change_blocked')
                      OR (intent_row.outcome = 'failure' AND attempt_row.state = 'failed')
                  );
                IF current_source_count = 0 THEN
                    SET valid_terminal_count = 1;
                END IF;
            ELSEIF OLD.notification_type = 'expiry' THEN
                SELECT COUNT(*), MAX(snapshot_row.remote_expires_at)
                  INTO current_source_count, current_remote_expires_at
                FROM service_sync_snapshots snapshot_row
                JOIN service_subscriptions service_row ON service_row.id = snapshot_row.service_subscription_id
                WHERE snapshot_row.service_subscription_id = OLD.service_subscription_id
                  AND snapshot_row.remote_disposition = 'present'
                  AND snapshot_row.remote_expires_at IS NOT NULL
                  AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                  AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                  AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                  AND service_row.provisioned_at IS NOT NULL
                  AND service_row.remote_deleted_at IS NULL
                  AND service_row.lifecycle_state IN ('active','suspended')
                  AND BINARY SHA2(CONCAT_WS('|',
                      'service-notification-expiry-cycle-v1',
                      service_row.id,
                      service_row.remote_identity_generation,
                      service_row.mutation_generation,
                      service_row.lifecycle_version,
                      DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
                  ), 256) = BINARY OLD.cycle_key_hash
                  AND NOT EXISTS (
                      SELECT 1 FROM service_sync_snapshots newer_snapshot
                      WHERE newer_snapshot.service_subscription_id = snapshot_row.service_subscription_id
                        AND newer_snapshot.local_lifecycle_version = snapshot_row.local_lifecycle_version
                        AND newer_snapshot.local_remote_identity_generation = snapshot_row.local_remote_identity_generation
                        AND newer_snapshot.local_mutation_generation = snapshot_row.local_mutation_generation
                        AND (newer_snapshot.observed_at > snapshot_row.observed_at
                            OR (newer_snapshot.observed_at = snapshot_row.observed_at AND newer_snapshot.id > snapshot_row.id))
                  );
                IF current_source_count = 0
                   OR (OLD.threshold_code = 'expiry_7d' AND current_remote_expires_at <= @app_service_notification_timestamp + INTERVAL 3 DAY)
                   OR (OLD.threshold_code = 'expiry_3d' AND current_remote_expires_at <= @app_service_notification_timestamp + INTERVAL 1 DAY)
                   OR (OLD.threshold_code = 'expiry_1d' AND current_remote_expires_at <= @app_service_notification_timestamp) THEN
                    SET valid_terminal_count = 1;
                END IF;
            END IF;
        END IF;
        IF valid_terminal_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification terminal transition causal evidence is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_acknowledge_v1' THEN
        SELECT COUNT(*) INTO valid_terminal_count
        FROM administrators administrator_row
        WHERE administrator_row.id = COALESCE(@app_service_notification_actor_administrator_id, 0)
          AND BINARY administrator_row.status = BINARY 'active';
        IF BINARY OLD.state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR OLD.state NOT IN ('notified','escalated') OR NEW.state <> 'acknowledged'
           OR COALESCE(@app_service_notification_transition_cause, '') <> 'administrator_acknowledgment'
           OR valid_terminal_count <> 1
           OR COALESCE(@app_service_notification_request_hash, '') NOT REGEXP '^[0-9a-f]{64}$'
           OR COALESCE(@app_service_notification_reason_code, '') = ''
           OR COALESCE(@app_service_notification_reason, '') = ''
           OR NOT (OLD.latest_delivery_attempt_id <=> NEW.latest_delivery_attempt_id)
           OR NOT (OLD.latest_retry_ordinal <=> NEW.latest_retry_ordinal)
           OR NEW.next_retry_at IS NOT NULL
           OR NEW.acknowledged_at <> @app_service_notification_timestamp
           OR NEW.updated_at <> @app_service_notification_timestamp
           OR NOT (OLD.notified_at <=> NEW.notified_at)
           OR NOT (OLD.escalated_at <=> NEW.escalated_at)
           OR NOT (OLD.expired_at <=> NEW.expired_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification acknowledgment authority is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state update authority is invalid.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function deliveryAttemptInsertGuardV2(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_attempts_insert_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    DECLARE valid_service_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_terminal_notification_count INT DEFAULT 0;
    DECLARE valid_outbox_count INT DEFAULT 0;
    DECLARE unresolved_mutation_count INT DEFAULT 0;

    IF COALESCE(@app_service_delivery_authority, '') <> 'service_delivery_queue_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_delivery_service_id, 0)
       OR BINARY NEW.purpose <> BINARY COALESCE(@app_service_delivery_purpose, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_delivery_request_hash, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_delivery_correlation_id, '')
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_service_delivery_attempt_public_id, '')
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_service_delivery_outbox_event_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt creation authority is invalid.';
    END IF;

    SELECT service_row.id INTO valid_service_id
    FROM service_subscriptions service_row
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.service_target_id IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND CHAR_LENGTH(service_row.remote_service_id) > 0
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
      AND service_row.lifecycle_version = NEW.target_lifecycle_version
    LIMIT 1 FOR UPDATE;

    IF valid_service_id IS NULL AND BINARY NEW.purpose = BINARY 'notification' THEN
        SELECT COUNT(*) INTO valid_terminal_notification_count
        FROM service_notification_states state_row
        INNER JOIN provisioning_operations operation_row ON operation_row.id = state_row.source_id
        INNER JOIN service_subscriptions service_row ON service_row.id = state_row.service_subscription_id
        WHERE state_row.service_subscription_id = NEW.service_subscription_id
          AND state_row.state = 'triggered'
          AND state_row.notification_type = 'service_state'
          AND state_row.threshold_code = 'state_deleted'
          AND state_row.source_type = 'provisioning_operation'
          AND state_row.source_id IS NOT NULL
          AND operation_row.service_subscription_id = service_row.id
          AND operation_row.operation_type = 'delete'
          AND operation_row.state = 'succeeded'
          AND operation_row.operation_generation = service_row.mutation_generation
          AND operation_row.target_remote_identity_generation = service_row.remote_identity_generation
          AND operation_row.target_lifecycle_version + 1 = service_row.lifecycle_version
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.service_target_id IS NOT NULL
          AND service_row.remote_service_id IS NOT NULL
          AND CHAR_LENGTH(service_row.remote_service_id) > 0
          AND service_row.remote_deleted_at IS NOT NULL
          AND service_row.lifecycle_state = 'retired'
          AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
          AND service_row.lifecycle_version = NEW.target_lifecycle_version
          AND COALESCE(state_row.latest_retry_ordinal + 1, 0) <= state_row.max_retries
          AND BINARY state_row.cycle_key_hash = BINARY SHA2(CONCAT_WS('|',
              'service-notification-state-cycle-v1',
              service_row.id,
              operation_row.id,
              operation_row.operation_generation,
              service_row.lifecycle_version
          ), 256)
          AND BINARY state_row.episode_key_hash = BINARY SHA2(CONCAT_WS('|',
              'service-notification-episode-v1',
              service_row.id,
              state_row.notification_type,
              state_row.threshold_code,
              state_row.cycle_key_hash
          ), 256)
          AND BINARY NEW.request_key_hash = BINARY SHA2(CONCAT(
              'service-notification:',
              state_row.public_id,
              ':',
              COALESCE(state_row.latest_retry_ordinal + 1, 0)
          ), 256)
          AND BINARY NEW.correlation_id = BINARY CONCAT(
              'service-notification:',
              state_row.public_id,
              ':',
              COALESCE(state_row.latest_retry_ordinal + 1, 0)
          );
    END IF;

    IF valid_service_id IS NULL AND valid_terminal_notification_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt must match the current provisioned Service identity and lifecycle.';
    END IF;

    SELECT COUNT(*) INTO unresolved_mutation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.service_subscription_id = NEW.service_subscription_id
      AND operation_row.operation_type <> 'initial_provision'
      AND operation_row.state NOT IN ('succeeded','failed_final','compensated');

    IF unresolved_mutation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt is blocked by an unresolved Service mutation.';
    END IF;

    SELECT COUNT(*) INTO valid_outbox_count
    FROM outbox_messages outbox_row
    WHERE BINARY outbox_row.id = BINARY NEW.outbox_event_id
      AND BINARY outbox_row.event_type = BINARY 'provisioning.service_delivery.requested'
      AND BINARY outbox_row.event_key = BINARY CONCAT('provisioning-service-delivery-requested:', NEW.public_id)
      AND BINARY outbox_row.aggregate_type = BINARY 'service_delivery_attempt'
      AND BINARY outbox_row.aggregate_id = BINARY NEW.public_id
      AND BINARY outbox_row.correlation_id = BINARY NEW.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL
      AND outbox_row.lease_token IS NULL
      AND outbox_row.leased_until IS NULL
      AND outbox_row.attempts = 0
      AND outbox_row.review_reason IS NULL
      AND outbox_row.last_error_class IS NULL
      AND outbox_row.last_error_code IS NULL
      AND COALESCE(JSON_TYPE(outbox_row.payload), '') = 'OBJECT'
      AND COALESCE(JSON_LENGTH(outbox_row.payload), -1) = 1
      AND BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.service_delivery_attempt_public_id')), '') = BINARY NEW.public_id
      AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT('{"service_delivery_attempt_public_id":"', NEW.public_id, '"}'))
      AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

    IF valid_outbox_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt requires one exact quarantined Outbox command.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function deliveryAttemptInsertGuardV1(): string
    {
        return <<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_attempts_insert_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    DECLARE valid_service_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE valid_outbox_count INT DEFAULT 0;
    DECLARE unresolved_mutation_count INT DEFAULT 0;

    IF COALESCE(@app_service_delivery_authority, '') <> 'service_delivery_queue_v1'
       OR NEW.service_subscription_id <> COALESCE(@app_service_delivery_service_id, 0)
       OR BINARY NEW.purpose <> BINARY COALESCE(@app_service_delivery_purpose, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_service_delivery_request_hash, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_delivery_correlation_id, '')
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_service_delivery_attempt_public_id, '')
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_service_delivery_outbox_event_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt creation authority is invalid.';
    END IF;

    SELECT service_row.id INTO valid_service_id
    FROM service_subscriptions service_row
    WHERE service_row.id = NEW.service_subscription_id
      AND service_row.provisioned_at IS NOT NULL
      AND service_row.service_target_id IS NOT NULL
      AND service_row.remote_service_id IS NOT NULL
      AND CHAR_LENGTH(service_row.remote_service_id) > 0
      AND service_row.remote_deleted_at IS NULL
      AND service_row.lifecycle_state IN ('active','suspended')
      AND service_row.remote_identity_generation = NEW.target_remote_identity_generation
      AND service_row.lifecycle_version = NEW.target_lifecycle_version
    LIMIT 1 FOR UPDATE;

    IF valid_service_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt must match the current provisioned Service identity and lifecycle.';
    END IF;

    SELECT COUNT(*) INTO unresolved_mutation_count
    FROM provisioning_operations operation_row
    WHERE operation_row.service_subscription_id = NEW.service_subscription_id
      AND operation_row.operation_type <> 'initial_provision'
      AND operation_row.state NOT IN ('succeeded','failed_final','compensated');

    IF unresolved_mutation_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt is blocked by an unresolved Service mutation.';
    END IF;

    SELECT COUNT(*) INTO valid_outbox_count
    FROM outbox_messages outbox_row
    WHERE BINARY outbox_row.id = BINARY NEW.outbox_event_id
      AND BINARY outbox_row.event_type = BINARY 'provisioning.service_delivery.requested'
      AND BINARY outbox_row.event_key = BINARY CONCAT('provisioning-service-delivery-requested:', NEW.public_id)
      AND BINARY outbox_row.aggregate_type = BINARY 'service_delivery_attempt'
      AND BINARY outbox_row.aggregate_id = BINARY NEW.public_id
      AND BINARY outbox_row.correlation_id = BINARY NEW.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL
      AND outbox_row.lease_token IS NULL
      AND outbox_row.leased_until IS NULL
      AND outbox_row.attempts = 0
      AND outbox_row.review_reason IS NULL
      AND outbox_row.last_error_class IS NULL
      AND outbox_row.last_error_code IS NULL
      AND COALESCE(JSON_TYPE(outbox_row.payload), '') = 'OBJECT'
      AND COALESCE(JSON_LENGTH(outbox_row.payload), -1) = 1
      AND BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.service_delivery_attempt_public_id')), '') = BINARY NEW.public_id
      AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT('{"service_delivery_attempt_public_id":"', NEW.public_id, '"}'))
      AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

    IF valid_outbox_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service Delivery Attempt requires one exact quarantined Outbox command.';
    END IF;
END
SQL;
    }
};
