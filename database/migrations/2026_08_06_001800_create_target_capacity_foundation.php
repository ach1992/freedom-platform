<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('panel_target_capacities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('panel_service_target_id')->unique();
            $table->foreign('panel_service_target_id', 'target_capacity_target_fk')->references('id')->on('panel_service_targets')->restrictOnDelete();
            $table->unsignedBigInteger('hard_limit');
            $table->unsignedBigInteger('held_units')->default(0);
            $table->unsignedBigInteger('committed_units')->default(0);
            $table->string('state', 16)->default('disabled');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('panel_capacity_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('panel_target_capacity_id');
            $table->foreign('panel_target_capacity_id', 'capacity_reservation_capacity_fk')->references('id')->on('panel_target_capacities')->restrictOnDelete();
            $table->string('reservation_key', 128)->unique();
            $table->string('purpose_code', 64);
            $table->unsignedBigInteger('units');
            $table->string('state', 16)->default('held');
            $table->dateTime('expires_at', 6);
            $table->unsignedBigInteger('version')->default(1);
            $table->string('last_command_key', 128);
            $table->char('last_payload_hmac', 64);
            $table->string('last_correlation_id', 64);
            $table->string('last_source_code', 64);
            $table->string('last_reason_code', 64);
            $table->timestamps(6);
            $table->index(['panel_target_capacity_id', 'state', 'expires_at'], 'capacity_reservation_state_expiry_idx');
        });

        Schema::create('panel_capacity_reservation_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('panel_capacity_reservation_id');
            $table->foreign('panel_capacity_reservation_id', 'capacity_event_reservation_fk')->references('id')->on('panel_capacity_reservations')->restrictOnDelete();
            $table->string('command_key', 128)->unique();
            $table->string('action', 32);
            $table->char('payload_hmac', 64);
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16);
            $table->unsignedBigInteger('units');
            $table->unsignedBigInteger('reservation_version');
            $table->unsignedBigInteger('capacity_hard_limit');
            $table->unsignedBigInteger('capacity_held_units');
            $table->unsignedBigInteger('capacity_committed_units');
            $table->string('capacity_state', 16);
            $table->unsignedBigInteger('capacity_version');
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['panel_capacity_reservation_id', 'reservation_version'], 'capacity_event_reservation_version_unique');
        });

        Schema::create('panel_target_capacity_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('panel_target_capacity_id');
            $table->foreign('panel_target_capacity_id', 'target_capacity_history_parent_fk')->references('id')->on('panel_target_capacities')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 96);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['panel_target_capacity_id', 'version'], 'target_capacity_history_version_unique');
        });

        DB::statement("ALTER TABLE panel_target_capacities ADD CONSTRAINT target_capacity_state_chk CHECK (`state` IN ('disabled', 'enabled'))");
        DB::statement('ALTER TABLE panel_target_capacities ADD CONSTRAINT target_capacity_limit_chk CHECK (`hard_limit` >= 1 AND `held_units` + `committed_units` <= `hard_limit`)');
        DB::statement('ALTER TABLE panel_target_capacities ADD CONSTRAINT target_capacity_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE panel_capacity_reservations ADD CONSTRAINT capacity_reservation_state_chk CHECK (`state` IN ('held', 'committed', 'released', 'expired'))");
        DB::statement('ALTER TABLE panel_capacity_reservations ADD CONSTRAINT capacity_reservation_units_chk CHECK (`units` >= 1)');
        DB::statement('ALTER TABLE panel_capacity_reservations ADD CONSTRAINT capacity_reservation_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE panel_capacity_reservations ADD CONSTRAINT capacity_reservation_hmac_chk CHECK (CHAR_LENGTH(`last_payload_hmac`) = 64)');
        DB::statement("ALTER TABLE panel_capacity_reservation_events ADD CONSTRAINT capacity_event_action_chk CHECK (`action` IN ('capacity.reserve', 'capacity.commit', 'capacity.release', 'capacity.expire'))");

        $this->createReservationTriggers();
        $this->createAppendOnlyTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('panel_target_capacity_histories');
        Schema::dropIfExists('panel_capacity_reservation_events');
        Schema::dropIfExists('panel_capacity_reservations');
        Schema::dropIfExists('panel_target_capacities');
    }

    private function createReservationTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER capacity_reservations_insert_guard
BEFORE INSERT ON panel_capacity_reservations
FOR EACH ROW
BEGIN
    IF NEW.state <> 'held' OR NEW.version <> 1 OR NEW.last_command_key <> NEW.reservation_key THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'New capacity reservation must be an initial hold.';
    END IF;
    UPDATE panel_target_capacities
       SET held_units = held_units + NEW.units, version = version + 1, updated_at = CURRENT_TIMESTAMP(6)
     WHERE id = NEW.panel_target_capacity_id
       AND state = 'enabled'
       AND held_units + committed_units + NEW.units <= hard_limit;
    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Insufficient target capacity.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER capacity_reservations_after_insert
AFTER INSERT ON panel_capacity_reservations
FOR EACH ROW
BEGIN
    INSERT INTO panel_capacity_reservation_events (
        panel_capacity_reservation_id, command_key, action, payload_hmac, from_state, to_state,
        units, reservation_version, capacity_hard_limit, capacity_held_units, capacity_committed_units,
        capacity_state, capacity_version, correlation_id, source_code, reason_code, created_at
    )
    SELECT NEW.id, NEW.last_command_key, 'capacity.reserve', NEW.last_payload_hmac, NULL, NEW.state,
           NEW.units, NEW.version, capacity.hard_limit, capacity.held_units, capacity.committed_units,
           capacity.state, capacity.version, NEW.last_correlation_id, NEW.last_source_code,
           NEW.last_reason_code, CURRENT_TIMESTAMP(6)
      FROM panel_target_capacities capacity
     WHERE capacity.id = NEW.panel_target_capacity_id;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER capacity_reservations_update_guard
BEFORE UPDATE ON panel_capacity_reservations
FOR EACH ROW
BEGIN
    IF NOT (OLD.panel_target_capacity_id <=> NEW.panel_target_capacity_id)
       OR NOT (OLD.reservation_key <=> NEW.reservation_key)
       OR NOT (OLD.purpose_code <=> NEW.purpose_code)
       OR NOT (OLD.units <=> NEW.units)
       OR NOT (OLD.expires_at <=> NEW.expires_at)
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity reservation identity is immutable.';
    END IF;
    IF NEW.version <> OLD.version + 1 OR NEW.state = OLD.state THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity reservation update requires one state transition.';
    END IF;

    IF OLD.state = 'held' AND NEW.state = 'committed' THEN
        IF OLD.expires_at <= CURRENT_TIMESTAMP(6) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expired capacity hold cannot be committed.';
        END IF;
        UPDATE panel_target_capacities
           SET held_units = held_units - OLD.units, committed_units = committed_units + OLD.units,
               version = version + 1, updated_at = CURRENT_TIMESTAMP(6)
         WHERE id = OLD.panel_target_capacity_id AND held_units >= OLD.units;
    ELSEIF OLD.state = 'held' AND NEW.state IN ('released', 'expired') THEN
        IF NEW.state = 'expired' AND OLD.expires_at > CURRENT_TIMESTAMP(6) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity hold is not expired yet.';
        END IF;
        UPDATE panel_target_capacities
           SET held_units = held_units - OLD.units, version = version + 1, updated_at = CURRENT_TIMESTAMP(6)
         WHERE id = OLD.panel_target_capacity_id AND held_units >= OLD.units;
    ELSEIF OLD.state = 'committed' AND NEW.state = 'released' THEN
        UPDATE panel_target_capacities
           SET committed_units = committed_units - OLD.units, version = version + 1, updated_at = CURRENT_TIMESTAMP(6)
         WHERE id = OLD.panel_target_capacity_id AND committed_units >= OLD.units;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity reservation transition is not allowed.';
    END IF;

    IF ROW_COUNT() <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity counters are inconsistent.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER capacity_reservations_after_update
AFTER UPDATE ON panel_capacity_reservations
FOR EACH ROW
BEGIN
    INSERT INTO panel_capacity_reservation_events (
        panel_capacity_reservation_id, command_key, action, payload_hmac, from_state, to_state,
        units, reservation_version, capacity_hard_limit, capacity_held_units, capacity_committed_units,
        capacity_state, capacity_version, correlation_id, source_code, reason_code, created_at
    )
    SELECT NEW.id, NEW.last_command_key,
           CASE NEW.state WHEN 'committed' THEN 'capacity.commit' WHEN 'released' THEN 'capacity.release' WHEN 'expired' THEN 'capacity.expire' END,
           NEW.last_payload_hmac, OLD.state, NEW.state, NEW.units, NEW.version,
           capacity.hard_limit, capacity.held_units, capacity.committed_units, capacity.state, capacity.version,
           NEW.last_correlation_id, NEW.last_source_code, NEW.last_reason_code, CURRENT_TIMESTAMP(6)
      FROM panel_target_capacities capacity
     WHERE capacity.id = NEW.panel_target_capacity_id;
END
SQL);

        DB::unprepared("CREATE TRIGGER capacity_reservations_delete_guard BEFORE DELETE ON panel_capacity_reservations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity reservations are immutable records.'");
    }

    private function createAppendOnlyTriggers(): void
    {
        DB::unprepared("CREATE TRIGGER capacity_events_update_guard BEFORE UPDATE ON panel_capacity_reservation_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity events are append-only.'");
        DB::unprepared("CREATE TRIGGER capacity_events_delete_guard BEFORE DELETE ON panel_capacity_reservation_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity events are append-only.'");
        DB::unprepared("CREATE TRIGGER target_capacity_histories_update_guard BEFORE UPDATE ON panel_target_capacity_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity history is append-only.'");
        DB::unprepared("CREATE TRIGGER target_capacity_histories_delete_guard BEFORE DELETE ON panel_target_capacity_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Capacity history is append-only.'");
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_reservations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_reservations_after_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_reservations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_reservations_after_update');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_reservations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS capacity_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS target_capacity_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS target_capacity_histories_delete_guard');
    }
};
