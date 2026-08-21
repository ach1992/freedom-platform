<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addChecks();
    }

    public function down(): void
    {
        // Constraints are dropped with their authority tables by the preceding schema migration.
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE plan_offering_auto_renew_policies ADD CONSTRAINT sarp_mode_chk CHECK (`price_change_mode` IN ('stop','continue','within_limit'))");
        DB::statement('ALTER TABLE plan_offering_auto_renew_policies ADD CONSTRAINT sarp_abs_chk CHECK (`absolute_increase_limit_irr` IS NULL OR `absolute_increase_limit_irr` >= 0)');
        DB::statement('ALTER TABLE plan_offering_auto_renew_policies ADD CONSTRAINT sarp_pct_chk CHECK (`percentage_increase_limit_bps` IS NULL OR `percentage_increase_limit_bps` <= 1000000)');
        DB::statement("ALTER TABLE plan_offering_auto_renew_policies ADD CONSTRAINT sarp_limits_chk CHECK ((`price_change_mode` = 'within_limit' AND (`absolute_increase_limit_irr` IS NOT NULL OR `percentage_increase_limit_bps` IS NOT NULL)) OR (`price_change_mode` <> 'within_limit' AND `absolute_increase_limit_irr` IS NULL AND `percentage_increase_limit_bps` IS NULL))");
        DB::statement('ALTER TABLE plan_offering_auto_renew_policies ADD CONSTRAINT sarp_version_chk CHECK (`version` >= 1)');

        DB::statement("ALTER TABLE plan_offering_auto_renew_policy_histories ADD CONSTRAINT sarph_mode_chk CHECK (`price_change_mode` IN ('stop','continue','within_limit'))");
        DB::statement('ALTER TABLE plan_offering_auto_renew_policy_histories ADD CONSTRAINT sarph_abs_chk CHECK (`absolute_increase_limit_irr` IS NULL OR `absolute_increase_limit_irr` >= 0)');
        DB::statement('ALTER TABLE plan_offering_auto_renew_policy_histories ADD CONSTRAINT sarph_pct_chk CHECK (`percentage_increase_limit_bps` IS NULL OR `percentage_increase_limit_bps` <= 1000000)');
        DB::statement("ALTER TABLE plan_offering_auto_renew_policy_histories ADD CONSTRAINT sarph_limits_chk CHECK ((`price_change_mode` = 'within_limit' AND (`absolute_increase_limit_irr` IS NOT NULL OR `percentage_increase_limit_bps` IS NOT NULL)) OR (`price_change_mode` <> 'within_limit' AND `absolute_increase_limit_irr` IS NULL AND `percentage_increase_limit_bps` IS NULL))");
        DB::statement('ALTER TABLE plan_offering_auto_renew_policy_histories ADD CONSTRAINT sarph_hash_chk CHECK (`request_key_hash` REGEXP \'^[0-9a-f]{64}$\' AND `payload_hash` REGEXP \'^[0-9a-f]{64}$\')');

        DB::statement('ALTER TABLE service_auto_renew_configurations ADD CONSTRAINT sarc_price_chk CHECK (`accepted_price_irr` >= 0 AND (`last_settled_price_irr` IS NULL OR `last_settled_price_irr` >= 0))');
        DB::statement('ALTER TABLE service_auto_renew_configurations ADD CONSTRAINT sarc_version_chk CHECK (`configuration_version` >= 1)');
        DB::statement("ALTER TABLE service_auto_renew_configurations ADD CONSTRAINT sarc_observation_chk CHECK ((`observed_expires_at` IS NULL AND `expiry_observed_at` IS NULL AND `observed_expiry_evidence_hash` IS NULL AND `observed_expiry_source` IS NULL AND `observed_remote_identity_generation` IS NULL) OR (`observed_expires_at` IS NOT NULL AND `expiry_observed_at` IS NOT NULL AND `observed_expiry_evidence_hash` REGEXP '^[0-9a-f]{64}$' AND `observed_expiry_source` IN ('remote_snapshot','paid_mutation') AND `observed_remote_identity_generation` >= 1))");
        DB::statement('ALTER TABLE service_auto_renew_configurations ADD CONSTRAINT sarc_enabled_observation_chk CHECK (`enabled` = 0 OR `observed_expires_at` IS NOT NULL)');

        DB::statement('ALTER TABLE service_auto_renew_configuration_histories ADD CONSTRAINT sarch_price_chk CHECK (`accepted_price_irr` >= 0)');
        DB::statement("ALTER TABLE service_auto_renew_configuration_histories ADD CONSTRAINT sarch_observation_chk CHECK ((`observed_expires_at` IS NULL AND `observed_expiry_evidence_hash` IS NULL AND `observed_expiry_source` IS NULL AND `observed_remote_identity_generation` IS NULL) OR (`observed_expires_at` IS NOT NULL AND `observed_expiry_evidence_hash` REGEXP '^[0-9a-f]{64}$' AND `observed_expiry_source` IN ('remote_snapshot','paid_mutation') AND `observed_remote_identity_generation` >= 1))");
        DB::statement('ALTER TABLE service_auto_renew_configuration_histories ADD CONSTRAINT sarch_hash_chk CHECK (`request_key_hash` REGEXP \'^[0-9a-f]{64}$\' AND `payload_hash` REGEXP \'^[0-9a-f]{64}$\')');

        DB::statement("ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_state_chk CHECK (`state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed'))");
        DB::statement('ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_cycle_chk CHECK (`cycle_key` REGEXP \'^[0-9a-f]{64}$\')');
        DB::statement('ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_evidence_chk CHECK (`observed_expiry_evidence_hash` REGEXP \'^[0-9a-f]{64}$\' AND `observed_expiry_source` IN (\'remote_snapshot\',\'paid_mutation\'))');
        DB::statement('ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_price_chk CHECK (`baseline_price_irr` >= 0 AND (`current_price_irr` IS NULL OR `current_price_irr` >= 0))');
        DB::statement('ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_refs_chk CHECK ((`payment_eligibility_decision_id` IS NULL OR `quote_id` IS NOT NULL) AND (`payment_intent_id` IS NULL OR `payment_eligibility_decision_id` IS NOT NULL) AND (`purchase_settlement_id` IS NULL OR `payment_intent_id` IS NOT NULL) AND (`provisioning_operation_id` IS NULL OR `purchase_settlement_id` IS NOT NULL))');
        DB::statement("ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_complete_chk CHECK ((`state` IN ('price_change_blocked','succeeded','failed') AND `completed_at` IS NOT NULL) OR (`state` NOT IN ('price_change_blocked','succeeded','failed') AND `completed_at` IS NULL))");
        DB::statement("ALTER TABLE service_auto_renew_attempts ADD CONSTRAINT sara_retry_chk CHECK ((`state` IN ('retry_pending','insufficient_wallet') AND `next_retry_at` IS NOT NULL AND `retry_count` >= 1) OR (`state` NOT IN ('retry_pending','insufficient_wallet') AND `next_retry_at` IS NULL))");

        DB::statement("ALTER TABLE service_auto_renew_attempt_events ADD CONSTRAINT sarae_from_chk CHECK (`from_state` IS NULL OR `from_state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed'))");
        DB::statement("ALTER TABLE service_auto_renew_attempt_events ADD CONSTRAINT sarae_to_chk CHECK (`to_state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed'))");
        DB::statement("ALTER TABLE service_auto_renew_notification_intents ADD CONSTRAINT sarni_outcome_chk CHECK (`outcome` IN ('success','insufficient_wallet','price_change_blocked','failure'))");
    }
};
