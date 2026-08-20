<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement AGT-003 AGT-004 BUY-001 BUY-002 PAY-002 SVC-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        $this->ensureQuoteColumnsAndBindings();

        $this->replaceCheckConstraint(
            'quotes',
            'quotes_action_chk',
            "`action_snapshot` IN ('purchase','renew','add_data','add_days','add_data_days')",
        );
        $this->replaceCheckConstraint('quotes', 'quotes_service_package_shape_chk', <<<'SQL'
(
    (`action_snapshot` = 'purchase'
        AND `service_subscription_id` IS NULL
        AND `service_subscription_public_id` IS NULL
        AND `service_target_id_snapshot` IS NULL
        AND `service_remote_identity_generation_snapshot` IS NULL
        AND `service_lifecycle_version_snapshot` IS NULL
        AND `service_package_id_snapshot` IS NULL
        AND `service_package_code_snapshot` IS NULL
        AND `service_package_type_snapshot` IS NULL
        AND `service_package_duration_days_snapshot` IS NULL
        AND `service_package_data_bytes_snapshot` IS NULL
        AND `service_required_capability_code_snapshot` IS NULL)
    OR
    (`action_snapshot` <> 'purchase'
        AND `service_subscription_id` IS NOT NULL
        AND `service_subscription_public_id` IS NOT NULL
        AND `service_target_id_snapshot` IS NOT NULL
        AND `service_remote_identity_generation_snapshot` >= 1
        AND `service_lifecycle_version_snapshot` >= 0
        AND `service_package_id_snapshot` IS NOT NULL
        AND `service_package_code_snapshot` IS NOT NULL
        AND `service_package_type_snapshot` IS NOT NULL
        AND (
            (`action_snapshot` = 'renew' AND `service_package_type_snapshot` = 'renewal' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data' AND `service_package_type_snapshot` = 'add_data' AND `service_package_duration_days_snapshot` IS NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
            OR (`action_snapshot` = 'add_days' AND `service_package_type_snapshot` = 'add_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NULL)
            OR (`action_snapshot` = 'add_data_days' AND `service_package_type_snapshot` = 'add_data_days' AND `service_package_duration_days_snapshot` IS NOT NULL AND `service_package_data_bytes_snapshot` IS NOT NULL)
        ))
)
SQL);
        $this->replaceCheckConstraint(
            'quotes',
            'quotes_agent_action_match_chk',
            "`account_type_snapshot` <> 'agent' OR `agent_pricing_action_snapshot` = `action_snapshot`",
        );

        foreach ([
            ['agent_pricing_rule_versions', 'agent_price_rule_action_chk', "`action` IS NULL OR `action` IN ('purchase','renew','add_data','add_days','add_data_days')"],
            ['agent_pricing_resolutions', 'agent_price_resolution_action_chk', "`action` IN ('purchase','renew','add_data','add_days','add_data_days')"],
            ['quotes', 'quotes_agent_pricing_action_chk', "`agent_pricing_action_snapshot` IS NULL OR `agent_pricing_action_snapshot` IN ('purchase','renew','add_data','add_days','add_data_days')"],
            ['pricing_rule_versions', 'pricing_rule_versions_action_chk', "`action` IS NULL OR `action` IN ('purchase','renew','add_data','add_days','add_data_days')"],
            ['pricing_rule_resolutions', 'pricing_rule_resolutions_action_chk', "`action` IN ('purchase','renew','add_data','add_days','add_data_days')"],
            ['payment_method_eligibility_decisions', 'payment_decision_action_currency_chk', "`action_snapshot` IN ('purchase','renew','add_data','add_days','add_data_days') AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0"],
        ] as [$table, $constraint, $definition]) {
            $this->replaceCheckConstraint($table, $constraint, $definition);
        }

        $this->replaceQuoteInsertGuard();
        $this->replacePaymentDecisionInsertGuard();
        $this->replacePaymentIntentInsertGuard();
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotes', 'action_snapshot')
            && DB::table('quotes')->where('action_snapshot', '<>', 'purchase')->exists()) {
            throw new RuntimeException('Cannot roll back Service package Quote authority while Service-operation Quotes exist.');
        }
        foreach (['agent_pricing_rule_versions', 'agent_pricing_resolutions', 'pricing_rule_versions', 'pricing_rule_resolutions'] as $table) {
            if (DB::table($table)->where('action', 'add_data_days')->exists()) {
                throw new RuntimeException('Cannot roll back Service package Quote authority while combined-action pricing evidence exists.');
            }
        }
        if (DB::table('payment_method_eligibility_decisions')->where('action_snapshot', 'add_data_days')->exists()) {
            throw new RuntimeException('Cannot roll back Service package Quote authority while combined-action payment decisions exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS quotes_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_method_eligibility_decisions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');

        foreach ([
            ['agent_pricing_rule_versions', 'agent_price_rule_action_chk', "`action` IS NULL OR `action` IN ('purchase','renew','add_data','add_days')"],
            ['agent_pricing_resolutions', 'agent_price_resolution_action_chk', "`action` IN ('purchase','renew','add_data','add_days')"],
            ['quotes', 'quotes_agent_pricing_action_chk', "`agent_pricing_action_snapshot` IS NULL OR `agent_pricing_action_snapshot` IN ('purchase','renew','add_data','add_days')"],
            ['pricing_rule_versions', 'pricing_rule_versions_action_chk', "`action` IS NULL OR `action` IN ('purchase','renew','add_data','add_days')"],
            ['pricing_rule_resolutions', 'pricing_rule_resolutions_action_chk', "`action` IN ('purchase','renew','add_data','add_days')"],
            ['payment_method_eligibility_decisions', 'payment_decision_action_currency_chk', "`action_snapshot` = 'purchase' AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0"],
        ] as [$table, $constraint, $definition]) {
            $this->replaceCheckConstraint($table, $constraint, $definition);
        }

        foreach (['quotes_agent_action_match_chk', 'quotes_service_package_shape_chk', 'quotes_action_chk'] as $constraint) {
            $this->dropConstraintIfExists('quotes', $constraint);
        }
        if ($this->indexExists('quotes', 'quotes_service_action_idx')) {
            DB::statement('ALTER TABLE quotes DROP INDEX quotes_service_action_idx');
        }
        if ($this->constraintExists('quotes', 'quotes_service_subscription_fk')) {
            DB::statement('ALTER TABLE quotes DROP FOREIGN KEY quotes_service_subscription_fk');
        }
        if (Schema::hasColumn('quotes', 'action_snapshot')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->dropColumn([
                    'action_snapshot',
                    'service_subscription_id',
                    'service_subscription_public_id',
                    'service_target_id_snapshot',
                    'service_remote_identity_generation_snapshot',
                    'service_lifecycle_version_snapshot',
                    'service_package_id_snapshot',
                    'service_package_code_snapshot',
                    'service_package_type_snapshot',
                    'service_package_duration_days_snapshot',
                    'service_package_data_bytes_snapshot',
                    'service_required_capability_code_snapshot',
                ]);
            });
        }

        $this->restoreLegacyQuoteInsertGuard();
        $this->restoreLegacyPaymentDecisionInsertGuard();
        $this->replacePaymentIntentInsertGuard(true);
    }

    private function ensureQuoteColumnsAndBindings(): void
    {
        if (! Schema::hasColumn('quotes', 'action_snapshot')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->string('action_snapshot', 32)->default('purchase')->after('account_type_snapshot');
                $table->foreignId('service_subscription_id')->nullable()->after('agent_discount_combination_allowed');
                $table->ulid('service_subscription_public_id')->nullable()->after('service_subscription_id');
                $table->unsignedBigInteger('service_target_id_snapshot')->nullable()->after('service_subscription_public_id');
                $table->unsignedBigInteger('service_remote_identity_generation_snapshot')->nullable()->after('service_target_id_snapshot');
                $table->unsignedBigInteger('service_lifecycle_version_snapshot')->nullable()->after('service_remote_identity_generation_snapshot');
                $table->unsignedBigInteger('service_package_id_snapshot')->nullable()->after('service_lifecycle_version_snapshot');
                $table->string('service_package_code_snapshot', 64)->nullable()->after('service_package_id_snapshot');
                $table->string('service_package_type_snapshot', 32)->nullable()->after('service_package_code_snapshot');
                $table->unsignedInteger('service_package_duration_days_snapshot')->nullable()->after('service_package_type_snapshot');
                $table->unsignedBigInteger('service_package_data_bytes_snapshot')->nullable()->after('service_package_duration_days_snapshot');
                $table->string('service_required_capability_code_snapshot', 64)->nullable()->after('service_package_data_bytes_snapshot');
            });
        }

        foreach ([
            'action_snapshot', 'service_subscription_id', 'service_subscription_public_id', 'service_target_id_snapshot',
            'service_remote_identity_generation_snapshot', 'service_lifecycle_version_snapshot', 'service_package_id_snapshot',
            'service_package_code_snapshot', 'service_package_type_snapshot', 'service_package_duration_days_snapshot',
            'service_package_data_bytes_snapshot', 'service_required_capability_code_snapshot',
        ] as $column) {
            if (! Schema::hasColumn('quotes', $column)) {
                throw new RuntimeException('Service package Quote migration is partially applied and cannot be safely normalized.');
            }
        }

        if (! $this->constraintExists('quotes', 'quotes_service_subscription_fk')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->foreign('service_subscription_id', 'quotes_service_subscription_fk')
                    ->references('id')->on('service_subscriptions')->restrictOnDelete();
            });
        }
        if (! $this->indexExists('quotes', 'quotes_service_action_idx')) {
            Schema::table('quotes', function (Blueprint $table): void {
                $table->index(['service_subscription_id', 'action_snapshot', 'created_at'], 'quotes_service_action_idx');
            });
        }
    }

    private function replaceCheckConstraint(string $table, string $constraint, string $definition): void
    {
        $this->dropConstraintIfExists($table, $constraint);
        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK ({$definition})");
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        );

        return $row !== null && (int) $row->aggregate > 0;
    }

    private function replaceQuoteInsertGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_insert_guard
BEFORE INSERT ON quotes
FOR EACH ROW
BEGIN
    DECLARE valid_user_count INT DEFAULT 0;
    DECLARE valid_offering_count INT DEFAULT 0;
    DECLARE valid_override_count INT DEFAULT 0;
    DECLARE valid_service_package_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_user_count
    FROM users u
    WHERE u.id = NEW.user_id
      AND u.account_status = 'active'
      AND u.account_type = NEW.account_type_snapshot;
    IF valid_user_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote requires one active matching pricing subject.';
    END IF;

    SELECT COUNT(*) INTO valid_offering_count
    FROM plan_offerings o
    INNER JOIN plan_offering_histories h
        ON h.plan_offering_id = o.id
       AND h.version = NEW.offering_version
       AND h.to_configuration_hash = NEW.offering_configuration_hash
    WHERE o.id = NEW.plan_offering_id
      AND o.code = NEW.offering_code_snapshot
      AND o.version = NEW.offering_version
      AND (NEW.action_snapshot <> 'purchase' OR o.base_price_irr = NEW.base_price_irr)
      AND (NEW.action_snapshot <> 'purchase' OR o.discount_eligible = NEW.offering_discount_eligible);
    IF valid_offering_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering snapshot is not current.';
    END IF;

    IF NEW.action_snapshot <> 'purchase' THEN
        SELECT COUNT(*) INTO valid_service_package_count
        FROM service_subscriptions service_row
        INNER JOIN order_items original_item ON original_item.id = service_row.order_item_id
        INNER JOIN plan_offering_packages package_row
            ON package_row.id = NEW.service_package_id_snapshot
           AND package_row.plan_offering_id = NEW.plan_offering_id
           AND package_row.code = NEW.service_package_code_snapshot
           AND package_row.package_type = NEW.service_package_type_snapshot
           AND package_row.price_irr = NEW.base_price_irr
           AND (package_row.duration_days <=> NEW.service_package_duration_days_snapshot)
           AND (package_row.data_bytes <=> NEW.service_package_data_bytes_snapshot)
        INNER JOIN plan_offering_operations operation_row
            ON operation_row.plan_offering_id = NEW.plan_offering_id
           AND operation_row.operation_code = NEW.action_snapshot
           AND operation_row.customer_enabled = 1
           AND (operation_row.required_capability_code <=> NEW.service_required_capability_code_snapshot)
        WHERE service_row.id = NEW.service_subscription_id
          AND service_row.public_id = NEW.service_subscription_public_id
          AND service_row.user_id = NEW.user_id
          AND original_item.plan_offering_id = NEW.plan_offering_id
          AND service_row.lifecycle_state IN ('active','suspended')
          AND service_row.remote_deleted_at IS NULL
          AND service_row.provisioned_at IS NOT NULL
          AND service_row.service_target_id = NEW.service_target_id_snapshot
          AND service_row.remote_identity_generation = NEW.service_remote_identity_generation_snapshot
          AND service_row.lifecycle_version = NEW.service_lifecycle_version_snapshot
          AND service_row.remote_service_id IS NOT NULL
          AND NEW.offering_discount_eligible = (
              (SELECT offering_row.discount_eligible FROM plan_offerings offering_row WHERE offering_row.id = NEW.plan_offering_id)
              AND package_row.discount_eligible
              AND operation_row.discount_eligible
          )
          AND (
              (NEW.action_snapshot IN ('renew','add_days') AND EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability
                  WHERE capability.panel_service_target_id = service_row.service_target_id
                    AND capability.capability_code = 'update_expiry'
                    AND capability.verification_status = 'verified'
              ))
              OR (NEW.action_snapshot = 'add_data' AND EXISTS (
                  SELECT 1 FROM panel_target_capabilities capability
                  WHERE capability.panel_service_target_id = service_row.service_target_id
                    AND capability.capability_code = 'add_data_allowance'
                    AND capability.verification_status = 'verified'
              ))
              OR (NEW.action_snapshot = 'add_data_days'
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'update_expiry'
                        AND capability.verification_status = 'verified'
                  )
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'add_data_allowance'
                        AND capability.verification_status = 'verified'
                  )
                  AND EXISTS (
                      SELECT 1 FROM panel_target_capabilities capability
                      WHERE capability.panel_service_target_id = service_row.service_target_id
                        AND capability.capability_code = 'atomic_service_entitlements'
                        AND capability.verification_status = 'verified'
                  ))
          )
          AND (operation_row.required_capability_code IS NULL OR EXISTS (
              SELECT 1 FROM panel_target_capabilities policy_capability
              WHERE policy_capability.panel_service_target_id = service_row.service_target_id
                AND policy_capability.capability_code = operation_row.required_capability_code
                AND policy_capability.verification_status = 'verified'
          ));
        IF valid_service_package_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service package Quote authority is stale or invalid.';
        END IF;
    END IF;

    IF NEW.discount_irr > 0 AND NEW.offering_discount_eligible <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering does not allow discounts.';
    END IF;

    IF NEW.override_source = 'agent' THEN
        SELECT COUNT(*) INTO valid_override_count FROM agent_profiles a
        WHERE a.user_id = NEW.user_id AND a.status = 'active' AND a.pricing_profile_code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote agent override reference is not current.'; END IF;
    ELSEIF NEW.override_source = 'tier' THEN
        SELECT COUNT(*) INTO valid_override_count
        FROM customer_profiles p INNER JOIN customer_tiers t ON t.id = p.current_tier_id
        WHERE p.user_id = NEW.user_id AND t.is_active = 1 AND t.code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote tier override reference is not current.'; END IF;
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote configuration snapshot hash mismatch.';
    END IF;
END
SQL);
    }

    private function replacePaymentDecisionInsertGuard(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS payment_method_eligibility_decisions_insert_guard');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decisions_insert_guard
BEFORE INSERT ON payment_method_eligibility_decisions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM quotes q INNER JOIN users u ON u.id = q.user_id
        WHERE q.id = NEW.source_quote_id AND q.public_id = NEW.source_quote_public_id
          AND q.user_id = NEW.user_id AND q.action_snapshot = NEW.action_snapshot
          AND q.currency = NEW.currency_snapshot AND q.final_price_irr = NEW.amount_irr_snapshot
          AND q.expires_at > NEW.created_at AND u.account_type IN ('customer', 'agent')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility source Quote is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-eligibility-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.action')) <> NEW.action_snapshot
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote.public_id')) <> NEW.source_quote_public_id
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote.currency')) <> NEW.currency_snapshot
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote.final_price_irr')) AS SIGNED) <> NEW.amount_irr_snapshot THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision snapshot is invalid.';
    END IF;
END
SQL);
    }

    private function replacePaymentIntentInsertGuard(bool $legacy = false): void
    {
        /** @var literal-string $actions */
        $actions = $legacy ? "decision_row.action_snapshot = 'purchase'" : 'decision_row.action_snapshot = quote_row.action_snapshot';
        DB::unprepared('DROP TRIGGER IF EXISTS payment_intents_insert_guard');
        /** @var literal-string $guardSql */
        $guardSql = str_replace('__ACTION_AUTHORITY__', $actions, <<<'SQL'
CREATE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_decision_count INT DEFAULT 0;
    DECLARE valid_method_count INT DEFAULT 0;

    IF NEW.purpose = 'wallet_top_up' THEN
        IF NEW.source_quote_id IS NOT NULL OR NEW.payment_eligibility_decision_id IS NOT NULL OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;
        SELECT COUNT(*) INTO valid_wallet_count FROM ledger_accounts
        WHERE id = NEW.wallet_account_id AND owner_user_id = NEW.user_id AND account_class = 'liability'
          AND wallet_bucket = 'cash' AND currency = 'IRR' AND is_active = 1;
        IF valid_wallet_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.'; END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.'; END IF;
        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at AND quote_row.expires_at > NEW.created_at;
        IF valid_quote_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.'; END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        INNER JOIN quotes quote_row ON quote_row.id = decision_row.source_quote_id
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND __ACTION_AUTHORITY__
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;
        IF valid_decision_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.'; END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version ON method_version.id = decision_method.payment_method_version_id
        WHERE decision_method.payment_method_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND decision_method.payment_method_version_id = NEW.payment_method_version_id
          AND decision_method.method_code = NEW.payment_method_code
          AND decision_method.method_version = NEW.payment_method_version
          AND decision_method.route_order IS NOT NULL
          AND decision_method.reason_code = 'eligible'
          AND decision_method.configuration_snapshot_hash = NEW.payment_eligibility_method_configuration_hash
          AND method_version.method_code = NEW.payment_method_code
          AND method_version.version = NEW.payment_method_version
          AND method_version.configuration_snapshot_hash = NEW.payment_method_configuration_hash
          AND NEW.provider_code = NEW.payment_method_code;
        IF valid_method_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
    END IF;
END
SQL);
        DB::unprepared($guardSql);
    }

    private function restoreLegacyQuoteInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_insert_guard BEFORE INSERT ON quotes FOR EACH ROW
BEGIN
    DECLARE valid_user_count INT DEFAULT 0; DECLARE valid_offering_count INT DEFAULT 0; DECLARE valid_override_count INT DEFAULT 0;
    SELECT COUNT(*) INTO valid_user_count FROM users u WHERE u.id=NEW.user_id AND u.account_status='active' AND u.account_type=NEW.account_type_snapshot;
    IF valid_user_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote requires one active matching pricing subject.'; END IF;
    SELECT COUNT(*) INTO valid_offering_count FROM plan_offerings o INNER JOIN plan_offering_histories h ON h.plan_offering_id=o.id AND h.version=NEW.offering_version AND h.to_configuration_hash=NEW.offering_configuration_hash
    WHERE o.id=NEW.plan_offering_id AND o.code=NEW.offering_code_snapshot AND o.version=NEW.offering_version AND o.base_price_irr=NEW.base_price_irr AND o.discount_eligible=NEW.offering_discount_eligible;
    IF valid_offering_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote offering snapshot is not current.'; END IF;
    IF NEW.discount_irr>0 AND NEW.offering_discount_eligible<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote offering does not allow discounts.'; END IF;
    IF NEW.override_source='agent' THEN SELECT COUNT(*) INTO valid_override_count FROM agent_profiles a WHERE a.user_id=NEW.user_id AND a.status='active' AND a.pricing_profile_code=NEW.override_reference_code;
      IF valid_override_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote agent override reference is not current.'; END IF;
    ELSEIF NEW.override_source='tier' THEN SELECT COUNT(*) INTO valid_override_count FROM customer_profiles p INNER JOIN customer_tiers t ON t.id=p.current_tier_id WHERE p.user_id=NEW.user_id AND t.is_active=1 AND t.code=NEW.override_reference_code;
      IF valid_override_count<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote tier override reference is not current.'; END IF;
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR),256))<>LOWER(NEW.configuration_snapshot_hash) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quote configuration snapshot hash mismatch.'; END IF;
END
SQL);
    }

    private function restoreLegacyPaymentDecisionInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decisions_insert_guard BEFORE INSERT ON payment_method_eligibility_decisions FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM quotes q INNER JOIN users u ON u.id=q.user_id WHERE q.id=NEW.source_quote_id AND q.public_id=NEW.source_quote_public_id AND q.user_id=NEW.user_id AND q.currency=NEW.currency_snapshot AND q.final_price_irr=NEW.amount_irr_snapshot AND q.expires_at>NEW.created_at AND u.account_type IN ('customer','agent')) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment eligibility source Quote is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR),256))<>LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot,'$.formula_version'))<>'pay-001-eligibility-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot,'$.action'))<>NEW.action_snapshot
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot,'$.source_quote.public_id'))<>NEW.source_quote_public_id
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot,'$.source_quote.currency'))<>NEW.currency_snapshot
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot,'$.source_quote.final_price_irr')) AS SIGNED)<>NEW.amount_irr_snapshot THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment eligibility decision snapshot is invalid.';
    END IF;
END
SQL);
    }
};
