<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PAY-001 BUY-002 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-001 QUA-003 QUA-004 */
    public function up(): void
    {
        Schema::create('payment_method_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('method_code', 64);
            $table->unsignedBigInteger('version');
            $table->boolean('enabled');
            $table->boolean('maintenance');
            $table->unsignedInteger('display_priority');
            $table->string('mutation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->foreignId('changed_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('change_reason', 255);
            $table->char('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['method_code', 'version'], 'payment_method_version_unique');
            $table->index(['method_code', 'version'], 'payment_method_current_idx');
            $table->index(['display_priority', 'method_code'], 'payment_method_route_order_idx');
        });

        Schema::create('payment_method_rule_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('method_code', 64);
            $table->string('rule_code', 64);
            $table->unsignedBigInteger('version');
            $table->boolean('enabled');
            $table->string('effect', 8);
            $table->unsignedInteger('priority');
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('account_types');
            $table->json('tier_codes');
            $table->bigInteger('minimum_amount_irr')->nullable();
            $table->bigInteger('maximum_amount_irr')->nullable();
            $table->json('offering_codes');
            $table->json('product_ids');
            $table->json('sales_server_ids');
            $table->string('required_identity_status', 16)->nullable();
            $table->string('required_agent_status', 16)->nullable();
            $table->string('starts_at_utc', 5)->nullable();
            $table->string('ends_at_utc', 5)->nullable();
            $table->boolean('requires_contact_otp_provenance');
            $table->boolean('requires_purchase_history');
            $table->boolean('requires_daily_payment_limit');
            $table->string('mutation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->foreignId('changed_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('change_reason', 255);
            $table->char('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['method_code', 'rule_code', 'version'], 'payment_method_rule_version_unique');
            $table->index(['method_code', 'rule_code', 'version'], 'payment_method_rule_current_idx');
            $table->index(['method_code', 'enabled', 'priority'], 'payment_method_rule_evaluation_idx');
            $table->index(['subject_user_id', 'method_code'], 'payment_method_rule_subject_idx');
        });

        Schema::create('payment_method_rule_version_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_method_rule_version_id')->constrained('payment_method_rule_versions')->restrictOnDelete();
            $table->string('tag_code', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_method_rule_version_id', 'tag_code'], 'payment_method_rule_tag_unique');
        });

        Schema::create('payment_method_health_observations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('method_code', 64);
            $table->string('observation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->boolean('healthy');
            $table->dateTime('observed_at', 6);
            $table->dateTime('expires_at', 6);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->foreignId('recorded_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('change_reason', 255);
            $table->char('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['method_code', 'observed_at'], 'payment_method_health_current_idx');
        });

        Schema::create('payment_method_eligibility_decisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('decision_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->ulid('source_quote_public_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('action_snapshot', 32);
            $table->char('currency_snapshot', 3);
            $table->bigInteger('amount_irr_snapshot');
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['source_quote_id', 'created_at'], 'payment_eligibility_source_quote_idx');
            $table->index(['user_id', 'created_at'], 'payment_eligibility_user_idx');
        });

        Schema::create('payment_method_eligibility_decision_methods', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('payment_method_eligibility_decision_id')->constrained('payment_method_eligibility_decisions')->restrictOnDelete();
            $table->foreignId('payment_method_version_id')->constrained('payment_method_versions')->restrictOnDelete();
            $table->string('method_code', 64);
            $table->unsignedBigInteger('method_version');
            $table->unsignedInteger('route_order')->nullable();
            $table->string('reason_code', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['payment_method_eligibility_decision_id', 'route_order'], 'payment_eligibility_route_order_unique');
            $table->unique(['payment_method_eligibility_decision_id', 'method_code'], 'payment_eligibility_method_unique');
        });

        $this->addChecks();
        $this->createGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        Schema::dropIfExists('payment_method_eligibility_decision_methods');
        Schema::dropIfExists('payment_method_eligibility_decisions');
        Schema::dropIfExists('payment_method_health_observations');
        Schema::dropIfExists('payment_method_rule_version_tags');
        Schema::dropIfExists('payment_method_rule_versions');
        Schema::dropIfExists('payment_method_versions');
    }

    private function addChecks(): void
    {
        DB::statement("ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        DB::statement('ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_versions ADD CONSTRAINT payment_method_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");

        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$' AND `rule_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_effect_chk CHECK (`effect` IN ('allow','deny'))");
        DB::statement('ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_version_chk CHECK (`version` >= 1 AND (`minimum_amount_irr` IS NULL OR `minimum_amount_irr` >= 0) AND (`maximum_amount_irr` IS NULL OR `maximum_amount_irr` >= 0) AND (`minimum_amount_irr` IS NULL OR `maximum_amount_irr` IS NULL OR `minimum_amount_irr` <= `maximum_amount_irr`))');
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_identity_chk CHECK (`required_identity_status` IS NULL OR `required_identity_status` IN ('unverified','pending','verified','rejected'))");
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_agent_chk CHECK (`required_agent_status` IS NULL OR `required_agent_status` IN ('active','suspended'))");
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_time_chk CHECK ((`starts_at_utc` IS NULL AND `ends_at_utc` IS NULL) OR (`starts_at_utc` REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]))");
        DB::statement('ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_json_chk CHECK (JSON_VALID(`account_types`) AND JSON_VALID(`tier_codes`) AND JSON_VALID(`offering_codes`) AND JSON_VALID(`product_ids`) AND JSON_VALID(`sales_server_ids`) AND JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE payment_method_rule_version_tags ADD CONSTRAINT payment_rule_tag_code_chk CHECK (`tag_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");

        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_time_chk CHECK (`expires_at` > `observed_at`)');
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");

        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_action_currency_chk CHECK (`action_snapshot` = 'purchase' AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0)");
        DB::statement('ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 32768)");

        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$' AND `method_version` >= 1 AND (`route_order` IS NULL OR `route_order` >= 1))");
        DB::statement('ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_hashes_chk CHECK (CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_insert_guard
BEFORE INSERT ON payment_method_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method change requires an active administrator.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_versions v WHERE v.method_code = NEW.method_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.enabled')) <> IF(NEW.enabled = 1, 'true', 'false')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.maintenance')) <> IF(NEW.maintenance = 1, 'true', 'false')
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.display_priority')) AS UNSIGNED) <> NEW.display_priority THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_versions_insert_guard
BEFORE INSERT ON payment_method_rule_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule requires an active administrator and configured method.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_rule_versions v WHERE v.method_code = NEW.method_code AND v.rule_code = NEW.rule_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-rule-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rule_code')) <> NEW.rule_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.effect')) <> NEW.effect
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.priority')) AS UNSIGNED) <> NEW.priority
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.subject_user_id') <=> CAST(NEW.subject_user_id AS CHAR))
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.account_types') <=> NEW.account_types)
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.tier_codes') <=> NEW.tier_codes)
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.offering_codes') <=> NEW.offering_codes)
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.product_ids') <=> NEW.product_ids)
       OR NOT (JSON_EXTRACT(NEW.configuration_snapshot, '$.sales_server_ids') <=> NEW.sales_server_ids)
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.minimum_amount_irr')) <=> CAST(NEW.minimum_amount_irr AS CHAR))
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.maximum_amount_irr')) <=> CAST(NEW.maximum_amount_irr AS CHAR))
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.required_identity_status')) <=> NEW.required_identity_status)
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.required_agent_status')) <=> NEW.required_agent_status)
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.starts_at_utc')) <=> NEW.starts_at_utc)
       OR NOT (JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.ends_at_utc')) <=> NEW.ends_at_utc)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.requires_contact_otp_provenance')) <> IF(NEW.requires_contact_otp_provenance = 1, 'true', 'false')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.requires_purchase_history')) <> IF(NEW.requires_purchase_history = 1, 'true', 'false')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.requires_daily_payment_limit')) <> IF(NEW.requires_daily_payment_limit = 1, 'true', 'false') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_version_tags_insert_guard
BEFORE INSERT ON payment_method_rule_version_tags
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM payment_method_rule_versions r
        WHERE r.id = NEW.payment_method_rule_version_id
          AND JSON_CONTAINS(JSON_EXTRACT(r.configuration_snapshot, '$.tag_codes'), JSON_QUOTE(NEW.tag_code))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule tag is not part of the immutable rule snapshot.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_health_observations_insert_guard
BEFORE INSERT ON payment_method_health_observations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.recorded_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health requires an active administrator and configured method.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-health-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.healthy')) <> IF(NEW.healthy = 1, 'true', 'false')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.observed_at')) <> DATE_FORMAT(NEW.observed_at, '%Y-%m-%d %H:%i:%s.%f')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.expires_at')) <> DATE_FORMAT(NEW.expires_at, '%Y-%m-%d %H:%i:%s.%f') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decisions_insert_guard
BEFORE INSERT ON payment_method_eligibility_decisions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM quotes q INNER JOIN users u ON u.id = q.user_id
        WHERE q.id = NEW.source_quote_id AND q.public_id = NEW.source_quote_public_id
          AND q.user_id = NEW.user_id AND q.currency = NEW.currency_snapshot
          AND q.final_price_irr = NEW.amount_irr_snapshot AND q.expires_at > NEW.created_at
          AND u.account_type IN ('customer', 'agent')
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

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decision_methods_insert_guard
BEFORE INSERT ON payment_method_eligibility_decision_methods
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.id = NEW.payment_method_version_id AND m.method_code = NEW.method_code AND m.version = NEW.method_version) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-decision-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_version')) AS UNSIGNED) <> NEW.method_version
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.candidate_outcome')) <> NEW.reason_code
       OR (NEW.route_order IS NULL AND JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.route_order')) <> 'NULL')
       OR (NEW.route_order IS NOT NULL AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.route_order')) AS UNSIGNED) <> NEW.route_order)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.configuration_snapshot_hash')) <> (
            SELECT m.configuration_snapshot_hash FROM payment_method_versions m WHERE m.id = NEW.payment_method_version_id
       )
       OR (
            JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.observation_id')) = 'NULL'
            AND (
                JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.configuration_snapshot_hash')) <> 'NULL'
                OR JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.healthy')) <> 'NULL'
                OR JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.observed_at')) <> 'NULL'
                OR JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.expires_at')) <> 'NULL'
            )
       )
       OR (
            JSON_TYPE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.observation_id')) <> 'NULL'
            AND NOT EXISTS (
                SELECT 1
                FROM payment_method_health_observations h
                WHERE h.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.observation_id')) AS UNSIGNED)
                  AND h.method_code = NEW.method_code
                  AND h.configuration_snapshot_hash = JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.configuration_snapshot_hash'))
                  AND h.healthy = CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.healthy')) AS UNSIGNED)
                  AND CAST(h.observed_at AS CHAR) = JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.observed_at'))
                  AND CAST(h.expires_at AS CHAR) = JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.health.expires_at'))
            )
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method snapshot is invalid.';
    END IF;
END
SQL);

        foreach ([
            'payment_method_versions',
            'payment_method_rule_versions',
            'payment_method_rule_version_tags',
            'payment_method_health_observations',
            'payment_method_eligibility_decisions',
            'payment_method_eligibility_decision_methods',
        ] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are immutable.'");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are non-deletable.'");
        }
    }

    private function dropGuards(): void
    {
        foreach ([
            'payment_method_eligibility_decision_methods',
            'payment_method_eligibility_decisions',
            'payment_method_health_observations',
            'payment_method_rule_version_tags',
            'payment_method_rule_versions',
            'payment_method_versions',
        ] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_insert_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_update_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_delete_guard");
        }
    }
};
 AND `ends_at_utc` REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]))");
        DB::statement('ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_json_chk CHECK (JSON_VALID(`account_types`) AND JSON_VALID(`tier_codes`) AND JSON_VALID(`offering_codes`) AND JSON_VALID(`product_ids`) AND JSON_VALID(`sales_server_ids`) AND JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE payment_method_rule_version_tags ADD CONSTRAINT payment_rule_tag_code_chk CHECK (`tag_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");

        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_time_chk CHECK (`expires_at` > `observed_at`)');
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");

        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_action_currency_chk CHECK (`action_snapshot` = 'purchase' AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0)");
        DB::statement('ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 32768)");

        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$' AND `method_version` >= 1 AND (`route_order` IS NULL OR `route_order` >= 1))");
        DB::statement('ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_hashes_chk CHECK (CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_insert_guard
BEFORE INSERT ON payment_method_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method change requires an active administrator.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_versions v WHERE v.method_code = NEW.method_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_versions_insert_guard
BEFORE INSERT ON payment_method_rule_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule requires an active administrator and configured method.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_rule_versions v WHERE v.method_code = NEW.method_code AND v.rule_code = NEW.rule_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-rule-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rule_code')) <> NEW.rule_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_version_tags_insert_guard
BEFORE INSERT ON payment_method_rule_version_tags
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM payment_method_rule_versions r
        WHERE r.id = NEW.payment_method_rule_version_id
          AND JSON_CONTAINS(JSON_EXTRACT(r.configuration_snapshot, '$.tag_codes'), JSON_QUOTE(NEW.tag_code))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule tag is not part of the immutable rule snapshot.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_health_observations_insert_guard
BEFORE INSERT ON payment_method_health_observations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.recorded_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health requires an active administrator and configured method.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-health-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decisions_insert_guard
BEFORE INSERT ON payment_method_eligibility_decisions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM quotes q INNER JOIN users u ON u.id = q.user_id
        WHERE q.id = NEW.source_quote_id AND q.public_id = NEW.source_quote_public_id
          AND q.user_id = NEW.user_id AND q.currency = NEW.currency_snapshot
          AND q.final_price_irr = NEW.amount_irr_snapshot AND q.expires_at > NEW.created_at
          AND u.account_type IN ('customer', 'agent')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility source Quote is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-eligibility-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote.public_id')) <> NEW.source_quote_public_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decision_methods_insert_guard
BEFORE INSERT ON payment_method_eligibility_decision_methods
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.id = NEW.payment_method_version_id AND m.method_code = NEW.method_code AND m.version = NEW.method_version) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-decision-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_version')) AS UNSIGNED) <> NEW.method_version
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.route_order')) AS UNSIGNED) <> NEW.route_order THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method snapshot is invalid.';
    END IF;
END
SQL);

        foreach ([
            'payment_method_versions',
            'payment_method_rule_versions',
            'payment_method_rule_version_tags',
            'payment_method_health_observations',
            'payment_method_eligibility_decisions',
            'payment_method_eligibility_decision_methods',
        ] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are immutable.'");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are non-deletable.'");
        }
    }

    private function dropGuards(): void
    {
        foreach ([
            'payment_method_eligibility_decision_methods',
            'payment_method_eligibility_decisions',
            'payment_method_health_observations',
            'payment_method_rule_version_tags',
            'payment_method_rule_versions',
            'payment_method_versions',
        ] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_insert_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_update_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_delete_guard");
        }
    }
};
))");
        DB::statement('ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_rule_versions ADD CONSTRAINT payment_rule_json_chk CHECK (JSON_VALID(`account_types`) AND JSON_VALID(`tier_codes`) AND JSON_VALID(`offering_codes`) AND JSON_VALID(`product_ids`) AND JSON_VALID(`sales_server_ids`) AND JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        DB::statement("ALTER TABLE payment_method_rule_version_tags ADD CONSTRAINT payment_rule_tag_code_chk CHECK (`tag_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");

        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$')");
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_time_chk CHECK (`expires_at` > `observed_at`)');
        DB::statement('ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE payment_method_health_observations ADD CONSTRAINT payment_health_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");

        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_action_currency_chk CHECK (`action_snapshot` = 'purchase' AND `currency_snapshot` = 'IRR' AND `amount_irr_snapshot` >= 0)");
        DB::statement('ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decisions ADD CONSTRAINT payment_decision_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 32768)");

        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_code_chk CHECK (`method_code` REGEXP '^[a-z][a-z0-9_.-]{1,63}$' AND `method_version` >= 1 AND (`route_order` IS NULL OR `route_order` >= 1))");
        DB::statement('ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_hashes_chk CHECK (CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE payment_method_eligibility_decision_methods ADD CONSTRAINT payment_decision_method_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");
    }

    private function createGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_versions_insert_guard
BEFORE INSERT ON payment_method_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method change requires an active administrator.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_versions v WHERE v.method_code = NEW.method_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment method configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_versions_insert_guard
BEFORE INSERT ON payment_method_rule_versions
FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.changed_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule requires an active administrator and configured method.';
    END IF;
    SELECT COALESCE(MAX(v.version), 0) INTO latest_version FROM payment_method_rule_versions v WHERE v.method_code = NEW.method_code AND v.rule_code = NEW.rule_code;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule version is not sequential.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-rule-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rule_code')) <> NEW.rule_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_rule_version_tags_insert_guard
BEFORE INSERT ON payment_method_rule_version_tags
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM payment_method_rule_versions r
        WHERE r.id = NEW.payment_method_rule_version_id
          AND JSON_CONTAINS(JSON_EXTRACT(r.configuration_snapshot, '$.tag_codes'), JSON_QUOTE(NEW.tag_code))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment rule tag is not part of the immutable rule snapshot.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_health_observations_insert_guard
BEFORE INSERT ON payment_method_health_observations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM administrators a WHERE a.id = NEW.recorded_by_administrator_id AND a.status = 'active')
       OR NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.method_code = NEW.method_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health requires an active administrator and configured method.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-health-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment health configuration snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decisions_insert_guard
BEFORE INSERT ON payment_method_eligibility_decisions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM quotes q INNER JOIN users u ON u.id = q.user_id
        WHERE q.id = NEW.source_quote_id AND q.public_id = NEW.source_quote_public_id
          AND q.user_id = NEW.user_id AND q.currency = NEW.currency_snapshot
          AND q.final_price_irr = NEW.amount_irr_snapshot AND q.expires_at > NEW.created_at
          AND u.account_type IN ('customer', 'agent')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility source Quote is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-eligibility-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote.public_id')) <> NEW.source_quote_public_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision snapshot is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER payment_method_eligibility_decision_methods_insert_guard
BEFORE INSERT ON payment_method_eligibility_decision_methods
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM payment_method_versions m WHERE m.id = NEW.payment_method_version_id AND m.method_code = NEW.method_code AND m.version = NEW.method_version) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method is invalid.';
    END IF;
    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash)
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'pay-001-decision-method-v2'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_code')) <> NEW.method_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.method_version')) AS UNSIGNED) <> NEW.method_version
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.route_order')) AS UNSIGNED) <> NEW.route_order THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment eligibility decision method snapshot is invalid.';
    END IF;
END
SQL);

        foreach ([
            'payment_method_versions',
            'payment_method_rule_versions',
            'payment_method_rule_version_tags',
            'payment_method_health_observations',
            'payment_method_eligibility_decisions',
            'payment_method_eligibility_decision_methods',
        ] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are immutable.'");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} records are non-deletable.'");
        }
    }

    private function dropGuards(): void
    {
        foreach ([
            'payment_method_eligibility_decision_methods',
            'payment_method_eligibility_decisions',
            'payment_method_health_observations',
            'payment_method_rule_version_tags',
            'payment_method_rule_versions',
            'payment_method_versions',
        ] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_insert_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_update_guard");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_delete_guard");
        }
    }
};
