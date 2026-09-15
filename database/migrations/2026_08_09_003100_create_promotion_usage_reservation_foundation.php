<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRO-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('promotion_usage_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('reservation_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('pricing_rule_resolution_id')->unique()->constrained('pricing_rule_resolutions')->restrictOnDelete();
            $table->ulid('resolution_public_id_snapshot');
            $table->char('resolution_configuration_snapshot_hash', 64);
            $table->foreignId('quote_id')->unique()->constrained('quotes')->restrictOnDelete();
            $table->ulid('quote_public_id_snapshot');
            $table->char('quote_configuration_snapshot_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('pricing_rule_id')->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('pricing_rule_version_id')->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->string('rule_code_snapshot', 128);
            $table->unsignedBigInteger('rule_version');
            $table->char('rule_configuration_hash', 64);
            $table->bigInteger('discount_irr');
            $table->unsignedBigInteger('total_use_limit_snapshot')->nullable();
            $table->unsignedBigInteger('per_user_use_limit_snapshot')->nullable();
            $table->boolean('allows_free_order_snapshot')->default(false);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['pricing_rule_version_id', 'user_id', 'created_at'], 'promotion_usage_version_user_idx');
            $table->index(['pricing_rule_version_id', 'created_at'], 'promotion_usage_version_created_idx');
        });

        Schema::create('promotion_usage_releases', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('release_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('promotion_usage_reservation_id')->unique()->constrained('promotion_usage_reservations')->restrictOnDelete();
            $table->foreignId('released_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('reservation_configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
        });

        $this->addChecks();
        $this->createReservationGuards();
        $this->createReleaseGuards();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_releases_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_releases_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_releases_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_reservations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_reservations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_reservations_insert_guard');
        Schema::dropIfExists('promotion_usage_releases');
        Schema::dropIfExists('promotion_usage_reservations');
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE promotion_usage_reservations ADD CONSTRAINT promotion_usage_discount_chk CHECK (`discount_irr` > 0)');
        DB::statement('ALTER TABLE promotion_usage_reservations ADD CONSTRAINT promotion_usage_rule_version_chk CHECK (`rule_version` >= 1)');
        DB::statement('ALTER TABLE promotion_usage_reservations ADD CONSTRAINT promotion_usage_limits_chk CHECK ((`total_use_limit_snapshot` IS NULL OR `total_use_limit_snapshot` >= 1) AND (`per_user_use_limit_snapshot` IS NULL OR `per_user_use_limit_snapshot` >= 1) AND (`total_use_limit_snapshot` IS NULL OR `per_user_use_limit_snapshot` IS NULL OR `per_user_use_limit_snapshot` <= `total_use_limit_snapshot`))');
        DB::statement("ALTER TABLE promotion_usage_reservations ADD CONSTRAINT promotion_usage_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `resolution_configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$' AND `quote_configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$' AND `rule_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE promotion_usage_reservations ADD CONSTRAINT promotion_usage_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");
        DB::statement("ALTER TABLE promotion_usage_releases ADD CONSTRAINT promotion_usage_release_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `reservation_configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
    }

    private function createReservationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_reservations_insert_guard
BEFORE INSERT ON promotion_usage_reservations
FOR EACH ROW
BEGIN
    DECLARE valid_subject_count INT DEFAULT 0;
    DECLARE valid_resolution_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_subject_count
    FROM users user_row
    WHERE user_row.id = NEW.user_id
      AND user_row.account_status = 'active'
      AND user_row.account_type IN ('customer', 'agent');

    IF valid_subject_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion reservation requires one active pricing subject.';
    END IF;

    SELECT COUNT(*) INTO valid_resolution_count
    FROM pricing_rule_resolutions resolution
    INNER JOIN pricing_rules rule_row ON rule_row.id = resolution.pricing_rule_id
    INNER JOIN pricing_rule_versions version_row ON version_row.id = resolution.pricing_rule_version_id
    WHERE resolution.id = NEW.pricing_rule_resolution_id
      AND resolution.public_id = NEW.resolution_public_id_snapshot
      AND resolution.configuration_snapshot_hash = NEW.resolution_configuration_snapshot_hash
      AND resolution.user_id = NEW.user_id
      AND resolution.plan_offering_id = NEW.plan_offering_id
      AND resolution.pricing_rule_id = NEW.pricing_rule_id
      AND resolution.pricing_rule_version_id = NEW.pricing_rule_version_id
      AND resolution.rule_code_snapshot = NEW.rule_code_snapshot
      AND resolution.rule_kind_snapshot = 'promotion'
      AND resolution.rule_version = NEW.rule_version
      AND resolution.rule_configuration_hash = NEW.rule_configuration_hash
      AND resolution.discount_irr = NEW.discount_irr
      AND resolution.discount_irr > 0
      AND rule_row.id = NEW.pricing_rule_id
      AND rule_row.rule_code = NEW.rule_code_snapshot
      AND rule_row.kind = 'promotion'
      AND version_row.id = NEW.pricing_rule_version_id
      AND version_row.pricing_rule_id = NEW.pricing_rule_id
      AND version_row.version = NEW.rule_version
      AND version_row.configuration_hash = NEW.rule_configuration_hash
      AND (version_row.total_use_limit <=> NEW.total_use_limit_snapshot)
      AND (version_row.per_user_use_limit <=> NEW.per_user_use_limit_snapshot)
      AND version_row.allows_free_order = NEW.allows_free_order_snapshot;

    IF valid_resolution_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion reservation immutable resolution identity is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_quote_count
    FROM quotes quote_row
    INNER JOIN pricing_rule_resolutions resolution ON resolution.id = NEW.pricing_rule_resolution_id
    WHERE quote_row.id = NEW.quote_id
      AND quote_row.public_id = NEW.quote_public_id_snapshot
      AND quote_row.configuration_snapshot_hash = NEW.quote_configuration_snapshot_hash
      AND quote_row.user_id = NEW.user_id
      AND quote_row.plan_offering_id = NEW.plan_offering_id
      AND quote_row.offering_discount_eligible = 1
      AND quote_row.effective_price_irr = resolution.input_price_irr
      AND quote_row.discount_reference_code = NEW.rule_code_snapshot
      AND quote_row.discount_irr = NEW.discount_irr
      AND quote_row.currency = 'IRR'
      AND quote_row.valid_from <= NEW.created_at
      AND quote_row.expires_at > NEW.created_at
      AND (quote_row.final_price_irr > 0 OR NEW.allows_free_order_snapshot = 1);

    IF valid_quote_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion reservation quote binding is invalid.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion reservation snapshot hash mismatch.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_reservations_update_guard
BEFORE UPDATE ON promotion_usage_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage reservations are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_reservations_delete_guard
BEFORE DELETE ON promotion_usage_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage reservations are non-deletable.';
END
SQL);
    }

    private function createReleaseGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_releases_insert_guard
BEFORE INSERT ON promotion_usage_releases
FOR EACH ROW
BEGIN
    DECLARE valid_reservation_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_reservation_count
    FROM promotion_usage_reservations reservation
    WHERE reservation.id = NEW.promotion_usage_reservation_id
      AND reservation.user_id = NEW.released_by_user_id
      AND reservation.configuration_snapshot_hash = NEW.reservation_configuration_snapshot_hash;

    IF valid_reservation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion release reservation identity is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_releases_update_guard
BEFORE UPDATE ON promotion_usage_releases
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage releases are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_releases_delete_guard
BEFORE DELETE ON promotion_usage_releases
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage releases are non-deletable.';
END
SQL);
    }
};
