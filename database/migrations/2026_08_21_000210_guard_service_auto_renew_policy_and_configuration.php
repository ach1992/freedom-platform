<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropGuards();
        $this->createPolicyGuards();
        $this->createConfigurationGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_delete_guard');
    }

    private function createPolicyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER sarp_insert_guard
BEFORE INSERT ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id AND o.auto_renew_allowed = 1 AND o.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew policy requires an active auto-renew-enabled offering.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER sarp_update_guard
BEFORE UPDATE ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    IF OLD.plan_offering_id <> NEW.plan_offering_id OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policy identity is immutable.';
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
CREATE TRIGGER sarp_delete_guard
BEFORE DELETE ON plan_offering_auto_renew_policies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew offering policies are non-deletable.';
END
SQL);
    }

    private function createConfigurationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER sarc_insert_guard
BEFORE INSERT ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1
        FROM service_subscriptions s
        JOIN plan_offerings o ON o.id = s.plan_offering_id
        JOIN plan_offering_packages p ON p.id = NEW.renewal_package_id AND p.plan_offering_id = s.plan_offering_id
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
CREATE TRIGGER sarc_update_guard
BEFORE UPDATE ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    DECLARE config_changed BOOLEAN DEFAULT FALSE;
    SET config_changed = NOT (OLD.enabled <=> NEW.enabled)
        OR NOT (OLD.renewal_package_id <=> NEW.renewal_package_id)
        OR NOT (OLD.accepted_price_irr <=> NEW.accepted_price_irr);

    IF OLD.service_subscription_id <> NEW.service_subscription_id OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration identity is immutable.';
    END IF;
    IF NEW.configuration_version NOT IN (OLD.configuration_version, OLD.configuration_version + 1)
       OR (config_changed AND NEW.configuration_version <> OLD.configuration_version + 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configuration version transition is invalid.';
    END IF;
    IF NEW.enabled = 1 AND NOT EXISTS (
        SELECT 1
        FROM service_subscriptions s
        JOIN plan_offerings o ON o.id = s.plan_offering_id
        JOIN plan_offering_packages p ON p.id = NEW.renewal_package_id AND p.plan_offering_id = s.plan_offering_id
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
CREATE TRIGGER sarc_delete_guard
BEFORE DELETE ON service_auto_renew_configurations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Service configurations are non-deletable.';
END
SQL);
    }
};
