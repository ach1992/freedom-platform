<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement USDT-001 USDT-002 DAT-002 DAT-003 SEC-001 SEC-002 */
    public function up(): void
    {
        Schema::create('usdt_destination_wallet_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('wallet_code', 64);
            $table->unsignedBigInteger('version');
            $table->string('network', 16);
            $table->string('address', 42);
            $table->boolean('enabled');
            $table->string('mutation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->foreignId('changed_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('change_reason', 255);
            $table->char('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['wallet_code', 'version'], 'usdt_wallet_code_version_uniq');
            $table->index(['wallet_code', 'version'], 'usdt_wallet_current_idx');
        });

        DB::statement("ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_network_chk CHECK (`network` = 'BEP20')");
        DB::statement("ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_code_chk CHECK (`wallet_code` REGEXP '^[a-z][a-z0-9_-]{1,63}$')");
        DB::statement("ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_address_chk CHECK (`address` REGEXP '^0x[a-f0-9]{40}$')");
        DB::statement('ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64 AND CHAR_LENGTH(`correlation_id`) = 64)');
        DB::statement("ALTER TABLE usdt_destination_wallet_versions ADD CONSTRAINT usdt_wallet_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 16 AND OCTET_LENGTH(`configuration_snapshot`) <= 4096)");

        Schema::create('usdt_amount_quotes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('quote_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('source_quote_id')->constrained('quotes')->restrictOnDelete();
            $table->ulid('source_quote_public_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('destination_wallet_version_id')->constrained('usdt_destination_wallet_versions')->restrictOnDelete();
            $table->string('destination_wallet_code', 64);
            $table->unsignedBigInteger('destination_wallet_version');
            $table->string('destination_address', 42);
            $table->string('network', 16);
            $table->char('destination_configuration_hash', 64);
            $table->string('rate_source', 32);
            $table->decimal('raw_rate_irr', 24, 8);
            $table->unsignedSmallInteger('margin_bps');
            $table->decimal('final_rate_irr', 24, 8);
            $table->bigInteger('order_amount_irr');
            $table->decimal('exact_usdt', 36, 6);
            $table->unsignedTinyInteger('rounding_precision');
            $table->unsignedSmallInteger('rate_max_age_seconds');
            $table->unsignedSmallInteger('quote_validity_seconds');
            $table->dateTime('rate_fetched_at', 6);
            $table->dateTime('expires_at', 6);
            $table->char('provider_response_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['source_quote_id', 'created_at'], 'usdt_amount_quotes_source_idx');
            $table->index(['user_id', 'expires_at'], 'usdt_amount_quotes_user_expiry_idx');
        });

        DB::statement("ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_network_chk CHECK (`network` = 'BEP20')");
        DB::statement("ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_address_chk CHECK (`destination_address` REGEXP '^0x[a-f0-9]{40}$')");
        DB::statement("ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_source_chk CHECK (`rate_source` REGEXP '^[a-z][a-z0-9_-]{1,31}$')");
        DB::statement('ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_rate_chk CHECK (`raw_rate_irr` > 0 AND `final_rate_irr` > 0 AND `final_rate_irr` = TRUNCATE((`raw_rate_irr` * (10000 - `margin_bps`)) / 10000, 8))');
        DB::statement('ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_values_chk CHECK (`margin_bps` < 10000 AND `order_amount_irr` > 0 AND `exact_usdt` > 0 AND `rounding_precision` <= 6)');
        DB::statement('ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_policy_chk CHECK (`rate_max_age_seconds` BETWEEN 1 AND 3600 AND `quote_validity_seconds` BETWEEN 1 AND 3600)');
        DB::statement('ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_time_chk CHECK (`expires_at` > `created_at` AND `rate_fetched_at` <= TIMESTAMPADD(SECOND, 5, `created_at`))');
        DB::statement('ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`destination_configuration_hash`) = 64 AND CHAR_LENGTH(`provider_response_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE usdt_amount_quotes ADD CONSTRAINT usdt_amount_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        $this->createDestinationInsertGuard();
        $this->createDestinationImmutableGuards();
        $this->createQuoteInsertGuard();
        $this->createQuoteImmutableGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_amount_quotes_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_amount_quotes_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_amount_quotes_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_wallet_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_wallet_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS usdt_wallet_versions_insert_guard');
        Schema::dropIfExists('usdt_amount_quotes');
        Schema::dropIfExists('usdt_destination_wallet_versions');
    }

    private function createDestinationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_wallet_versions_insert_guard
BEFORE INSERT ON usdt_destination_wallet_versions
FOR EACH ROW
BEGIN
    DECLARE active_admin_count INT DEFAULT 0;
    DECLARE latest_version BIGINT UNSIGNED DEFAULT 0;

    SELECT COUNT(*) INTO active_admin_count
    FROM administrators a
    WHERE a.id = NEW.changed_by_administrator_id
      AND a.status = 'active';

    IF active_admin_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination requires one active administrator.';
    END IF;

    SELECT COALESCE(MAX(v.version), 0) INTO latest_version
    FROM usdt_destination_wallet_versions v
    WHERE v.wallet_code = NEW.wallet_code;

    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination version is not sequential.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination configuration snapshot hash mismatch.';
    END IF;

    IF JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'usdt-destination-v1'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.wallet_code')) <> NEW.wallet_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.version')) AS UNSIGNED) <> NEW.version
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.network')) <> NEW.network
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.address')) <> NEW.address
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.enabled')) <> IF(NEW.enabled = 1, 'true', 'false') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination configuration snapshot mismatch.';
    END IF;
END
SQL);
    }

    private function createDestinationImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_wallet_versions_update_guard
BEFORE UPDATE ON usdt_destination_wallet_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination versions are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_wallet_versions_delete_guard
BEFORE DELETE ON usdt_destination_wallet_versions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT destination versions are non-deletable.';
END
SQL);
    }

    private function createQuoteInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_amount_quotes_insert_guard
BEFORE INSERT ON usdt_amount_quotes
FOR EACH ROW
BEGIN
    DECLARE valid_source_count INT DEFAULT 0;
    DECLARE valid_destination_count INT DEFAULT 0;
    DECLARE expected_exact DECIMAL(36,6);

    SELECT COUNT(*) INTO valid_source_count
    FROM quotes q
    WHERE q.id = NEW.source_quote_id
      AND q.public_id = NEW.source_quote_public_id
      AND q.user_id = NEW.user_id
      AND q.currency = 'IRR'
      AND q.final_price_irr = NEW.order_amount_irr
      AND q.final_price_irr > 0
      AND q.expires_at > NEW.created_at
      AND NEW.expires_at <= q.expires_at;

    IF valid_source_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote source snapshot is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_destination_count
    FROM usdt_destination_wallet_versions v
    WHERE v.id = NEW.destination_wallet_version_id
      AND v.wallet_code = NEW.destination_wallet_code
      AND v.version = NEW.destination_wallet_version
      AND v.network = NEW.network
      AND v.address = NEW.destination_address
      AND v.configuration_snapshot_hash = NEW.destination_configuration_hash
      AND v.enabled = 1
      AND NOT EXISTS (
          SELECT 1
          FROM usdt_destination_wallet_versions newer
          WHERE newer.wallet_code = v.wallet_code
            AND newer.version > v.version
      );

    IF valid_destination_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote destination snapshot is not current.';
    END IF;

    IF NEW.expires_at > TIMESTAMPADD(SECOND, NEW.quote_validity_seconds, NEW.created_at)
       OR NEW.expires_at > TIMESTAMPADD(SECOND, NEW.rate_max_age_seconds, NEW.rate_fetched_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote expiry exceeds its policy bounds.';
    END IF;

    SET expected_exact = CASE NEW.rounding_precision
        WHEN 0 THEN CEIL(NEW.order_amount_irr / NEW.final_rate_irr)
        WHEN 1 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 10) / 10
        WHEN 2 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 100) / 100
        WHEN 3 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 1000) / 1000
        WHEN 4 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 10000) / 10000
        WHEN 5 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 100000) / 100000
        WHEN 6 THEN CEIL((NEW.order_amount_irr / NEW.final_rate_irr) * 1000000) / 1000000
        ELSE NULL
    END;

    IF expected_exact IS NULL OR NEW.exact_usdt <> expected_exact THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote exact amount is invalid.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote configuration snapshot hash mismatch.';
    END IF;

    IF JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.formula_version')) <> 'usdt-quote-v1'
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote_public_id')) <> NEW.source_quote_public_id
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.destination_wallet_code')) <> NEW.destination_wallet_code
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.destination_wallet_version')) AS UNSIGNED) <> NEW.destination_wallet_version
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.destination_address')) <> NEW.destination_address
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.network')) <> NEW.network
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rate_source')) <> NEW.rate_source
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.raw_rate_irr')) AS DECIMAL(24,8)) <> NEW.raw_rate_irr
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.final_rate_irr')) AS DECIMAL(24,8)) <> NEW.final_rate_irr
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.margin_bps')) AS UNSIGNED) <> NEW.margin_bps
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.order_amount_irr')) AS SIGNED) <> NEW.order_amount_irr
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.exact_usdt')) AS DECIMAL(36,6)) <> NEW.exact_usdt
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rounding_precision')) AS UNSIGNED) <> NEW.rounding_precision
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rate_max_age_seconds')) AS UNSIGNED) <> NEW.rate_max_age_seconds
       OR CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.quote_validity_seconds')) AS UNSIGNED) <> NEW.quote_validity_seconds
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rate_fetched_at')) <> DATE_FORMAT(NEW.rate_fetched_at, '%Y-%m-%d %H:%i:%s.%f')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.expires_at')) <> DATE_FORMAT(NEW.expires_at, '%Y-%m-%d %H:%i:%s.%f')
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.provider_response_hash')) <> NEW.provider_response_hash
       OR JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.destination_configuration_hash')) <> NEW.destination_configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quote configuration snapshot mismatch.';
    END IF;
END
SQL);
    }

    private function createQuoteImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_amount_quotes_update_guard
BEFORE UPDATE ON usdt_amount_quotes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quotes are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER usdt_amount_quotes_delete_guard
BEFORE DELETE ON usdt_amount_quotes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT amount quotes are non-deletable.';
END
SQL);
    }
};
