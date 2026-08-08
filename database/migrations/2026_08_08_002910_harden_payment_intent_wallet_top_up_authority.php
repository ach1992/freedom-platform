<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 WAL-001 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intent_capture_lifecycle_chk CHECK (((`state` IN ('captured','refund_pending','refunded','partially_refunded')) AND `captured_at` IS NOT NULL) OR ((`state` NOT IN ('captured','refund_pending','refunded','partially_refunded')) AND `captured_at` IS NULL))");

        $this->replaceIntentUpdateGuard(true);
        $this->createProviderTransactionInsertGuard();
        $this->replaceSettlementInsertGuard(true);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_top_up_settlements_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_provider_transactions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT payment_intent_capture_lifecycle_chk');

        $this->replaceIntentUpdateGuard(false);
        $this->replaceSettlementInsertGuard(false);
    }

    private function replaceIntentUpdateGuard(bool $strictCaptureLifecycle): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_update_guard');

        $lines = [
            'CREATE TRIGGER payment_intents_update_guard',
            'BEFORE UPDATE ON payment_intents',
            'FOR EACH ROW',
            'BEGIN',
            '    IF NEW.public_id <> OLD.public_id',
            '       OR NEW.creation_key <> OLD.creation_key',
            '       OR NEW.payload_hash <> OLD.payload_hash',
            '       OR NEW.purpose <> OLD.purpose',
            '       OR NEW.user_id <> OLD.user_id',
            '       OR NEW.wallet_account_id <> OLD.wallet_account_id',
            '       OR NEW.provider_code <> OLD.provider_code',
            '       OR NEW.amount_irr <> OLD.amount_irr',
            '       OR NEW.currency <> OLD.currency',
            '       OR NEW.creation_correlation_id <> OLD.creation_correlation_id',
            '       OR NEW.created_at <> OLD.created_at THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent financial identity is immutable.';",
            '    END IF;',
            '',
            '    IF NEW.state <> OLD.state AND NOT (',
            "        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR",
            "        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR",
            "        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR",
            "        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR",
            "        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR",
            "        (OLD.state = 'authorized' AND NEW.state = 'captured') OR",
            "        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR",
            "        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR",
            "        (OLD.state = 'partially_refunded' AND NEW.state IN ('refund_pending','refunded'))",
            '    ) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';",
            '    END IF;',
            '',
        ];

        if ($strictCaptureLifecycle) {
            array_push(
                $lines,
                "    IF NEW.state IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NULL THEN",
                "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment lifecycle requires captured_at.';",
                '    END IF;',
                "    IF NEW.state NOT IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NOT NULL THEN",
                "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-capture payment intent cannot carry captured_at.';",
                '    END IF;',
            );
        } else {
            array_push(
                $lines,
                "    IF NEW.state = 'captured' AND NEW.captured_at IS NULL THEN",
                "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment intent requires captured_at.';",
                '    END IF;',
            );
        }

        array_push(
            $lines,
            '    IF OLD.captured_at IS NOT NULL AND NOT (NEW.captured_at <=> OLD.captured_at) THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent capture timestamp is immutable.';",
            '    END IF;',
            'END',
        );

        DB::unprepared(implode("\n", $lines));
    }

    private function createProviderTransactionInsertGuard(): void
    {
        DB::unprepared(implode("\n", [
            'CREATE TRIGGER payment_provider_transactions_insert_guard',
            'BEFORE INSERT ON payment_provider_transactions',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_event_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_event_count',
            '    FROM payment_provider_events event_row',
            '    WHERE event_row.id = NEW.provider_event_row_id',
            '      AND event_row.payment_intent_id = NEW.payment_intent_id',
            '      AND event_row.provider_code = NEW.provider_code',
            '      AND event_row.provider_transaction_id = NEW.provider_transaction_id',
            '      AND event_row.evidence_payload_hash = NEW.evidence_payload_hash',
            "      AND event_row.evidence_authority = 'authoritative'",
            "      AND event_row.transaction_status = 'settled'",
            '      AND event_row.amount_irr = NEW.amount_irr',
            '      AND event_row.currency = NEW.currency',
            '      AND event_row.settled_at IS NOT NULL;',
            '',
            '    IF valid_event_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment provider transaction requires one matching authoritative settled provider event.';",
            '    END IF;',
            'END',
        ]));
    }

    private function replaceSettlementInsertGuard(bool $strictProviderAuthority): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wallet_top_up_settlements_insert_guard');

        $providerJoin = $strictProviderAuthority
            ? '    INNER JOIN payment_provider_events event_row ON event_row.id = pt.provider_event_row_id'
            : null;
        $providerPredicates = $strictProviderAuthority
            ? [
                '      AND event_row.payment_intent_id = NEW.payment_intent_id',
                '      AND event_row.provider_code = pt.provider_code',
                '      AND event_row.provider_transaction_id = pt.provider_transaction_id',
                '      AND event_row.evidence_payload_hash = pt.evidence_payload_hash',
                "      AND event_row.evidence_authority = 'authoritative'",
                "      AND event_row.transaction_status = 'settled'",
                '      AND event_row.amount_irr = pt.amount_irr',
                '      AND event_row.currency = pt.currency',
                '      AND event_row.settled_at IS NOT NULL',
            ]
            : [];
        $clearingPredicates = $strictProviderAuthority
            ? [
                "            AND clearing.account_class = 'asset'",
                '            AND clearing.owner_user_id IS NULL',
                '            AND clearing.wallet_bucket IS NULL',
                "            AND clearing.currency = 'IRR'",
                '            AND clearing.is_active = 1',
            ]
            : [];

        $lines = [
            'CREATE TRIGGER wallet_top_up_settlements_insert_guard',
            'BEFORE INSERT ON wallet_top_up_settlements',
            'FOR EACH ROW',
            'BEGIN',
            '    DECLARE valid_intent_count INT DEFAULT 0;',
            '    DECLARE valid_provider_count INT DEFAULT 0;',
            '    DECLARE valid_ledger_count INT DEFAULT 0;',
            '',
            '    SELECT COUNT(*) INTO valid_intent_count',
            '    FROM payment_intents i',
            '    INNER JOIN ledger_accounts wallet ON wallet.id = i.wallet_account_id',
            '    WHERE i.id = NEW.payment_intent_id',
            "      AND i.purpose = 'wallet_top_up'",
            "      AND i.state = 'captured'",
            '      AND i.captured_at IS NOT NULL',
            '      AND i.wallet_account_id = NEW.wallet_account_id',
            '      AND i.amount_irr = NEW.amount_irr',
            "      AND i.currency = 'IRR'",
            '      AND wallet.owner_user_id = i.user_id',
            "      AND wallet.wallet_bucket = 'cash'",
            "      AND wallet.account_class = 'liability'",
            "      AND wallet.currency = 'IRR'",
            '      AND wallet.is_active = 1;',
            '',
            '    IF valid_intent_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement does not match one captured cash-wallet intent.';",
            '    END IF;',
            '',
            '    SELECT COUNT(*) INTO valid_provider_count',
            '    FROM payment_provider_transactions pt',
            '    INNER JOIN payment_intents i ON i.id = pt.payment_intent_id',
        ];

        if ($providerJoin !== null) {
            $lines[] = $providerJoin;
        }

        array_push(
            $lines,
            '    WHERE pt.id = NEW.provider_transaction_row_id',
            '      AND pt.payment_intent_id = NEW.payment_intent_id',
            '      AND pt.provider_code = i.provider_code',
            '      AND pt.amount_irr = NEW.amount_irr',
            "      AND pt.currency = 'IRR'",
            "      AND pt.transaction_status = 'settled'",
            '      AND pt.settled_at IS NOT NULL',
        );
        foreach ($providerPredicates as $predicate) {
            $lines[] = $predicate;
        }
        $lines[count($lines) - 1] .= ';';

        array_push(
            $lines,
            '',
            '    IF valid_provider_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement requires matching authoritative settled provider transaction evidence.';",
            '    END IF;',
            '',
            '    SELECT COUNT(*) INTO valid_ledger_count',
            '    FROM ledger_transactions t',
            '    INNER JOIN payment_intents i ON i.id = NEW.payment_intent_id',
            '    WHERE t.id = NEW.ledger_transaction_id',
            "      AND t.transaction_type = 'wallet_external_top_up'",
            "      AND t.source_type = 'payment_intent'",
            '      AND t.source_id = i.public_id',
            '      AND t.expected_total_irr = NEW.amount_irr',
            '      AND t.posted_debit_irr = NEW.amount_irr',
            '      AND t.posted_credit_irr = NEW.amount_irr',
            '      AND t.entry_count = 2',
            '      AND t.finalized_at IS NOT NULL',
            '      AND EXISTS (',
            '          SELECT 1 FROM ledger_entries wallet_entry',
            '          WHERE wallet_entry.ledger_transaction_id = t.id',
            '            AND wallet_entry.ledger_account_id = NEW.wallet_account_id',
            "            AND wallet_entry.direction = 'credit'",
            '            AND wallet_entry.amount_irr = NEW.amount_irr',
            '      )',
            '      AND EXISTS (',
            '          SELECT 1',
            '          FROM ledger_entries clearing_entry',
            '          INNER JOIN ledger_accounts clearing ON clearing.id = clearing_entry.ledger_account_id',
            '          WHERE clearing_entry.ledger_transaction_id = t.id',
            "            AND clearing.code = 'system.payment.wallet-topup.clearing'",
        );
        foreach ($clearingPredicates as $predicate) {
            $lines[] = $predicate;
        }
        array_push(
            $lines,
            "            AND clearing_entry.direction = 'debit'",
            '            AND clearing_entry.amount_irr = NEW.amount_irr',
            '      );',
            '',
            '    IF valid_ledger_count <> 1 THEN',
            "        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up settlement requires the exact finalized cash-wallet ledger effect.';",
            '    END IF;',
            'END',
        );

        DB::unprepared(implode("\n", $lines));
    }
};
