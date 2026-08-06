<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement CAT-005 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_calculations_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_calculations_insert_guard
BEFORE INSERT ON custom_plan_calculations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM custom_plan_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = NEW.custom_plan_policy_id
          AND policy.plan_offering_id = NEW.plan_offering_id
          AND policy.enabled = 1
          AND policy.version = NEW.custom_plan_policy_version
          AND policy.configuration_hash = NEW.policy_configuration_hash
          AND offering.custom_plan_allowed = 1
          AND NEW.data_gb BETWEEN policy.minimum_data_gb AND policy.maximum_data_gb
          AND MOD(NEW.data_gb - policy.minimum_data_gb, policy.data_step_gb) = 0
          AND NEW.days BETWEEN policy.minimum_days AND policy.maximum_days
          AND MOD(NEW.days - policy.minimum_days, policy.day_step) = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan calculation policy snapshot is invalid.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS custom_plan_calculations_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER custom_plan_calculations_insert_guard
BEFORE INSERT ON custom_plan_calculations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM custom_plan_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = NEW.custom_plan_policy_id
          AND policy.plan_offering_id = NEW.plan_offering_id
          AND policy.enabled = 1
          AND policy.version = NEW.custom_plan_policy_version
          AND policy.configuration_hash = NEW.policy_configuration_hash
          AND offering.state = 'active'
          AND offering.visibility = 'visible'
          AND offering.custom_plan_allowed = 1
          AND NEW.data_gb BETWEEN policy.minimum_data_gb AND policy.maximum_data_gb
          AND MOD(NEW.data_gb - policy.minimum_data_gb, policy.data_step_gb) = 0
          AND NEW.days BETWEEN policy.minimum_days AND policy.maximum_days
          AND MOD(NEW.days - policy.minimum_days, policy.day_step) = 0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Custom-plan calculation policy snapshot is invalid.';
    END IF;
END
SQL);
    }
};
