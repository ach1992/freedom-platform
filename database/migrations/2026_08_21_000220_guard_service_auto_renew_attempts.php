<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->createAttemptGuards();
        $this->createAppendOnlyGuards();
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $this->dropGuards();
    }

    private function assertRollbackSafe(): void
    {
        foreach ([
            'plan_offering_auto_renew_policies',
            'plan_offering_auto_renew_policy_histories',
            'service_auto_renew_configurations',
            'service_auto_renew_configuration_histories',
            'service_auto_renew_attempts',
            'service_auto_renew_attempt_events',
            'service_auto_renew_notification_intents',
        ] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove Service auto-renew guards while auto-renew authority rows exist.');
            }
        }
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sara_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sara_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sara_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarph_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarph_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarch_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarch_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarae_insert_authority_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarae_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarae_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_insert_authority_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_delete_guard');
    }

    private function createAttemptGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sara_insert_guard
BEFORE INSERT ON service_auto_renew_attempts
FOR EACH ROW
BEGIN
    IF NEW.state <> 'pending'
       OR NEW.reason_code IS NOT NULL
       OR NEW.current_price_irr IS NOT NULL
       OR NEW.quote_id IS NOT NULL
       OR NEW.payment_eligibility_decision_id IS NOT NULL
       OR NEW.payment_intent_id IS NOT NULL
       OR NEW.purchase_settlement_id IS NOT NULL
       OR NEW.provisioning_operation_id IS NOT NULL
       OR NEW.commercial_generation <> 0
       OR NEW.retry_count <> 0
       OR NEW.next_retry_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt must start without commercial or terminal authority.';
    END IF;

    IF BINARY NEW.cycle_key <> BINARY LOWER(SHA2(CONCAT(
        NEW.auto_renew_configuration_id, '|',
        NEW.service_subscription_id, '|',
        NEW.configuration_version, '|',
        NEW.remote_identity_generation, '|',
        DATE_FORMAT(NEW.observed_expires_at, '%Y-%m-%d %H:%i:%s.%f')
    ), 256)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew cycle identity is not derived from the current Service cycle authority.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM service_auto_renew_configurations c
        JOIN service_subscriptions s ON s.id = c.service_subscription_id
        WHERE c.id = NEW.auto_renew_configuration_id
          AND c.service_subscription_id = NEW.service_subscription_id
          AND c.enabled = 1
          AND c.configuration_version = NEW.configuration_version
          AND c.observed_remote_identity_generation = NEW.remote_identity_generation
          AND c.observed_expires_at = NEW.observed_expires_at
          AND c.observed_expiry_evidence_hash = NEW.observed_expiry_evidence_hash
          AND c.observed_expiry_source = NEW.observed_expiry_source
          AND COALESCE(c.last_settled_price_irr, c.accepted_price_irr) = NEW.baseline_price_irr
          AND s.remote_identity_generation = NEW.remote_identity_generation
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt does not match current configuration and Service cycle authority.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sara_update_guard
BEFORE UPDATE ON service_auto_renew_attempts
FOR EACH ROW
BEGIN
    IF OLD.public_id <> NEW.public_id
       OR OLD.cycle_key <> NEW.cycle_key
       OR OLD.auto_renew_configuration_id <> NEW.auto_renew_configuration_id
       OR OLD.service_subscription_id <> NEW.service_subscription_id
       OR OLD.configuration_version <> NEW.configuration_version
       OR OLD.remote_identity_generation <> NEW.remote_identity_generation
       OR OLD.observed_expires_at <> NEW.observed_expires_at
       OR OLD.observed_expiry_evidence_hash <> NEW.observed_expiry_evidence_hash
       OR OLD.observed_expiry_source <> NEW.observed_expiry_source
       OR OLD.baseline_price_irr <> NEW.baseline_price_irr
       OR OLD.correlation_id <> NEW.correlation_id
       OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt identity is immutable.';
    END IF;

    IF OLD.payment_intent_id IS NOT NULL THEN
        IF NEW.payment_intent_id IS NULL THEN
            IF OLD.purchase_settlement_id IS NOT NULL
               OR OLD.provisioning_operation_id IS NOT NULL
               OR NEW.quote_id IS NOT NULL
               OR NEW.payment_eligibility_decision_id IS NOT NULL
               OR NEW.current_price_irr IS NOT NULL
               OR NEW.commercial_generation <> OLD.commercial_generation + 1
               OR NOT EXISTS (
                   SELECT 1
                   FROM payment_intents pi
                   JOIN purchase_wallet_reservations pwr ON pwr.payment_intent_id = pi.id
                   JOIN wallet_holds wh ON wh.id = pwr.wallet_hold_id
                   WHERE pi.id = OLD.payment_intent_id
                     AND pi.state IN ('expired', 'canceled')
                     AND wh.status = 'released'
                     AND NOT EXISTS (
                         SELECT 1 FROM purchase_settlements ps
                         WHERE ps.payment_intent_id = OLD.payment_intent_id
                     )
               ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew commercial authority can reset only after safe pre-capture terminalization.';
            END IF;
        ELSE
            IF NEW.commercial_generation <> OLD.commercial_generation THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew commercial generation cannot change without safe authority reset.';
            END IF;
            IF NOT (OLD.quote_id <=> NEW.quote_id)
               OR NOT (OLD.payment_eligibility_decision_id <=> NEW.payment_eligibility_decision_id)
               OR NOT (OLD.current_price_irr <=> NEW.current_price_irr) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew commercial authority is frozen after Payment Intent binding.';
            END IF;
            IF NOT (OLD.payment_intent_id <=> NEW.payment_intent_id) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Payment Intent authority cannot be replaced after binding.';
            END IF;
        END IF;
    END IF;
    IF OLD.payment_intent_id IS NULL AND NEW.commercial_generation <> OLD.commercial_generation THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew commercial generation cannot change without bound authority reset.';
    END IF;
    IF OLD.purchase_settlement_id IS NOT NULL AND NOT (OLD.purchase_settlement_id <=> NEW.purchase_settlement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew settlement authority cannot be replaced after binding.';
    END IF;
    IF OLD.provisioning_operation_id IS NOT NULL AND NOT (OLD.provisioning_operation_id <=> NEW.provisioning_operation_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew mutation authority cannot be replaced after binding.';
    END IF;

    IF NEW.payment_intent_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM payment_intents pi
        WHERE pi.id = NEW.payment_intent_id
          AND pi.purpose = 'purchase'
          AND pi.source_quote_id = NEW.quote_id
          AND pi.payment_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND pi.payment_method_code = 'wallet'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Payment Intent does not match bound Quote and eligibility authority.';
    END IF;
    IF NEW.purchase_settlement_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM purchase_settlements ps
        WHERE ps.id = NEW.purchase_settlement_id AND ps.payment_intent_id = NEW.payment_intent_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew settlement does not match bound Payment Intent.';
    END IF;
    IF NEW.provisioning_operation_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM service_paid_mutation_authorities a
        WHERE a.provisioning_operation_id = NEW.provisioning_operation_id
          AND a.purchase_settlement_id = NEW.purchase_settlement_id
          AND a.service_subscription_id = NEW.service_subscription_id
          AND a.action = 'renew'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew mutation does not match the captured renewal authority.';
    END IF;

    IF NEW.state = 'succeeded'
       AND (
           NEW.reason_code <> 'renewal_succeeded'
           OR NEW.provisioning_operation_id IS NULL
           OR NOT EXISTS (
               SELECT 1
               FROM provisioning_operations op
               WHERE op.id = NEW.provisioning_operation_id
                 AND op.state = 'succeeded'
           )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew success requires succeeded provisioning operation authority.';
    END IF;
    IF NEW.state = 'failed'
       AND NEW.reason_code = 'renewal_mutation_failed'
       AND (
           NEW.provisioning_operation_id IS NULL
           OR NOT EXISTS (
               SELECT 1
               FROM provisioning_operations op
               WHERE op.id = NEW.provisioning_operation_id
                 AND op.state IN ('failed_final', 'compensated')
           )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew mutation failure requires terminal provisioning operation authority.';
    END IF;
    IF NEW.state = 'mutation_queued'
       AND NEW.reason_code = 'mutation_reconciliation_required'
       AND (
           NEW.provisioning_operation_id IS NULL
           OR NOT EXISTS (
               SELECT 1
               FROM provisioning_operations op
               WHERE op.id = NEW.provisioning_operation_id
                 AND op.state IN ('uncertain_remote_result', 'needs_review')
           )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew reconciliation reason requires uncertain provisioning operation authority.';
    END IF;

    IF OLD.state IN ('price_change_blocked', 'succeeded', 'failed')
       AND (NEW.state <> OLD.state
            OR NOT (OLD.reason_code <=> NEW.reason_code)
            OR NOT (OLD.current_price_irr <=> NEW.current_price_irr)
            OR NOT (OLD.quote_id <=> NEW.quote_id)
            OR NOT (OLD.payment_eligibility_decision_id <=> NEW.payment_eligibility_decision_id)
            OR NOT (OLD.payment_intent_id <=> NEW.payment_intent_id)
            OR NOT (OLD.purchase_settlement_id <=> NEW.purchase_settlement_id)
            OR NOT (OLD.provisioning_operation_id <=> NEW.provisioning_operation_id)
            OR NOT (OLD.commercial_generation <=> NEW.commercial_generation)
            OR NOT (OLD.retry_count <=> NEW.retry_count)
            OR NOT (OLD.next_retry_at <=> NEW.next_retry_at)
            OR NOT (OLD.completed_at <=> NEW.completed_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal auto-renew attempt authority is immutable.';
    END IF;
    IF OLD.state = 'pending' AND NEW.state NOT IN ('pending', 'price_change_blocked', 'insufficient_wallet', 'retry_pending', 'settled', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew pending transition is invalid.';
    END IF;
    IF OLD.state = 'insufficient_wallet' AND NEW.state NOT IN ('insufficient_wallet', 'retry_pending', 'price_change_blocked', 'settled', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew insufficient-wallet transition is invalid.';
    END IF;
    IF OLD.state = 'retry_pending' AND NEW.state NOT IN ('retry_pending', 'price_change_blocked', 'insufficient_wallet', 'settled', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew retry-pending transition is invalid.';
    END IF;
    IF OLD.state = 'settled' AND NEW.state NOT IN ('settled', 'mutation_queued', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew settled transition is invalid.';
    END IF;
    IF OLD.state = 'mutation_queued' AND NEW.state NOT IN ('mutation_queued', 'succeeded', 'failed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew mutation transition is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sara_delete_guard
BEFORE DELETE ON service_auto_renew_attempts
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempts are non-deletable.';
END
SQL);
    }

    private function createAppendOnlyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarph_update_guard BEFORE UPDATE ON plan_offering_auto_renew_policy_histories
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policy histories are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarph_delete_guard BEFORE DELETE ON plan_offering_auto_renew_policy_histories
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policy histories are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarch_update_guard BEFORE UPDATE ON service_auto_renew_configuration_histories
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration histories are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarch_delete_guard BEFORE DELETE ON service_auto_renew_configuration_histories
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration histories are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarae_insert_authority_guard
BEFORE INSERT ON service_auto_renew_attempt_events
FOR EACH ROW
BEGIN
    IF NEW.sequence = 1 THEN
        IF NEW.from_state IS NOT NULL
           OR NEW.to_state <> 'pending'
           OR COALESCE(NEW.reason_code, '') <> 'cycle_claimed'
           OR NEW.quote_id IS NOT NULL
           OR NEW.payment_intent_id IS NOT NULL
           OR NEW.purchase_settlement_id IS NOT NULL
           OR NEW.provisioning_operation_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew initial attempt event must be the cycle-claim authority.';
        END IF;
    ELSEIF NEW.sequence < 2 OR NEW.from_state IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew subsequent attempt event must have prior-state authority.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM service_auto_renew_attempts a
        WHERE a.id = NEW.auto_renew_attempt_id
          AND a.state = NEW.to_state
          AND (a.quote_id <=> NEW.quote_id)
          AND (a.payment_intent_id <=> NEW.payment_intent_id)
          AND (a.purchase_settlement_id <=> NEW.purchase_settlement_id)
          AND (a.provisioning_operation_id <=> NEW.provisioning_operation_id)
          AND BINARY a.correlation_id = BINARY NEW.correlation_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt event does not match current attempt authority.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarae_update_guard BEFORE UPDATE ON service_auto_renew_attempt_events
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt events are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarae_delete_guard BEFORE DELETE ON service_auto_renew_attempt_events
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew attempt events are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarni_insert_authority_guard
BEFORE INSERT ON service_auto_renew_notification_intents
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM service_auto_renew_attempts a
        LEFT JOIN provisioning_operations op ON op.id = a.provisioning_operation_id
        WHERE a.id = NEW.auto_renew_attempt_id
          AND BINARY COALESCE(a.reason_code, '') = BINARY COALESCE(NEW.reason_code, '')
          AND (
              (NEW.outcome = 'success'
                  AND a.state = 'succeeded'
                  AND a.reason_code = 'renewal_succeeded'
                  AND op.state = 'succeeded')
              OR (NEW.outcome = 'price_change_blocked'
                  AND a.state = 'price_change_blocked')
              OR (NEW.outcome = 'insufficient_wallet'
                  AND a.state = 'insufficient_wallet')
              OR (NEW.outcome = 'failure'
                  AND a.state = 'failed'
                  AND a.reason_code NOT IN ('configuration_superseded', 'cycle_superseded'))
          )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew notification outcome does not match current attempt authority.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarni_update_guard BEFORE UPDATE ON service_auto_renew_notification_intents
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew notification intents are append-only.'; END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarni_delete_guard BEFORE DELETE ON service_auto_renew_notification_intents
FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew notification intents are append-only.'; END
SQL);
    }
};
