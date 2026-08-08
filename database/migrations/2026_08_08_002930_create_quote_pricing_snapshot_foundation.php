<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('quote_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('account_type_snapshot', 32);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->string('offering_code_snapshot', 64);
            $table->unsignedBigInteger('offering_version');
            $table->char('offering_configuration_hash', 64);
            $table->boolean('offering_discount_eligible');
            $table->bigInteger('base_price_irr');
            $table->string('override_source', 16)->default('none');
            $table->string('override_reference_code', 64)->nullable();
            $table->bigInteger('override_price_irr')->nullable();
            $table->bigInteger('effective_price_irr');
            $table->string('discount_reference_code', 64)->nullable();
            $table->bigInteger('discount_irr')->default(0);
            $table->bigInteger('final_price_irr');
            $table->char('currency', 3)->default('IRR');
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->string('creation_correlation_id', 64);
            $table->dateTime('valid_from', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'expires_at'], 'quotes_user_expiry_idx');
            $table->index(['plan_offering_id', 'expires_at'], 'quotes_offering_expiry_idx');
        });

        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_account_type_chk CHECK (`account_type_snapshot` IN ('customer','agent'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_override_source_chk CHECK (`override_source` IN ('none','account','tier','agent'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_currency_chk CHECK (`currency` = 'IRR')");
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_base_price_chk CHECK (`base_price_irr` >= 0)');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_effective_price_chk CHECK (`effective_price_irr` >= 0 AND `effective_price_irr` = COALESCE(`override_price_irr`, `base_price_irr`))');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_discount_chk CHECK (`discount_irr` >= 0 AND `discount_irr` <= `effective_price_irr`)');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_final_price_chk CHECK (`final_price_irr` >= 0 AND `final_price_irr` = `effective_price_irr` - `discount_irr`)');
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_override_shape_chk CHECK ((`override_source` = 'none' AND `override_reference_code` IS NULL AND `override_price_irr` IS NULL) OR (`override_source` <> 'none' AND `override_reference_code` IS NOT NULL AND CHAR_LENGTH(`override_reference_code`) >= 1 AND `override_price_irr` IS NOT NULL AND `override_price_irr` >= 0))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_discount_shape_chk CHECK ((`discount_irr` = 0 AND `discount_reference_code` IS NULL) OR (`discount_irr` > 0 AND `discount_reference_code` IS NOT NULL AND CHAR_LENGTH(`discount_reference_code`) >= 1))");
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_validity_chk CHECK (`expires_at` > `valid_from`)');
        DB::statement('ALTER TABLE quotes ADD CONSTRAINT quotes_hashes_chk CHECK (CHAR_LENGTH(`request_payload_hash`) = 64 AND CHAR_LENGTH(`offering_configuration_hash`) = 64 AND CHAR_LENGTH(`configuration_snapshot_hash`) = 64)');
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_snapshot_bounds_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        $this->createInsertGuard();
        $this->createImmutableGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_insert_guard');
        Schema::dropIfExists('quotes');
    }

    private function createInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_insert_guard
BEFORE INSERT ON quotes
FOR EACH ROW
BEGIN
    DECLARE valid_user_count INT DEFAULT 0;
    DECLARE valid_offering_count INT DEFAULT 0;
    DECLARE valid_override_count INT DEFAULT 0;

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
      AND o.base_price_irr = NEW.base_price_irr
      AND o.discount_eligible = NEW.offering_discount_eligible;

    IF valid_offering_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering snapshot is not current.';
    END IF;

    IF NEW.discount_irr > 0 AND NEW.offering_discount_eligible <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote offering does not allow discounts.';
    END IF;

    IF NEW.override_source = 'agent' THEN
        SELECT COUNT(*) INTO valid_override_count
        FROM agent_profiles a
        WHERE a.user_id = NEW.user_id
          AND a.status = 'active'
          AND a.pricing_profile_code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote agent override reference is not current.';
        END IF;
    ELSEIF NEW.override_source = 'tier' THEN
        SELECT COUNT(*) INTO valid_override_count
        FROM customer_profiles p
        INNER JOIN customer_tiers t ON t.id = p.current_tier_id
        WHERE p.user_id = NEW.user_id
          AND t.is_active = 1
          AND t.code = NEW.override_reference_code;
        IF valid_override_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote tier override reference is not current.';
        END IF;
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote configuration snapshot hash mismatch.';
    END IF;
END
SQL);
    }

    private function createImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_update_guard
BEFORE UPDATE ON quotes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quotes are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_delete_guard
BEFORE DELETE ON quotes
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quotes are non-deletable.';
END
SQL);
    }
};
