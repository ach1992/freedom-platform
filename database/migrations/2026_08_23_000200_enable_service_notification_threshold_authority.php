<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement SVC-013 SVC-014 ARCH-003 ARCH-004 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        foreach ([
            'service_subscriptions',
            'service_delivery_attempts',
            'service_delivery_effects',
            'service_sync_snapshots',
            'service_auto_renew_notification_intents',
            'ledger_accounts',
            'ledger_entries',
            'ledger_transactions',
            'wallet_holds',
            'service_operational_authority_capability',
            'administrators',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Service notification authority requires the accepted Service, delivery, synchronization, renewal, and Wallet foundations.');
            }
        }

        $this->resetInterruptedInstallIfSafe();
        $this->installDeliveryPurposeConstraint();

        Schema::create('service_notification_states', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique('sns_public_uq');
            $table->foreignId('service_subscription_id');
            $table->foreign('service_subscription_id', 'sns_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
            $table->char('episode_key_hash', 64)->unique('sns_episode_uq');
            $table->string('notification_type', 32);
            $table->string('threshold_code', 64);
            $table->char('cycle_key_hash', 64);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('low_balance_threshold_irr')->nullable();
            $table->unsignedInteger('expiry_snapshot_max_age_seconds')->nullable();
            $table->unsignedSmallInteger('max_retries');
            $table->string('state', 24);
            $table->foreignId('latest_delivery_attempt_id')->nullable();
            $table->foreign('latest_delivery_attempt_id', 'sns_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('latest_retry_ordinal')->nullable();
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->dateTime('triggered_at', 6);
            $table->dateTime('notified_at', 6)->nullable();
            $table->dateTime('acknowledged_at', 6)->nullable();
            $table->dateTime('escalated_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->string('last_correlation_id', 64);
            $table->dateTime('updated_at', 6);
            $table->index(['service_subscription_id', 'state', 'updated_at'], 'sns_service_state_idx');
            $table->index(['notification_type', 'state', 'next_retry_at'], 'sns_type_retry_idx');
        });

        Schema::create('service_notification_delivery_bindings', function (Blueprint $table): void {
            $table->foreignId('service_delivery_attempt_id')->primary();
            $table->foreign('service_delivery_attempt_id', 'sndb_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->foreignId('service_notification_state_id');
            $table->foreign('service_notification_state_id', 'sndb_state_fk')->references('id')->on('service_notification_states')->restrictOnDelete();
            $table->unsignedSmallInteger('retry_ordinal');
            $table->text('presentation_text');
            $table->char('presentation_hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['service_notification_state_id', 'retry_ordinal'], 'sndb_state_retry_uq');
        });

        Schema::create('service_notification_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('service_notification_state_id');
            $table->foreign('service_notification_state_id', 'sne_state_fk')->references('id')->on('service_notification_states')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event_type', 32);
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24);
            $table->foreignId('service_delivery_attempt_id')->nullable();
            $table->foreign('service_delivery_attempt_id', 'sne_attempt_fk')->references('id')->on('service_delivery_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('retry_ordinal')->nullable();
            $table->string('transition_cause', 32)->nullable();
            $table->foreignId('actor_administrator_id')->nullable();
            $table->foreign('actor_administrator_id', 'sne_actor_fk')->references('id')->on('administrators')->restrictOnDelete();
            $table->char('request_hash', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['service_notification_state_id', 'sequence'], 'sne_state_sequence_uq');
            $table->unique(['service_notification_state_id', 'request_hash'], 'sne_state_request_uq');
        });

        $this->installConstraints();
        $this->installGuards();
        $this->installNotificationDeliveryCapabilityGuards();
    }

    public function down(): void
    {
        foreach ([
            'service_notification_events',
            'service_notification_delivery_bindings',
            'service_notification_states',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Service notification authority while durable notification evidence exists.');
            }
        }
        if (Schema::hasTable('service_delivery_attempts')
            && DB::table('service_delivery_attempts')->where('purpose', 'notification')->exists()) {
            throw new RuntimeException('Cannot roll back Service notification authority while notification Delivery Attempts exist.');
        }

        $this->dropGuards();
        Schema::dropIfExists('service_notification_events');
        Schema::dropIfExists('service_notification_delivery_bindings');
        Schema::dropIfExists('service_notification_states');
        $this->restoreDeliveryPurposeConstraint();
    }

    private function resetInterruptedInstallIfSafe(): void
    {
        $tables = [
            'service_notification_states',
            'service_notification_delivery_bindings',
            'service_notification_events',
        ];
        $existing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => Schema::hasTable($table),
        ));
        if ($existing === []) {
            return;
        }

        foreach ($existing as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Service notification migration cannot repair an interrupted install after authority rows exist.');
            }
        }

        $this->dropGuards();
        Schema::dropIfExists('service_notification_events');
        Schema::dropIfExists('service_notification_delivery_bindings');
        Schema::dropIfExists('service_notification_states');
    }

    private function installDeliveryPurposeConstraint(): void
    {
        $this->assertDeliveryPurposeValues(['initial', 'resend']);
        $this->replaceDeliveryPurposeConstraint("purpose IN ('initial','resend','notification')");
    }

    private function restoreDeliveryPurposeConstraint(): void
    {
        $this->assertDeliveryPurposeValues(['initial', 'resend']);
        $this->replaceDeliveryPurposeConstraint("purpose IN ('initial','resend')");
    }

    /** @param  list<string>  $allowed */
    private function assertDeliveryPurposeValues(array $allowed): void
    {
        if (DB::table('service_delivery_attempts')->whereNotIn('purpose', $allowed)->exists()) {
            throw new RuntimeException(
                'Service notification migration cannot repair delivery purpose authority while unexpected Delivery Attempts exist.',
            );
        }
    }

    private function replaceDeliveryPurposeConstraint(string $checkClause): void
    {
        $prefix = 'ALTER TABLE service_delivery_attempts ';
        if ($this->deliveryPurposeConstraintExists()) {
            DB::statement(
                $prefix.'DROP CONSTRAINT service_delivery_attempts_purpose_chk, '
                .'ADD CONSTRAINT service_delivery_attempts_purpose_chk CHECK ('.$checkClause.')',
            );

            return;
        }

        DB::statement(
            $prefix.'ADD CONSTRAINT service_delivery_attempts_purpose_chk CHECK ('.$checkClause.')',
        );
    }

    private function deliveryPurposeConstraintExists(): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'service_delivery_attempts'
  AND CONSTRAINT_NAME = 'service_delivery_attempts_purpose_chk'
  AND CONSTRAINT_TYPE = 'CHECK'
SQL,
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function installConstraints(): void
    {
        foreach ([
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_type_chk CHECK (notification_type IN ('expiry','low_balance','renewal_failure'))",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_state_chk CHECK (state IN ('triggered','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_hash_chk CHECK (episode_key_hash REGEXP '^[0-9a-f]{64}$' AND cycle_key_hash REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_correlation_chk CHECK (last_correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
            'ALTER TABLE service_notification_states ADD CONSTRAINT sns_attempt_retry_chk CHECK ((latest_delivery_attempt_id IS NULL AND latest_retry_ordinal IS NULL) OR (latest_delivery_attempt_id IS NOT NULL AND latest_retry_ordinal IS NOT NULL))',
            "ALTER TABLE service_notification_delivery_bindings ADD CONSTRAINT sndb_hash_chk CHECK (presentation_hash REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_low_balance_threshold_chk CHECK ((notification_type = 'low_balance' AND low_balance_threshold_irr IS NOT NULL AND low_balance_threshold_irr > 0) OR (notification_type <> 'low_balance' AND low_balance_threshold_irr IS NULL))",
            "ALTER TABLE service_notification_states ADD CONSTRAINT sns_expiry_freshness_chk CHECK ((notification_type = 'expiry' AND expiry_snapshot_max_age_seconds BETWEEN 60 AND 86400) OR (notification_type <> 'expiry' AND expiry_snapshot_max_age_seconds IS NULL))",
            'ALTER TABLE service_notification_states ADD CONSTRAINT sns_max_retries_chk CHECK (max_retries BETWEEN 0 AND 10)',
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_type_chk CHECK (event_type IN ('triggered','delivery_queued','retry_scheduled','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_state_chk CHECK ((from_state IS NULL OR from_state IN ('triggered','notified','acknowledged','escalated','expired')) AND to_state IN ('triggered','notified','acknowledged','escalated','expired'))",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$')",
            'ALTER TABLE service_notification_events ADD CONSTRAINT sne_attempt_retry_chk CHECK ((service_delivery_attempt_id IS NULL AND retry_ordinal IS NULL) OR (service_delivery_attempt_id IS NOT NULL AND retry_ordinal IS NOT NULL))',
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_cause_chk CHECK (transition_cause IS NULL OR transition_cause IN ('delivery_succeeded','delivery_uncertain','provider_retry_fenced','retry_exhausted','outbox_review_required','source_invalidated','administrator_acknowledgment'))",
            "ALTER TABLE service_notification_events ADD CONSTRAINT sne_ack_evidence_chk CHECK ((event_type = 'acknowledged' AND transition_cause = 'administrator_acknowledgment' AND actor_administrator_id IS NOT NULL AND request_hash REGEXP '^[0-9a-f]{64}$' AND CHAR_LENGTH(TRIM(reason_code)) BETWEEN 1 AND 64 AND CHAR_LENGTH(TRIM(reason)) BETWEEN 1 AND 1000) OR (event_type <> 'acknowledged' AND actor_administrator_id IS NULL AND request_hash IS NULL AND reason_code IS NULL AND reason IS NULL))",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
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
SQL);

        DB::unprepared(<<<'SQL'
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
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_states_delete_guard
BEFORE DELETE ON service_notification_states
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification state evidence is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_insert_guard
BEFORE INSERT ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    DECLARE valid_binding_count INT DEFAULT 0;
    DECLARE valid_freshness_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') <> 'service_notification_bind_v1'
       OR NEW.service_notification_state_id <> COALESCE(@app_service_notification_state_id, 0)
       OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
       OR NEW.retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
       OR BINARY NEW.presentation_hash <> BINARY LOWER(SHA2(NEW.presentation_text, 256)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_binding_count
    FROM service_notification_states state_row
    JOIN service_delivery_attempts attempt_row ON attempt_row.id = NEW.service_delivery_attempt_id
    WHERE state_row.id = NEW.service_notification_state_id
      AND state_row.service_subscription_id = COALESCE(@app_service_notification_service_id, 0)
      AND state_row.state = 'triggered'
      AND state_row.latest_delivery_attempt_id = attempt_row.id
      AND state_row.latest_retry_ordinal = NEW.retry_ordinal
      AND attempt_row.service_subscription_id = state_row.service_subscription_id
      AND BINARY attempt_row.purpose = BINARY 'notification'
      AND BINARY attempt_row.correlation_id = BINARY COALESCE(@app_service_notification_correlation_id, '');

    IF valid_binding_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding must match the current notification Delivery Attempt.';
    END IF;

    SELECT COUNT(*) INTO valid_freshness_count
    FROM service_notification_states state_row
    JOIN service_subscriptions service_row ON service_row.id = state_row.service_subscription_id
    WHERE state_row.id = NEW.service_notification_state_id
      AND (
          state_row.notification_type <> 'expiry'
          OR EXISTS (
              SELECT 1
              FROM service_sync_snapshots snapshot_row
              WHERE snapshot_row.service_subscription_id = state_row.service_subscription_id
                AND snapshot_row.remote_disposition = 'present'
                AND snapshot_row.remote_expires_at IS NOT NULL
                AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                AND state_row.expiry_snapshot_max_age_seconds BETWEEN 60 AND 86400
                AND snapshot_row.observed_at <= NEW.created_at
                AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -state_row.expiry_snapshot_max_age_seconds, NEW.created_at)
                AND BINARY SHA2(CONCAT_WS('|',
                    'service-notification-expiry-cycle-v1',
                    service_row.id,
                    service_row.remote_identity_generation,
                    service_row.mutation_generation,
                    service_row.lifecycle_version,
                    DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
                ), 256) = BINARY state_row.cycle_key_hash
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
                )
          )
      );

    IF valid_freshness_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding freshness authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_update_guard
BEFORE UPDATE ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding is immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_bindings_delete_guard
BEFORE DELETE ON service_notification_delivery_bindings
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery binding is non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_insert_guard
BEFORE INSERT ON service_notification_events
FOR EACH ROW
BEGIN
    DECLARE current_state VARCHAR(24) DEFAULT NULL;
    DECLARE current_attempt_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE current_retry_ordinal INT DEFAULT NULL;
    DECLARE expected_sequence INT DEFAULT 1;
    DECLARE valid_delivery_count INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) OR COALESCE(@app_service_notification_authority, '') NOT IN (
        'service_notification_create_v1',
        'service_notification_bind_v1',
        'service_notification_schedule_retry_v1',
        'service_notification_terminal_v2',
        'service_notification_acknowledge_v1'
    ) OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_service_notification_correlation_id, '') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification event authority is invalid.';
    END IF;

    IF COALESCE(@app_service_notification_authority, '') IN (
        'service_notification_create_v1','service_notification_bind_v1','service_notification_schedule_retry_v1'
    ) AND (NEW.transition_cause IS NOT NULL OR NEW.actor_administrator_id IS NOT NULL
        OR NEW.request_hash IS NOT NULL OR NEW.reason_code IS NOT NULL OR NEW.reason IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification non-terminal event evidence is invalid.';
    END IF;

    SELECT state_row.state, state_row.latest_delivery_attempt_id, state_row.latest_retry_ordinal
    INTO current_state, current_attempt_id, current_retry_ordinal
    FROM service_notification_states state_row
    WHERE state_row.id = NEW.service_notification_state_id
      AND state_row.service_subscription_id = COALESCE(@app_service_notification_service_id, 0)
    LIMIT 1;

    SELECT COALESCE(MAX(event_row.sequence), 0) + 1 INTO expected_sequence
    FROM service_notification_events event_row
    WHERE event_row.service_notification_state_id = NEW.service_notification_state_id;

    IF current_state IS NULL OR BINARY NEW.to_state <> BINARY current_state OR NEW.sequence <> expected_sequence THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification event state or sequence is invalid.';
    END IF;

    IF COALESCE(@app_service_notification_authority, '') = 'service_notification_create_v1' THEN
        IF NEW.sequence <> 1
           OR NEW.event_type <> 'triggered'
           OR NEW.from_state IS NOT NULL
           OR NEW.to_state <> 'triggered'
           OR NEW.service_delivery_attempt_id IS NOT NULL
           OR NEW.retry_ordinal IS NOT NULL
           OR NEW.created_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification trigger event authority is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_bind_v1' THEN
        SELECT COUNT(*) INTO valid_delivery_count
        FROM service_notification_delivery_bindings binding_row
        WHERE binding_row.service_notification_state_id = NEW.service_notification_state_id
          AND binding_row.service_delivery_attempt_id = NEW.service_delivery_attempt_id
          AND binding_row.retry_ordinal = NEW.retry_ordinal
          AND binding_row.created_at = NEW.created_at;
        IF NEW.event_type <> 'delivery_queued'
           OR NEW.from_state <> 'triggered'
           OR NEW.to_state <> 'triggered'
           OR NEW.service_delivery_attempt_id <> COALESCE(@app_service_notification_attempt_id, 0)
           OR NEW.retry_ordinal <> COALESCE(@app_service_notification_retry_ordinal, 65535)
           OR valid_delivery_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification delivery event authority is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_schedule_retry_v1' THEN
        IF NEW.event_type <> 'retry_scheduled'
           OR NEW.from_state <> 'triggered'
           OR NEW.to_state <> 'triggered'
           OR NEW.service_delivery_attempt_id IS NOT NULL
           OR NEW.retry_ordinal IS NOT NULL
           OR NEW.created_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification retry event authority is invalid.';
        END IF;
    ELSEIF COALESCE(@app_service_notification_authority, '') = 'service_notification_terminal_v2' THEN
        IF BINARY NEW.event_type <> BINARY COALESCE(@app_service_notification_to_state, '')
           OR BINARY NEW.from_state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR BINARY NEW.to_state <> BINARY COALESCE(@app_service_notification_to_state, '')
           OR NOT (NEW.service_delivery_attempt_id <=> current_attempt_id)
           OR NOT (NEW.retry_ordinal <=> current_retry_ordinal)
           OR BINARY COALESCE(NEW.transition_cause, '') <> BINARY COALESCE(@app_service_notification_transition_cause, '')
           OR NEW.actor_administrator_id IS NOT NULL OR NEW.request_hash IS NOT NULL
           OR NEW.reason_code IS NOT NULL OR NEW.reason IS NOT NULL
           OR NEW.created_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification transition event authority is invalid.';
        END IF;
    ELSE
        IF BINARY NEW.event_type <> BINARY 'acknowledged'
           OR BINARY NEW.from_state <> BINARY COALESCE(@app_service_notification_from_state, '')
           OR BINARY NEW.to_state <> BINARY 'acknowledged'
           OR NOT (NEW.service_delivery_attempt_id <=> current_attempt_id)
           OR NOT (NEW.retry_ordinal <=> current_retry_ordinal)
           OR BINARY COALESCE(NEW.transition_cause, '') <> BINARY 'administrator_acknowledgment'
           OR NEW.actor_administrator_id <> COALESCE(@app_service_notification_actor_administrator_id, 0)
           OR BINARY NEW.request_hash <> BINARY COALESCE(@app_service_notification_request_hash, '')
           OR BINARY NEW.reason_code <> BINARY COALESCE(@app_service_notification_reason_code, '')
           OR BINARY NEW.reason <> BINARY COALESCE(@app_service_notification_reason, '')
           OR NEW.created_at <> @app_service_notification_timestamp THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification acknowledgment event authority is invalid.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_update_guard
BEFORE UPDATE ON service_notification_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification events are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_notification_events_delete_guard
BEFORE DELETE ON service_notification_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service notification events are non-deletable.';
END
SQL);
    }

    private function installNotificationDeliveryCapabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_notification_attempt_capability_guard
BEFORE INSERT ON service_delivery_attempts
FOR EACH ROW
BEGIN
    IF NEW.purpose = 'notification' AND NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification Delivery Attempt requires the operational database capability.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_notification_effect_insert_capability_guard
BEFORE INSERT ON service_delivery_effects
FOR EACH ROW
BEGIN
    DECLARE attempt_purpose VARCHAR(16) DEFAULT NULL;

    SELECT purpose INTO attempt_purpose
    FROM service_delivery_attempts
    WHERE id = NEW.service_delivery_attempt_id
    LIMIT 1;

    IF attempt_purpose = 'notification' AND NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery effect requires the operational database capability.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER service_delivery_notification_effect_update_capability_guard
BEFORE UPDATE ON service_delivery_effects
FOR EACH ROW
BEGIN
    DECLARE attempt_purpose VARCHAR(16) DEFAULT NULL;
    DECLARE valid_notification_freshness_count INT DEFAULT 0;

    SELECT purpose INTO attempt_purpose
    FROM service_delivery_attempts
    WHERE id = OLD.service_delivery_attempt_id
    LIMIT 1;

    IF attempt_purpose = 'notification' AND NOT EXISTS (
        SELECT 1 FROM service_operational_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_service_operational_capability, ''), 256)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery effect update requires the operational database capability.';
    END IF;

    IF attempt_purpose = 'notification'
       AND OLD.state = 'prepared'
       AND NEW.state = 'sending' THEN
        SELECT COUNT(*) INTO valid_notification_freshness_count
        FROM service_notification_delivery_bindings binding_row
        JOIN service_notification_states state_row ON state_row.id = binding_row.service_notification_state_id
        JOIN service_subscriptions service_row ON service_row.id = state_row.service_subscription_id
        WHERE binding_row.service_delivery_attempt_id = OLD.service_delivery_attempt_id
          AND state_row.state = 'triggered'
          AND (
              state_row.notification_type <> 'expiry'
              OR (
                  NEW.provider_boundary_started_at IS NOT NULL
                  AND EXISTS (
                      SELECT 1
                      FROM service_sync_snapshots snapshot_row
                      WHERE snapshot_row.service_subscription_id = state_row.service_subscription_id
                        AND snapshot_row.remote_disposition = 'present'
                        AND snapshot_row.remote_expires_at IS NOT NULL
                        AND snapshot_row.local_lifecycle_version = service_row.lifecycle_version
                        AND snapshot_row.local_remote_identity_generation = service_row.remote_identity_generation
                        AND snapshot_row.local_mutation_generation = service_row.mutation_generation
                        AND state_row.expiry_snapshot_max_age_seconds BETWEEN 60 AND 86400
                        AND snapshot_row.observed_at <= NEW.provider_boundary_started_at
                        AND snapshot_row.observed_at >= TIMESTAMPADD(SECOND, -state_row.expiry_snapshot_max_age_seconds, NEW.provider_boundary_started_at)
                        AND BINARY SHA2(CONCAT_WS('|',
                            'service-notification-expiry-cycle-v1',
                            service_row.id,
                            service_row.remote_identity_generation,
                            service_row.mutation_generation,
                            service_row.lifecycle_version,
                            DATE_FORMAT(snapshot_row.remote_expires_at, '%Y-%m-%d %H:%i:%s.%f')
                        ), 256) = BINARY state_row.cycle_key_hash
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
                        )
                  )
              )
          );

        IF valid_notification_freshness_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notification delivery provider freshness authority is invalid.';
        END IF;
    END IF;
END
SQL);
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `service_delivery_notification_effect_update_capability_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_delivery_notification_effect_insert_capability_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_delivery_notification_attempt_capability_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_events_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_bindings_insert_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_delete_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_update_guard`');
        DB::unprepared('DROP TRIGGER IF EXISTS `service_notification_states_insert_guard`');
    }
};
