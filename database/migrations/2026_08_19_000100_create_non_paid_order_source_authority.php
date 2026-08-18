<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::create('order_source_authorizations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('source_type', 32);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();

            $table->foreignId('trial_reservation_id')->nullable()->unique()->constrained('trial_reservations')->restrictOnDelete();
            $table->ulid('trial_reservation_public_id')->nullable();
            $table->foreignId('benefit_entitlement_id')->nullable()->unique()->constrained('benefit_code_free_service_entitlements')->restrictOnDelete();
            $table->ulid('benefit_entitlement_public_id')->nullable();

            $table->string('authorization_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_snapshot_hash', 64);

            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);

            $table->index(['user_id', 'created_at'], 'order_source_auth_user_created_idx');
            $table->index(['source_type', 'created_at'], 'order_source_auth_type_created_idx');
        });

        DB::statement("ALTER TABLE order_source_authorizations ADD CONSTRAINT order_source_auth_type_chk CHECK (`source_type` IN ('trial','gift','service_code','benefit_code','admin_grant'))");
        DB::statement("ALTER TABLE order_source_authorizations ADD CONSTRAINT order_source_auth_actor_chk CHECK (`actor_type` IN ('system','administrator') AND ((`source_type` = 'admin_grant' AND `actor_type` = 'administrator' AND `actor_id` IS NOT NULL) OR (`source_type` <> 'admin_grant' AND `actor_type` = 'system' AND `actor_id` IS NULL)))");
        DB::statement("ALTER TABLE order_source_authorizations ADD CONSTRAINT order_source_auth_upstream_shape_chk CHECK ((`source_type` = 'trial' AND `trial_reservation_id` IS NOT NULL AND `trial_reservation_public_id` IS NOT NULL AND `benefit_entitlement_id` IS NULL AND `benefit_entitlement_public_id` IS NULL) OR (`source_type` IN ('gift','service_code','benefit_code') AND `trial_reservation_id` IS NULL AND `trial_reservation_public_id` IS NULL AND `benefit_entitlement_id` IS NOT NULL AND `benefit_entitlement_public_id` IS NOT NULL) OR (`source_type` = 'admin_grant' AND `trial_reservation_id` IS NULL AND `trial_reservation_public_id` IS NULL AND `benefit_entitlement_id` IS NULL AND `benefit_entitlement_public_id` IS NULL))");
        DB::statement("ALTER TABLE order_source_authorizations ADD CONSTRAINT order_source_auth_hash_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_snapshot_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE order_source_authorizations ADD CONSTRAINT order_source_auth_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 32 AND OCTET_LENGTH(`configuration_snapshot`) <= 8192)");

        $this->createInsertGuard();
        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        if (DB::table('order_source_authorizations')->exists()) {
            throw new RuntimeException('Cannot roll back non-paid Order source authority while authorization records exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS order_source_authorizations_insert_guard');
        Schema::dropIfExists('order_source_authorizations');
    }

    private function createInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_source_authorizations_insert_guard
BEFORE INSERT ON order_source_authorizations
FOR EACH ROW
BEGIN
    DECLARE valid_upstream_count INT DEFAULT 0;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> LOWER(NEW.configuration_snapshot_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorization snapshot hash is invalid.';
    END IF;

    IF NEW.source_type = 'trial' THEN
        SELECT COUNT(*) INTO valid_upstream_count
        FROM trial_reservations reservation_row
        WHERE reservation_row.id = NEW.trial_reservation_id
          AND reservation_row.public_id = NEW.trial_reservation_public_id
          AND reservation_row.user_id = NEW.user_id
          AND reservation_row.plan_offering_id = NEW.plan_offering_id
          AND reservation_row.state = 'committed';

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial Order authorization requires one committed Trial reservation.';
        END IF;
    ELSEIF NEW.source_type IN ('gift','service_code','benefit_code') THEN
        SELECT COUNT(*) INTO valid_upstream_count
        FROM benefit_code_free_service_entitlements entitlement_row
        WHERE entitlement_row.id = NEW.benefit_entitlement_id
          AND entitlement_row.public_id = NEW.benefit_entitlement_public_id
          AND entitlement_row.user_id = NEW.user_id
          AND entitlement_row.plan_offering_id = NEW.plan_offering_id
          AND entitlement_row.configuration_hash = NEW.configuration_snapshot_hash;

        IF valid_upstream_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit-code Order authorization requires one matching free-service entitlement.';
        END IF;
    ELSEIF NEW.source_type = 'admin_grant' THEN
        IF CHAR_LENGTH(TRIM(NEW.reason_code)) < 3 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administrator grant requires an explicit reason.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unsupported non-paid Order authorization source.';
    END IF;
END
SQL);
    }

    private function createImmutabilityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_source_authorizations_update_guard
BEFORE UPDATE ON order_source_authorizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorizations are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER order_source_authorizations_delete_guard
BEFORE DELETE ON order_source_authorizations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Order source authorizations are non-deletable.';
END
SQL);
    }
};
