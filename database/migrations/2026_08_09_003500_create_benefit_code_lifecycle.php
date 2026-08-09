<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FUNDING_ACCOUNT_CODE = 'system.benefit-code.promotional-funding';

    /** @requirement PRO-002 WAL-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function up(): void
    {
        Schema::create('benefit_code_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('campaign_code', 64)->unique();
            $table->string('type', 24);
            $table->dateTime('created_at', 6);
        });

        Schema::create('benefit_code_campaign_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('benefit_code_campaign_id')->constrained('benefit_code_campaigns')->restrictOnDelete();
            $table->string('mutation_key', 128)->unique();
            $table->char('mutation_payload_hash', 64);
            $table->unsignedBigInteger('version');
            $table->string('state', 16);
            $table->string('audience', 16);
            $table->boolean('single_use');
            $table->unsignedBigInteger('total_use_limit')->nullable();
            $table->unsignedBigInteger('per_user_use_limit')->nullable();
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_until', 6)->nullable();
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('sales_server_id')->nullable()->constrained('sales_servers')->restrictOnDelete();
            $table->bigInteger('wallet_credit_irr')->nullable();
            $table->foreignId('discount_pricing_rule_id')->nullable()->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('discount_pricing_rule_version_id')->nullable()->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['benefit_code_campaign_id', 'version'], 'benefit_code_campaign_version_unique');
            $table->index(['state', 'benefit_code_campaign_id', 'version'], 'benefit_code_campaign_current_idx');
        });

        Schema::create('benefit_code_issuances', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('issuance_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('benefit_code_campaign_id')->constrained('benefit_code_campaigns')->restrictOnDelete();
            $table->foreignId('benefit_code_campaign_version_id')->constrained('benefit_code_campaign_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->boolean('owner_chosen');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['benefit_code_campaign_id', 'created_at'], 'benefit_code_issuance_campaign_idx');
        });

        Schema::create('benefit_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('benefit_code_issuance_id')->constrained('benefit_code_issuances')->restrictOnDelete();
            $table->foreignId('benefit_code_campaign_id')->constrained('benefit_code_campaigns')->restrictOnDelete();
            $table->foreignId('benefit_code_campaign_version_id')->constrained('benefit_code_campaign_versions')->restrictOnDelete();
            $table->char('lookup_hash', 64)->unique();
            $table->unsignedSmallInteger('key_version');
            $table->string('display_mask', 32);
            $table->dateTime('created_at', 6);
            $table->index(['benefit_code_campaign_id', 'created_at'], 'benefit_code_campaign_code_idx');
        });

        Schema::create('benefit_code_disables', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('mutation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('benefit_code_id')->unique()->constrained('benefit_codes')->restrictOnDelete();
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
        });

        Schema::create('benefit_code_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('redemption_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('benefit_code_id')->constrained('benefit_codes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('benefit_code_campaign_id')->constrained('benefit_code_campaigns')->restrictOnDelete();
            $table->foreignId('benefit_code_campaign_version_id')->constrained('benefit_code_campaign_versions')->restrictOnDelete();
            $table->string('campaign_code_snapshot', 64);
            $table->unsignedBigInteger('campaign_version');
            $table->string('type_snapshot', 24);
            $table->foreignId('plan_offering_id')->nullable()->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('promotional_wallet_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['benefit_code_id', 'created_at'], 'benefit_code_redemption_code_idx');
            $table->index(['benefit_code_id', 'user_id', 'created_at'], 'benefit_code_redemption_user_idx');
        });

        Schema::create('benefit_code_free_service_entitlements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('benefit_code_redemption_id')->unique()->constrained('benefit_code_redemptions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'benefit_code_entitlement_user_idx');
        });

        Schema::create('benefit_code_discount_grants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('benefit_code_redemption_id')->unique()->constrained('benefit_code_redemptions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pricing_rule_id')->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('pricing_rule_version_id')->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->string('rule_code_snapshot', 128);
            $table->unsignedBigInteger('rule_version');
            $table->char('rule_configuration_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'benefit_code_discount_grant_user_idx');
        });

        $this->addChecks();
        $this->createGuards();
        $this->ensurePromotionalFundingAccount();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('benefit_code_discount_grants');
        Schema::dropIfExists('benefit_code_free_service_entitlements');
        Schema::dropIfExists('benefit_code_redemptions');
        Schema::dropIfExists('benefit_code_disables');
        Schema::dropIfExists('benefit_codes');
        Schema::dropIfExists('benefit_code_issuances');
        Schema::dropIfExists('benefit_code_campaign_versions');
        Schema::dropIfExists('benefit_code_campaigns');
        DB::table('ledger_accounts')->where('code', self::FUNDING_ACCOUNT_CODE)->update([
            'is_active' => false,
            'updated_at' => now('UTC'),
        ]);
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE benefit_code_campaigns ADD CONSTRAINT benefit_code_campaign_type_chk CHECK (`type` IN ('wallet_credit', 'free_service', 'discount_grant'))");
        DB::statement("ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_state_chk CHECK (`state` IN ('draft', 'active', 'disabled', 'archived'))");
        DB::statement("ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_audience_chk CHECK (`audience` IN ('customers', 'agents', 'both'))");
        DB::statement('ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_limits_chk CHECK ((`total_use_limit` IS NULL OR `total_use_limit` >= 1) AND (`per_user_use_limit` IS NULL OR `per_user_use_limit` >= 1) AND (`total_use_limit` IS NULL OR `per_user_use_limit` IS NULL OR `per_user_use_limit` <= `total_use_limit`) AND (`single_use` = 0 OR `total_use_limit` = 1))');
        DB::statement('ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_window_chk CHECK (`effective_from` IS NULL OR `effective_until` IS NULL OR `effective_until` > `effective_from`)');
        DB::statement('ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_wallet_amount_chk CHECK (`wallet_credit_irr` IS NULL OR `wallet_credit_irr` >= 1)');
        DB::statement("ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_hashes_chk CHECK (`mutation_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE benefit_code_campaign_versions ADD CONSTRAINT benefit_code_campaign_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
        DB::statement('ALTER TABLE benefit_code_issuances ADD CONSTRAINT benefit_code_issuance_quantity_chk CHECK (`quantity` BETWEEN 1 AND 500)');
        DB::statement("ALTER TABLE benefit_code_issuances ADD CONSTRAINT benefit_code_issuance_hash_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE benefit_codes ADD CONSTRAINT benefit_code_lookup_hash_chk CHECK (`lookup_hash` REGEXP '^[0-9a-f]{64}$' AND `key_version` >= 1)");
        DB::statement("ALTER TABLE benefit_codes ADD CONSTRAINT benefit_code_mask_chk CHECK (`display_mask` REGEXP '^[A-HJ-NP-Z2-9]{4}-\\*{4}-[A-HJ-NP-Z2-9]{4}$')");
        DB::statement("ALTER TABLE benefit_code_disables ADD CONSTRAINT benefit_code_disable_hash_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE benefit_code_redemptions ADD CONSTRAINT benefit_code_redemption_type_chk CHECK (`type_snapshot` IN ('wallet_credit', 'free_service', 'discount_grant'))");
        DB::statement("ALTER TABLE benefit_code_redemptions ADD CONSTRAINT benefit_code_redemption_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE benefit_code_redemptions ADD CONSTRAINT benefit_code_redemption_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
        DB::statement("ALTER TABLE benefit_code_free_service_entitlements ADD CONSTRAINT benefit_code_entitlement_hash_chk CHECK (`configuration_hash` REGEXP '^[0-9a-f]{64}$' AND JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
        DB::statement("ALTER TABLE benefit_code_discount_grants ADD CONSTRAINT benefit_code_discount_grant_hash_chk CHECK (`rule_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `rule_version` >= 1 AND JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
    }

    private function createGuards(): void
    {
        $this->immutableTrigger('benefit_code_campaigns', 'Benefit code campaign identity');
        $this->immutableTrigger('benefit_code_issuances', 'Benefit code issuance');
        $this->immutableTrigger('benefit_codes', 'Benefit code identity');
        $this->immutableTrigger('benefit_code_disables', 'Benefit code disable history');
        $this->immutableTrigger('benefit_code_redemptions', 'Benefit code redemption history');
        $this->immutableTrigger('benefit_code_free_service_entitlements', 'Benefit code free-service entitlement');
        $this->immutableTrigger('benefit_code_discount_grants', 'Benefit code discount grant');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_code_campaign_versions_insert_guard
BEFORE INSERT ON benefit_code_campaign_versions FOR EACH ROW
BEGIN
    DECLARE parent_type VARCHAR(24);
    DECLARE latest_version BIGINT UNSIGNED;

    SELECT type INTO parent_type FROM benefit_code_campaigns WHERE id = NEW.benefit_code_campaign_id;
    IF parent_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign parent does not exist.';
    END IF;
    SELECT COALESCE(MAX(version), 0) INTO latest_version FROM benefit_code_campaign_versions WHERE benefit_code_campaign_id = NEW.benefit_code_campaign_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign configuration hash mismatch.';
    END IF;
    IF parent_type = 'wallet_credit' AND (NEW.wallet_credit_irr IS NULL OR NEW.discount_pricing_rule_id IS NOT NULL OR NEW.discount_pricing_rule_version_id IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet-credit benefit shape is invalid.';
    END IF;
    IF parent_type = 'free_service' AND (NEW.plan_offering_id IS NULL OR NEW.wallet_credit_irr IS NOT NULL OR NEW.discount_pricing_rule_id IS NOT NULL OR NEW.discount_pricing_rule_version_id IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Free-service benefit shape is invalid.';
    END IF;
    IF parent_type = 'discount_grant' AND (NEW.wallet_credit_irr IS NOT NULL OR NEW.discount_pricing_rule_id IS NULL OR NEW.discount_pricing_rule_version_id IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discount-grant benefit shape is invalid.';
    END IF;
    IF NEW.discount_pricing_rule_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM pricing_rule_versions v
        INNER JOIN pricing_rules r ON r.id = v.pricing_rule_id
        WHERE v.id = NEW.discount_pricing_rule_version_id
          AND r.id = NEW.discount_pricing_rule_id
          AND r.kind = 'promotion'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Discount-grant promotion identity mismatch.';
    END IF;
    IF NEW.plan_offering_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id
          AND (NEW.product_id IS NULL OR o.product_id = NEW.product_id)
          AND (NEW.sales_server_id IS NULL OR o.sales_server_id = NEW.sales_server_id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code offering scope identity mismatch.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER benefit_code_campaign_versions_update_guard BEFORE UPDATE ON benefit_code_campaign_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign version is immutable.'; END");
        DB::unprepared("CREATE TRIGGER benefit_code_campaign_versions_delete_guard BEFORE DELETE ON benefit_code_campaign_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign version cannot be deleted.'; END");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_code_issuances_insert_guard
BEFORE INSERT ON benefit_code_issuances FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM benefit_code_campaign_versions v
        WHERE v.id = NEW.benefit_code_campaign_version_id
          AND v.benefit_code_campaign_id = NEW.benefit_code_campaign_id
          AND v.state = 'active'
          AND v.version = (SELECT MAX(latest.version) FROM benefit_code_campaign_versions latest WHERE latest.benefit_code_campaign_id = NEW.benefit_code_campaign_id)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code issuance campaign version identity is invalid.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_codes_insert_guard
BEFORE INSERT ON benefit_codes FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM benefit_code_issuances i
        WHERE i.id = NEW.benefit_code_issuance_id
          AND i.benefit_code_campaign_id = NEW.benefit_code_campaign_id
          AND i.benefit_code_campaign_version_id = NEW.benefit_code_campaign_version_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code issuance identity mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_code_redemptions_insert_guard
BEFORE INSERT ON benefit_code_redemptions FOR EACH ROW
BEGIN
    DECLARE locked_code_id BIGINT UNSIGNED;
    DECLARE campaign_type VARCHAR(24);
    DECLARE campaign_state VARCHAR(16);
    DECLARE audience_value VARCHAR(16);
    DECLARE total_limit BIGINT UNSIGNED;
    DECLARE user_limit BIGINT UNSIGNED;
    DECLARE single_use_value TINYINT;
    DECLARE effective_from_value DATETIME(6);
    DECLARE effective_until_value DATETIME(6);
    DECLARE scope_offering BIGINT UNSIGNED;
    DECLARE scope_product BIGINT UNSIGNED;
    DECLARE scope_server BIGINT UNSIGNED;
    DECLARE wallet_amount BIGINT;
    DECLARE total_uses BIGINT UNSIGNED;
    DECLARE user_uses BIGINT UNSIGNED;
    DECLARE subject_type VARCHAR(16);

    SELECT id INTO locked_code_id FROM benefit_codes WHERE id = NEW.benefit_code_id FOR UPDATE;
    IF locked_code_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption code does not exist.';
    END IF;
    IF EXISTS (SELECT 1 FROM benefit_code_disables WHERE benefit_code_id = NEW.benefit_code_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code is disabled.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM benefit_codes b
        WHERE b.id = NEW.benefit_code_id
          AND b.benefit_code_campaign_id = NEW.benefit_code_campaign_id
          AND b.benefit_code_campaign_version_id = NEW.benefit_code_campaign_version_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption version identity mismatch.';
    END IF;
    SELECT c.type, v.audience, v.total_use_limit, v.per_user_use_limit, v.single_use,
           v.effective_from, v.effective_until, v.plan_offering_id, v.product_id, v.sales_server_id, v.wallet_credit_irr
      INTO campaign_type, audience_value, total_limit, user_limit, single_use_value,
           effective_from_value, effective_until_value, scope_offering, scope_product, scope_server, wallet_amount
      FROM benefit_code_campaigns c
      INNER JOIN benefit_code_campaign_versions v ON v.benefit_code_campaign_id = c.id
      WHERE c.id = NEW.benefit_code_campaign_id AND v.id = NEW.benefit_code_campaign_version_id;
    IF campaign_type IS NULL OR campaign_type <> NEW.type_snapshot THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption type mismatch.';
    END IF;
    IF NEW.campaign_code_snapshot <> (SELECT campaign_code FROM benefit_code_campaigns WHERE id = NEW.benefit_code_campaign_id)
       OR NEW.campaign_version <> (SELECT version FROM benefit_code_campaign_versions WHERE id = NEW.benefit_code_campaign_version_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption campaign snapshot mismatch.';
    END IF;
    SELECT state INTO campaign_state FROM benefit_code_campaign_versions
      WHERE benefit_code_campaign_id = NEW.benefit_code_campaign_id ORDER BY version DESC LIMIT 1;
    IF campaign_state <> 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code campaign is not active.';
    END IF;
    SELECT account_type INTO subject_type FROM users WHERE id = NEW.user_id AND account_status = 'active';
    IF subject_type IS NULL OR subject_type NOT IN ('customer', 'agent') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption subject is not active.';
    END IF;
    IF (audience_value = 'customers' AND subject_type <> 'customer') OR (audience_value = 'agents' AND subject_type <> 'agent') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code audience does not allow this subject.';
    END IF;
    IF effective_from_value IS NOT NULL AND UTC_TIMESTAMP(6) < effective_from_value THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code is not yet effective.';
    END IF;
    IF effective_until_value IS NOT NULL AND UTC_TIMESTAMP(6) >= effective_until_value THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code is expired.';
    END IF;
    IF (scope_offering IS NOT NULL OR scope_product IS NOT NULL OR scope_server IS NOT NULL) AND NEW.plan_offering_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption requires offering context.';
    END IF;
    IF NEW.plan_offering_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id
          AND (scope_offering IS NULL OR o.id = scope_offering)
          AND (scope_product IS NULL OR o.product_id = scope_product)
          AND (scope_server IS NULL OR o.sales_server_id = scope_server)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption scope mismatch.';
    END IF;
    SELECT COUNT(*) INTO total_uses FROM benefit_code_redemptions WHERE benefit_code_id = NEW.benefit_code_id;
    SELECT COUNT(*) INTO user_uses FROM benefit_code_redemptions WHERE benefit_code_id = NEW.benefit_code_id AND user_id = NEW.user_id;
    IF (single_use_value = 1 AND total_uses >= 1) OR (total_limit IS NOT NULL AND total_uses >= total_limit) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code total redemption capacity is exhausted.';
    END IF;
    IF user_limit IS NOT NULL AND user_uses >= user_limit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code per-user redemption capacity is exhausted.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_snapshot_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code redemption snapshot hash mismatch.';
    END IF;
    IF campaign_type = 'wallet_credit' THEN
        IF NEW.promotional_wallet_account_id IS NULL OR NEW.ledger_transaction_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet-credit redemption effect identity is missing.';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM ledger_accounts a
            WHERE a.id = NEW.promotional_wallet_account_id
              AND a.owner_user_id = NEW.user_id
              AND a.account_class = 'liability'
              AND a.wallet_bucket = 'promotional'
              AND a.currency = 'IRR'
              AND a.is_active = 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet-credit promotional account mismatch.';
        END IF;
        IF NOT EXISTS (
            SELECT 1 FROM ledger_transactions t
            WHERE t.id = NEW.ledger_transaction_id
              AND t.transaction_type = 'benefit_code_promotional_credit'
              AND t.source_type = 'benefit_code_redemption'
              AND t.source_id = NEW.public_id
              AND t.expected_total_irr = wallet_amount
              AND t.posted_debit_irr = wallet_amount
              AND t.posted_credit_irr = wallet_amount
              AND t.entry_count = 2
              AND t.finalized_at IS NOT NULL
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet-credit ledger effect mismatch.';
        END IF;
    ELSEIF NEW.promotional_wallet_account_id IS NOT NULL OR NEW.ledger_transaction_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-wallet benefit cannot carry a wallet effect.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_code_free_service_entitlements_insert_guard
BEFORE INSERT ON benefit_code_free_service_entitlements FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM benefit_code_redemptions r
        WHERE r.id = NEW.benefit_code_redemption_id
          AND r.user_id = NEW.user_id
          AND r.type_snapshot = 'free_service'
          AND r.plan_offering_id = NEW.plan_offering_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code free-service entitlement identity mismatch.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code free-service entitlement hash mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_code_discount_grants_insert_guard
BEFORE INSERT ON benefit_code_discount_grants FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM benefit_code_redemptions r
        WHERE r.id = NEW.benefit_code_redemption_id
          AND r.user_id = NEW.user_id
          AND r.type_snapshot = 'discount_grant'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code discount grant redemption mismatch.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pricing_rule_versions v
        INNER JOIN pricing_rules r ON r.id = v.pricing_rule_id
        WHERE r.id = NEW.pricing_rule_id
          AND v.id = NEW.pricing_rule_version_id
          AND r.rule_code = NEW.rule_code_snapshot
          AND r.kind = 'promotion'
          AND v.version = NEW.rule_version
          AND v.configuration_hash = NEW.rule_configuration_hash
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code discount grant pricing identity mismatch.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit code discount grant hash mismatch.';
    END IF;
END
SQL);
    }

    private function immutableTrigger(string $table, string $label): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$label} is immutable.'; END");
        DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$label} cannot be deleted.'; END");
    }

    private function dropGuards(): void
    {
        foreach ([
            'benefit_code_discount_grants_insert_guard',
            'benefit_code_free_service_entitlements_insert_guard',
            'benefit_code_redemptions_insert_guard',
            'benefit_codes_insert_guard',
            'benefit_code_issuances_insert_guard',
            'benefit_code_campaign_versions_insert_guard',
            'benefit_code_campaign_versions_update_guard',
            'benefit_code_campaign_versions_delete_guard',
        ] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach ([
            'benefit_code_campaigns',
            'benefit_code_issuances',
            'benefit_codes',
            'benefit_code_disables',
            'benefit_code_redemptions',
            'benefit_code_free_service_entitlements',
            'benefit_code_discount_grants',
        ] as $table) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS '.$table.'_delete_guard');
        }
    }

    private function ensurePromotionalFundingAccount(): void
    {
        /** @var object{account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string}|null $existing */
        $existing = DB::table('ledger_accounts')->where('code', self::FUNDING_ACCOUNT_CODE)->first(['account_class', 'owner_user_id', 'wallet_bucket', 'currency']);
        if ($existing !== null) {
            if ($existing->account_class !== 'equity' || $existing->owner_user_id !== null || $existing->wallet_bucket !== null || $existing->currency !== 'IRR') {
                throw new RuntimeException('Existing benefit-code funding account has incompatible identity.');
            }
            DB::table('ledger_accounts')->where('code', self::FUNDING_ACCOUNT_CODE)->update(['is_active' => true, 'updated_at' => now('UTC')]);

            return;
        }
        $now = now('UTC');
        DB::table('ledger_accounts')->insert([
            'code' => self::FUNDING_ACCOUNT_CODE,
            'account_class' => 'equity',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
