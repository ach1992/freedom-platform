<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRO-001 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('promotion_usage_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('redemption_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->foreignId('promotion_usage_reservation_id')->unique()->constrained('promotion_usage_reservations')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->unique()->constrained('purchase_settlements')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('pricing_rule_id')->constrained('pricing_rules')->restrictOnDelete();
            $table->foreignId('pricing_rule_version_id')->constrained('pricing_rule_versions')->restrictOnDelete();
            $table->ulid('reservation_public_id');
            $table->ulid('purchase_settlement_public_id');
            $table->ulid('quote_public_id');
            $table->bigInteger('discount_irr');
            $table->char('reservation_configuration_snapshot_hash', 64);
            $table->dateTime('created_at', 6);
            $table->index(['pricing_rule_id', 'user_id', 'created_at'], 'promotion_redemption_rule_user_idx');
        });

        DB::statement('ALTER TABLE promotion_usage_redemptions ADD CONSTRAINT promotion_redemption_discount_chk CHECK (`discount_irr` > 0)');
        DB::statement("ALTER TABLE promotion_usage_redemptions ADD CONSTRAINT promotion_redemption_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `reservation_configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");

        $this->createRedemptionGuards();
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_releases_insert_guard');
        $this->createRedemptionAwareReleaseGuard();
    }

    public function down(): void
    {
        if (Schema::hasTable('promotion_usage_redemptions') && DB::table('promotion_usage_redemptions')->exists()) {
            throw new RuntimeException('Cannot roll back promotion finalization while redemptions exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_releases_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_redemptions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_redemptions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS promotion_usage_redemptions_insert_guard');
        Schema::dropIfExists('promotion_usage_redemptions');
        $this->createPriorReleaseGuard();
    }

    private function createRedemptionGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_redemptions_insert_guard
BEFORE INSERT ON promotion_usage_redemptions
FOR EACH ROW
BEGIN
    DECLARE valid_reservation_count INT DEFAULT 0;
    DECLARE valid_settlement_count INT DEFAULT 0;
    DECLARE release_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_reservation_count
    FROM promotion_usage_reservations reservation
    WHERE reservation.id = NEW.promotion_usage_reservation_id
      AND reservation.public_id = NEW.reservation_public_id
      AND reservation.user_id = NEW.user_id
      AND reservation.pricing_rule_id = NEW.pricing_rule_id
      AND reservation.pricing_rule_version_id = NEW.pricing_rule_version_id
      AND reservation.quote_public_id_snapshot = NEW.quote_public_id
      AND reservation.discount_irr = NEW.discount_irr
      AND reservation.configuration_snapshot_hash = NEW.reservation_configuration_snapshot_hash;

    IF valid_reservation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemption reservation identity is invalid.';
    END IF;

    SELECT COUNT(*) INTO release_count
    FROM promotion_usage_releases release_row
    WHERE release_row.promotion_usage_reservation_id = NEW.promotion_usage_reservation_id;

    IF release_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Released promotion usage cannot be redeemed.';
    END IF;

    SELECT COUNT(*) INTO valid_settlement_count
    FROM purchase_settlements settlement
    INNER JOIN payment_intents intent_row ON intent_row.id = settlement.payment_intent_id
    INNER JOIN promotion_usage_reservations reservation ON reservation.id = NEW.promotion_usage_reservation_id
    WHERE settlement.id = NEW.purchase_settlement_id
      AND settlement.public_id = NEW.purchase_settlement_public_id
      AND settlement.user_id = NEW.user_id
      AND settlement.source_quote_id = reservation.quote_id
      AND settlement.source_quote_public_id = NEW.quote_public_id
      AND intent_row.id = settlement.payment_intent_id
      AND intent_row.purpose = 'purchase'
      AND intent_row.state = 'captured'
      AND intent_row.user_id = NEW.user_id
      AND intent_row.source_quote_id = reservation.quote_id
      AND intent_row.source_quote_public_id = NEW.quote_public_id;

    IF valid_settlement_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion redemption requires the exact authoritative captured purchase settlement.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_redemptions_update_guard
BEFORE UPDATE ON promotion_usage_redemptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage redemptions are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_redemptions_delete_guard
BEFORE DELETE ON promotion_usage_redemptions
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion usage redemptions are non-deletable.';
END
SQL);
    }

    private function createRedemptionAwareReleaseGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER promotion_usage_releases_insert_guard
BEFORE INSERT ON promotion_usage_releases
FOR EACH ROW
BEGIN
    DECLARE valid_reservation_count INT DEFAULT 0;
    DECLARE redemption_count INT DEFAULT 0;

    SELECT COUNT(*) INTO valid_reservation_count
    FROM promotion_usage_reservations reservation
    WHERE reservation.id = NEW.promotion_usage_reservation_id
      AND reservation.user_id = NEW.released_by_user_id
      AND reservation.configuration_snapshot_hash = NEW.reservation_configuration_snapshot_hash;

    IF valid_reservation_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Promotion release reservation identity is invalid.';
    END IF;

    SELECT COUNT(*) INTO redemption_count
    FROM promotion_usage_redemptions redemption
    WHERE redemption.promotion_usage_reservation_id = NEW.promotion_usage_reservation_id;

    IF redemption_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Redeemed promotion usage cannot be released.';
    END IF;
END
SQL);
    }

    private function createPriorReleaseGuard(): void
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
    }
};
