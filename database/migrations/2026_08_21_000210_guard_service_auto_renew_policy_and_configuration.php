<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->createPolicyGuards();
        $this->createConfigurationGuards();
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        $this->dropGuards();
    }

    private function assertRollbackSafe(): void
    {
        foreach (['plan_offering_auto_renew_policies', 'service_auto_renew_configurations'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove Service auto-renew guards while auto-renew authority rows exist.');
            }
        }
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarph_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarch_insert_guard');
    }

    private function createPolicyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarp_insert_guard
BEFORE INSERT ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_policy_v1'
       OR COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0) <> NEW.actor_administrator_id
       OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.plan_offering_id
       OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.correlation_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy creation requires explicit administrator authority.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id AND o.auto_renew_allowed = 1 AND o.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy requires an active auto-renew-enabled offering.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarp_update_guard
BEFORE UPDATE ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policy identity is immutable.';
    END IF;
    IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_policy_v1'
       OR COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0) <> NEW.actor_administrator_id
       OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.plan_offering_id
       OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.correlation_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy mutation requires explicit administrator authority.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policy version must advance exactly once.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id AND o.auto_renew_allowed = 1 AND o.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy requires an active auto-renew-enabled offering.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarp_delete_guard
BEFORE DELETE ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policies are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarph_insert_guard
BEFORE INSERT ON plan_offering_auto_renew_policy_histories
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_policy_v1'
       OR COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0) <> NEW.actor_administrator_id
       OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.correlation_id
       OR NOT EXISTS (
           SELECT 1
           FROM plan_offering_auto_renew_policies p
           WHERE p.id = NEW.auto_renew_policy_id
             AND p.plan_offering_id = COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0)
             AND p.version = NEW.version
             AND BINARY p.price_change_mode = BINARY NEW.price_change_mode
             AND p.absolute_increase_limit_irr <=> NEW.absolute_increase_limit_irr
             AND p.percentage_increase_limit_bps <=> NEW.percentage_increase_limit_bps
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy history requires matching current administrator authority.';
    END IF;
END
SQL);
    }

    private function createConfigurationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarc_insert_guard
BEFORE INSERT ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_configuration_v1'
       OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.service_subscription_id
       OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.last_correlation_id
       OR NOT EXISTS (
           SELECT 1
           FROM service_subscriptions s
           JOIN quotes q ON q.id = COALESCE(CAST(@app_service_auto_renew_quote_id AS UNSIGNED), 0)
           WHERE s.id = NEW.service_subscription_id
             AND s.user_id = COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0)
             AND q.action_snapshot = 'renew'
             AND q.user_id = s.user_id
             AND q.service_subscription_id = s.id
             AND q.service_subscription_public_id = s.public_id
             AND q.service_target_id_snapshot = s.service_target_id
             AND q.service_remote_identity_generation_snapshot = NEW.observed_remote_identity_generation
             AND q.service_remote_identity_generation_snapshot = s.remote_identity_generation
             AND q.service_lifecycle_version_snapshot = s.lifecycle_version
             AND q.service_package_id_snapshot = NEW.renewal_package_id
             AND q.service_package_type_snapshot = 'renewal'
             AND q.final_price_irr = NEW.accepted_price_irr
             AND q.currency = 'IRR'
             AND q.expires_at > CURRENT_TIMESTAMP(6)
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew configuration creation requires a current owner-accepted renewal Quote.';
    END IF;
    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1
        FROM service_subscriptions s
        JOIN order_items oi ON oi.id = s.order_item_id
        JOIN plan_offerings o ON o.id = oi.plan_offering_id
        JOIN plan_offering_packages p ON p.id = NEW.renewal_package_id AND p.plan_offering_id = oi.plan_offering_id
        WHERE s.id = NEW.service_subscription_id
          AND s.lifecycle_state IN ('active', 'suspended')
          AND s.remote_deleted_at IS NULL
          AND s.service_target_id IS NOT NULL
          AND s.remote_service_id IS NOT NULL
          AND s.provisioned_at IS NOT NULL
          AND o.auto_renew_allowed = 1
          AND o.state = 'active'
          AND p.package_type = 'renewal'
          AND p.duration_days IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enabled auto-renew configuration references an ineligible Service or package.';
    END IF;
    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1 FROM service_subscriptions s
        WHERE s.id = NEW.service_subscription_id
          AND s.remote_identity_generation = NEW.observed_remote_identity_generation
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew expiry observation is stale for the Service remote identity.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarc_update_guard
BEFORE UPDATE ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    DECLARE config_changed BOOLEAN DEFAULT FALSE;
    DECLARE version_changed BOOLEAN DEFAULT FALSE;
    DECLARE settled_price_changed BOOLEAN DEFAULT FALSE;
    DECLARE observation_changed BOOLEAN DEFAULT FALSE;

    SET config_changed = NOT (OLD.enabled <=> NEW.enabled)
        OR NOT (OLD.renewal_package_id <=> NEW.renewal_package_id)
        OR NOT (OLD.accepted_price_irr <=> NEW.accepted_price_irr);
    SET version_changed = NOT (OLD.configuration_version <=> NEW.configuration_version);
    SET settled_price_changed = NOT (OLD.last_settled_price_irr <=> NEW.last_settled_price_irr);
    SET observation_changed = NOT (OLD.observed_expires_at <=> NEW.observed_expires_at)
        OR NOT (OLD.expiry_observed_at <=> NEW.expiry_observed_at)
        OR NOT (OLD.observed_expiry_evidence_hash <=> NEW.observed_expiry_evidence_hash)
        OR NOT (OLD.observed_expiry_source <=> NEW.observed_expiry_source)
        OR NOT (OLD.observed_remote_identity_generation <=> NEW.observed_remote_identity_generation);

    IF OLD.service_subscription_id <> NEW.service_subscription_id OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration identity is immutable.';
    END IF;
    IF NEW.configuration_version NOT IN (OLD.configuration_version, OLD.configuration_version + 1)
       OR (config_changed AND NEW.configuration_version <> OLD.configuration_version + 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration version transition is invalid.';
    END IF;

    IF version_changed THEN
        IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_configuration_v1'
           OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.service_subscription_id
           OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.last_correlation_id
           OR NOT EXISTS (
               SELECT 1 FROM service_subscriptions s
               WHERE s.id = NEW.service_subscription_id
                 AND s.user_id = COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0)
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew configuration mutation requires explicit owner authority.';
        END IF;

        IF NEW.enabled = 1 THEN
            IF NEW.last_settled_price_irr IS NOT NULL
               OR NOT EXISTS (
                   SELECT 1
                   FROM service_subscriptions s
                   JOIN quotes q ON q.id = COALESCE(CAST(@app_service_auto_renew_quote_id AS UNSIGNED), 0)
                   WHERE s.id = NEW.service_subscription_id
                     AND q.action_snapshot = 'renew'
                     AND q.user_id = s.user_id
                     AND q.service_subscription_id = s.id
                     AND q.service_subscription_public_id = s.public_id
                     AND q.service_target_id_snapshot = s.service_target_id
                     AND q.service_remote_identity_generation_snapshot = NEW.observed_remote_identity_generation
                     AND q.service_remote_identity_generation_snapshot = s.remote_identity_generation
                     AND q.service_lifecycle_version_snapshot = s.lifecycle_version
                     AND q.service_package_id_snapshot = NEW.renewal_package_id
                     AND q.service_package_type_snapshot = 'renewal'
                     AND q.final_price_irr = NEW.accepted_price_irr
                     AND q.currency = 'IRR'
                     AND q.expires_at > CURRENT_TIMESTAMP(6)
               ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enabled auto-renew reconfiguration requires a current owner-accepted renewal Quote.';
            END IF;
        ELSEIF NOT (OLD.renewal_package_id <=> NEW.renewal_package_id)
            OR NOT (OLD.accepted_price_irr <=> NEW.accepted_price_irr)
            OR NOT (OLD.last_settled_price_irr <=> NEW.last_settled_price_irr) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disabling auto-renew cannot rewrite commercial price authority.';
        END IF;
    ELSEIF config_changed THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew configuration changes require a new configuration version.';
    END IF;

    IF NOT version_changed AND settled_price_changed THEN
        IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_settlement_v1'
           OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.id
           OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.last_correlation_id
           OR NOT EXISTS (
               SELECT 1
               FROM service_auto_renew_attempts a
               JOIN purchase_settlements ps ON ps.id = a.purchase_settlement_id
               JOIN payment_intents pi ON pi.id = a.payment_intent_id
               WHERE a.id = COALESCE(CAST(@app_service_auto_renew_attempt_id AS UNSIGNED), 0)
                 AND a.auto_renew_configuration_id = NEW.id
                 AND a.configuration_version = NEW.configuration_version
                 AND a.state IN ('settled','mutation_queued','succeeded')
                 AND a.current_price_irr = NEW.last_settled_price_irr
                 AND BINARY a.correlation_id = BINARY NEW.last_correlation_id
                 AND ps.payment_intent_id = pi.id
                 AND ps.id = a.purchase_settlement_id
                 AND pi.state = 'captured'
                 AND pi.captured_at IS NOT NULL
           ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew settled price requires captured settlement authority.';
        END IF;
    END IF;

    IF NOT version_changed AND observation_changed THEN
        IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_observation_v1'
           OR COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0) <> NEW.id
           OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.last_correlation_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew expiry observation requires explicit observation authority.';
        END IF;

        IF NEW.observed_expiry_source = 'remote_snapshot' THEN
            IF COALESCE(CAST(@app_service_auto_renew_attempt_id AS UNSIGNED), 0) <> 0
               OR NOT EXISTS (
                   SELECT 1 FROM service_subscriptions s
                   WHERE s.id = NEW.service_subscription_id
                     AND s.remote_identity_generation = NEW.observed_remote_identity_generation
               ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew remote observation authority is stale for the Service identity.';
            END IF;
        ELSEIF NEW.observed_expiry_source = 'paid_mutation' THEN
            IF NOT EXISTS (
               SELECT 1
               FROM service_auto_renew_attempts a
               JOIN service_paid_mutation_authorities m ON m.provisioning_operation_id = a.provisioning_operation_id
               JOIN provisioning_operations op ON op.id = a.provisioning_operation_id
               JOIN service_subscriptions s ON s.id = a.service_subscription_id
               WHERE a.id = COALESCE(CAST(@app_service_auto_renew_attempt_id AS UNSIGNED), 0)
                 AND a.auto_renew_configuration_id = NEW.id
                 AND a.service_subscription_id = NEW.service_subscription_id
                 AND a.state = 'mutation_queued'
                 AND BINARY a.correlation_id = BINARY NEW.last_correlation_id
                 AND op.state = 'succeeded'
                 AND m.service_subscription_id = NEW.service_subscription_id
                 AND m.action = 'renew'
                 AND m.target_expires_at = NEW.observed_expires_at
                 AND m.quoted_remote_identity_generation = NEW.observed_remote_identity_generation
                 AND s.remote_identity_generation = NEW.observed_remote_identity_generation
                 AND BINARY LOWER(NEW.observed_expiry_evidence_hash) = BINARY LOWER(SHA2(CONCAT(
                     'paid_mutation|', m.id, '|', a.provisioning_operation_id, '|',
                     DATE_FORMAT(m.target_expires_at, '%Y-%m-%d %H:%i:%s.%f'), '|',
                     m.quoted_remote_identity_generation
                 ), 256))
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew expiry observation requires successful paid mutation authority.';
            END IF;
        ELSE
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew expiry observation source is not authorized.';
        END IF;
    END IF;

    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1
        FROM service_subscriptions s
        JOIN order_items oi ON oi.id = s.order_item_id
        JOIN plan_offerings o ON o.id = oi.plan_offering_id
        JOIN plan_offering_packages p ON p.id = NEW.renewal_package_id AND p.plan_offering_id = oi.plan_offering_id
        WHERE s.id = NEW.service_subscription_id
          AND s.lifecycle_state IN ('active', 'suspended')
          AND s.remote_deleted_at IS NULL
          AND s.service_target_id IS NOT NULL
          AND s.remote_service_id IS NOT NULL
          AND s.provisioned_at IS NOT NULL
          AND o.auto_renew_allowed = 1
          AND o.state = 'active'
          AND p.package_type = 'renewal'
          AND p.duration_days IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enabled auto-renew configuration references an ineligible Service or package.';
    END IF;
    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1 FROM service_subscriptions s
        WHERE s.id = NEW.service_subscription_id
          AND s.remote_identity_generation = NEW.observed_remote_identity_generation
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew expiry observation is stale for the Service remote identity.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarc_delete_guard
BEFORE DELETE ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configurations are non-deletable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sarch_insert_guard
BEFORE INSERT ON service_auto_renew_configuration_histories
FOR EACH ROW
BEGIN
    IF COALESCE(@app_service_auto_renew_authority, '') <> 'service_auto_renew_configuration_v1'
       OR COALESCE(CAST(@app_service_auto_renew_actor_id AS UNSIGNED), 0) <> NEW.actor_user_id
       OR BINARY COALESCE(@app_service_auto_renew_correlation_id, '') <> BINARY NEW.correlation_id
       OR NOT EXISTS (
           SELECT 1
           FROM service_auto_renew_configurations c
           JOIN service_subscriptions s ON s.id = c.service_subscription_id
           WHERE c.id = NEW.auto_renew_configuration_id
             AND c.service_subscription_id = COALESCE(CAST(@app_service_auto_renew_scope_id AS UNSIGNED), 0)
             AND s.user_id = NEW.actor_user_id
             AND c.configuration_version = NEW.configuration_version
             AND c.enabled = NEW.enabled
             AND c.renewal_package_id = NEW.renewal_package_id
             AND c.accepted_price_irr = NEW.accepted_price_irr
             AND c.observed_expires_at <=> NEW.observed_expires_at
             AND c.observed_expiry_evidence_hash <=> NEW.observed_expiry_evidence_hash
             AND c.observed_expiry_source <=> NEW.observed_expiry_source
             AND c.observed_remote_identity_generation <=> NEW.observed_remote_identity_generation
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew configuration history requires matching current owner authority.';
    END IF;
END
SQL);
    }
};
