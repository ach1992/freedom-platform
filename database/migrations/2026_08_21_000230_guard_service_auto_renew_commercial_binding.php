<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS sara_commercial_binding_guard
BEFORE UPDATE ON service_auto_renew_attempts
FOR EACH ROW
BEGIN
    IF NEW.quote_id IS NOT NULL
       AND (
           NOT (OLD.quote_id <=> NEW.quote_id)
           OR NOT (OLD.current_price_irr <=> NEW.current_price_irr)
       )
       AND NOT EXISTS (
           SELECT 1
           FROM quotes q
           INNER JOIN service_auto_renew_configurations c
               ON c.id = NEW.auto_renew_configuration_id
           INNER JOIN service_subscriptions s
               ON s.id = NEW.service_subscription_id
           WHERE q.id = NEW.quote_id
             AND q.action_snapshot = 'renew'
             AND q.user_id = s.user_id
             AND q.service_subscription_id = NEW.service_subscription_id
             AND q.service_subscription_public_id = s.public_id
             AND q.service_target_id_snapshot = s.service_target_id
             AND q.service_remote_identity_generation_snapshot = NEW.remote_identity_generation
             AND q.service_remote_identity_generation_snapshot = s.remote_identity_generation
             AND q.service_lifecycle_version_snapshot = s.lifecycle_version
             AND q.service_package_id_snapshot = c.renewal_package_id
             AND q.service_package_type_snapshot = 'renewal'
             AND q.final_price_irr = NEW.current_price_irr
             AND q.currency = 'IRR'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Quote does not match current Service renewal authority.';
    END IF;

    IF NEW.payment_intent_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1
           FROM payment_intents pi
           INNER JOIN service_subscriptions s
               ON s.id = NEW.service_subscription_id
           WHERE pi.id = NEW.payment_intent_id
             AND pi.purpose = 'purchase'
             AND pi.user_id = s.user_id
             AND pi.source_quote_id = NEW.quote_id
             AND pi.payment_eligibility_decision_id = NEW.payment_eligibility_decision_id
             AND pi.payment_method_code = 'wallet'
             AND pi.amount_irr = NEW.current_price_irr
             AND pi.currency = 'IRR'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Auto-renew Payment Intent does not match Service commercial authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        $this->assertRollbackSafe();
        DB::unprepared('DROP TRIGGER IF EXISTS sara_commercial_binding_guard');
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
};
