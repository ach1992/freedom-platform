<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->createConfigurationInsertGuard();
        $this->createConfigurationUpdateGuard();
    }

    public function down(): void
    {
        if (DB::table('service_auto_renew_configurations')->exists()) {
            throw new RuntimeException('Cannot restore legacy auto-renew configuration guards while configuration authority exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS sarc_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_update_guard');

        $legacy = require database_path('migrations/2026_08_21_000210_guard_service_auto_renew_policy_and_configuration.php');
        if (! is_object($legacy) || ! method_exists($legacy, 'up')) {
            throw new RuntimeException('Legacy auto-renew configuration guard migration is unavailable.');
        }
        $legacy->up();
    }

    private function createConfigurationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER sarc_insert_guard
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
    }

    private function createConfigurationUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER sarc_update_guard
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
    }
};
