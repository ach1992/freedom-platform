<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement IPG-001 PAY-002 PAY-003 DAT-003 DAT-004 QUA-004 */
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_requests_insert_guard
BEFORE INSERT ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    DECLARE matching_intent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state = 'awaiting_user_action'
      AND intent_row.captured_at IS NULL;

    IF matching_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request requires one matching purchase payment intent awaiting user action.';
    END IF;
    IF NEW.state <> 'initiating' OR NEW.authority IS NOT NULL OR NEW.authority_received_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request must start in initiating state without authority.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS zarinpal_payment_requests_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER zarinpal_payment_requests_insert_guard
BEFORE INSERT ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    DECLARE matching_intent_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_intent_count
    FROM payment_intents intent_row
    WHERE intent_row.id = NEW.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.provider_code = 'zarinpal'
      AND intent_row.amount_irr = NEW.amount_irr
      AND intent_row.currency = NEW.currency
      AND intent_row.state = 'created'
      AND intent_row.captured_at IS NULL;

    IF matching_intent_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request requires one matching created purchase payment intent.';
    END IF;
    IF NEW.state <> 'initiating' OR NEW.authority IS NOT NULL OR NEW.authority_received_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal request must start in initiating state without authority.';
    END IF;
END
SQL);
    }
};
