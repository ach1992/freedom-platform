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
        foreach ([
            ['plan_offering_auto_renew_policies', 'sarp_mode_chk', "`price_change_mode` IN ('stop','continue','within_limit')"],
            ['plan_offering_auto_renew_policies', 'sarp_abs_chk', '`absolute_increase_limit_irr` IS NULL OR `absolute_increase_limit_irr` >= 0'],
            ['plan_offering_auto_renew_policies', 'sarp_pct_chk', '`percentage_increase_limit_bps` IS NULL OR (`percentage_increase_limit_bps` >= 0 AND `percentage_increase_limit_bps` <= 1000000)'],
            ['plan_offering_auto_renew_policies', 'sarp_limits_chk', "(`price_change_mode` = 'within_limit' AND (`absolute_increase_limit_irr` IS NOT NULL OR `percentage_increase_limit_bps` IS NOT NULL)) OR (`price_change_mode` <> 'within_limit' AND `absolute_increase_limit_irr` IS NULL AND `percentage_increase_limit_bps` IS NULL)"],
            ['plan_offering_auto_renew_policies', 'sarp_version_chk', '`version` >= 1'],
            ['plan_offering_auto_renew_policy_histories', 'sarph_mode_chk', "`price_change_mode` IN ('stop','continue','within_limit')"],
            ['plan_offering_auto_renew_policy_histories', 'sarph_abs_chk', '`absolute_increase_limit_irr` IS NULL OR `absolute_increase_limit_irr` >= 0'],
            ['plan_offering_auto_renew_policy_histories', 'sarph_pct_chk', '`percentage_increase_limit_bps` IS NULL OR (`percentage_increase_limit_bps` >= 0 AND `percentage_increase_limit_bps` <= 1000000)'],
            ['plan_offering_auto_renew_policy_histories', 'sarph_limits_chk', "(`price_change_mode` = 'within_limit' AND (`absolute_increase_limit_irr` IS NOT NULL OR `percentage_increase_limit_bps` IS NOT NULL)) OR (`price_change_mode` <> 'within_limit' AND `absolute_increase_limit_irr` IS NULL AND `percentage_increase_limit_bps` IS NULL)"],
            ['plan_offering_auto_renew_policy_histories', 'sarph_hash_chk', "`request_key_hash` REGEXP '^[0-9a-f]{64}$' AND `payload_hash` REGEXP '^[0-9a-f]{64}$'"],
            ['service_auto_renew_configurations', 'sarc_price_chk', '`accepted_price_irr` >= 0 AND (`last_settled_price_irr` IS NULL OR `last_settled_price_irr` >= 0)'],
            ['service_auto_renew_configurations', 'sarc_version_chk', '`configuration_version` >= 1'],
            ['service_auto_renew_configurations', 'sarc_observation_chk', "(`observed_expires_at` IS NULL AND `expiry_observed_at` IS NULL AND `observed_expiry_evidence_hash` IS NULL AND `observed_expiry_source` IS NULL AND `observed_remote_identity_generation` IS NULL) OR (`observed_expires_at` IS NOT NULL AND `expiry_observed_at` IS NOT NULL AND `observed_expiry_evidence_hash` REGEXP '^[0-9a-f]{64}$' AND `observed_expiry_source` IN ('remote_snapshot','paid_mutation') AND `observed_remote_identity_generation` >= 1)"],
            ['service_auto_renew_configurations', 'sarc_enabled_observation_chk', '`enabled` = 0 OR `observed_expires_at` IS NOT NULL'],
            ['service_auto_renew_configuration_histories', 'sarch_price_chk', '`accepted_price_irr` >= 0'],
            ['service_auto_renew_configuration_histories', 'sarch_observation_chk', "(`observed_expires_at` IS NULL AND `observed_expiry_evidence_hash` IS NULL AND `observed_expiry_source` IS NULL AND `observed_remote_identity_generation` IS NULL) OR (`observed_expires_at` IS NOT NULL AND `observed_expiry_evidence_hash` REGEXP '^[0-9a-f]{64}$' AND `observed_expiry_source` IN ('remote_snapshot','paid_mutation') AND `observed_remote_identity_generation` >= 1)"],
            ['service_auto_renew_configuration_histories', 'sarch_hash_chk', "`request_key_hash` REGEXP '^[0-9a-f]{64}$' AND `payload_hash` REGEXP '^[0-9a-f]{64}$'"],
            ['service_auto_renew_attempts', 'sara_state_chk', "`state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed')"],
            ['service_auto_renew_attempts', 'sara_cycle_chk', "`cycle_key` REGEXP '^[0-9a-f]{64}$'"],
            ['service_auto_renew_attempts', 'sara_evidence_chk', "`observed_expiry_evidence_hash` REGEXP '^[0-9a-f]{64}$' AND `observed_expiry_source` IN ('remote_snapshot','paid_mutation')"],
            ['service_auto_renew_attempts', 'sara_price_chk', '`baseline_price_irr` >= 0 AND (`current_price_irr` IS NULL OR `current_price_irr` >= 0)'],
            ['service_auto_renew_attempts', 'sara_refs_chk', '(`payment_eligibility_decision_id` IS NULL OR `quote_id` IS NOT NULL) AND (`payment_intent_id` IS NULL OR `payment_eligibility_decision_id` IS NOT NULL) AND (`purchase_settlement_id` IS NULL OR `payment_intent_id` IS NOT NULL) AND (`provisioning_operation_id` IS NULL OR `purchase_settlement_id` IS NOT NULL)'],
            ['service_auto_renew_attempts', 'sara_complete_chk', "(`state` IN ('price_change_blocked','succeeded','failed') AND `completed_at` IS NOT NULL) OR (`state` NOT IN ('price_change_blocked','succeeded','failed') AND `completed_at` IS NULL)"],
            ['service_auto_renew_attempts', 'sara_retry_chk', "(`state` = 'retry_pending' AND `next_retry_at` IS NOT NULL AND (`retry_count` >= 1 OR (`retry_count` = 0 AND (`reason_code` = 'renewal_window_unsafe' OR `quote_id` IS NOT NULL)))) OR (`state` = 'insufficient_wallet' AND `next_retry_at` IS NOT NULL AND `retry_count` >= 1) OR (`state` NOT IN ('retry_pending','insufficient_wallet') AND `next_retry_at` IS NULL)"],
            ['service_auto_renew_attempt_events', 'sarae_from_chk', "`from_state` IS NULL OR `from_state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed')"],
            ['service_auto_renew_attempt_events', 'sarae_to_chk', "`to_state` IN ('pending','price_change_blocked','insufficient_wallet','retry_pending','settled','mutation_queued','succeeded','failed')"],
            ['service_auto_renew_notification_intents', 'sarni_outcome_chk', "`outcome` IN ('success','insufficient_wallet','price_change_blocked','failure')"],
        ] as [$table, $constraint, $definition]) {
            $this->addCheckIfMissing($table, $constraint, $definition);
        }
    }

    private function addCheckIfMissing(string $table, string $constraint, string $definition): void
    {
        $row = DB::selectOne(
            "SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $constraint],
        );

        if ($row !== null && (int) $row->aggregate > 0) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$definition})");
    }
};
