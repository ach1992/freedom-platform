<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-002 CAT-004 CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('plan_offering_route_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->unique()->constrained('plan_offerings')->restrictOnDelete();
            $table->char('configuration_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('plan_offering_routes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_offering_route_policy_id');
            $table->foreign('plan_offering_route_policy_id', 'offering_route_policy_fk')
                ->references('id')->on('plan_offering_route_policies')->restrictOnDelete();
            $table->unsignedBigInteger('sales_server_id');
            $table->foreign('sales_server_id', 'offering_route_server_fk')
                ->references('id')->on('sales_servers')->restrictOnDelete();
            $table->unsignedBigInteger('panel_service_target_id');
            $table->foreign('panel_service_target_id', 'offering_route_target_fk')
                ->references('id')->on('panel_service_targets')->restrictOnDelete();
            $table->string('route_type', 16);
            $table->unsignedInteger('priority');
            $table->boolean('customer_selectable')->default(false);
            $table->text('disclosure_fa')->nullable();
            $table->text('disclosure_en')->nullable();
            $table->timestamps(6);
            $table->unique(['plan_offering_route_policy_id', 'priority'], 'offering_route_priority_unique');
            $table->unique(['plan_offering_route_policy_id', 'panel_service_target_id'], 'offering_route_target_unique');
            $table->index(['panel_service_target_id', 'route_type'], 'offering_route_target_type_idx');
        });

        Schema::create('plan_offering_route_policy_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_offering_route_policy_id');
            $table->foreign('plan_offering_route_policy_id', 'offering_route_history_policy_fk')
                ->references('id')->on('plan_offering_route_policies')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->char('configuration_hash', 64);
            $table->unsignedInteger('route_count');
            $table->unsignedInteger('fallback_count');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['plan_offering_route_policy_id', 'version'], 'offering_route_history_version_unique');
        });

        Schema::create('plan_offering_route_selections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->unsignedBigInteger('plan_offering_route_policy_id');
            $table->foreign('plan_offering_route_policy_id', 'route_selection_policy_fk')
                ->references('id')->on('plan_offering_route_policies')->restrictOnDelete();
            $table->unsignedBigInteger('plan_offering_route_id');
            $table->foreign('plan_offering_route_id', 'route_selection_route_fk')
                ->references('id')->on('plan_offering_routes')->restrictOnDelete();
            $table->unsignedBigInteger('capacity_reservation_id')->unique();
            $table->foreign('capacity_reservation_id', 'route_selection_capacity_fk')
                ->references('id')->on('panel_capacity_reservations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->string('selection_mode', 32);
            $table->unsignedBigInteger('requested_route_id')->nullable();
            $table->foreign('requested_route_id', 'route_selection_requested_fk')
                ->references('id')->on('plan_offering_routes')->restrictOnDelete();
            $table->unsignedBigInteger('panel_protocol_profile_id');
            $table->foreign('panel_protocol_profile_id', 'route_selection_profile_fk')
                ->references('id')->on('panel_protocol_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('units');
            $table->boolean('fallback_used')->default(false);
            $table->text('disclosure_fa_snapshot')->nullable();
            $table->unsignedBigInteger('selected_sales_server_id');
            $table->foreign('selected_sales_server_id', 'route_selection_server_fk')
                ->references('id')->on('sales_servers')->restrictOnDelete();
            $table->unsignedBigInteger('selected_service_target_id');
            $table->foreign('selected_service_target_id', 'route_selection_target_fk')
                ->references('id')->on('panel_service_targets')->restrictOnDelete();
            $table->unsignedBigInteger('capacity_hard_limit');
            $table->unsignedBigInteger('capacity_held_units');
            $table->unsignedBigInteger('capacity_committed_units');
            $table->unsignedBigInteger('capacity_available_units');
            $table->unsignedBigInteger('capacity_version');
            $table->unsignedBigInteger('capacity_reservation_version');
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->dateTime('created_at', 6);
            $table->index(['plan_offering_id', 'created_at'], 'route_selection_offering_created_idx');
            $table->index(['user_id', 'created_at'], 'route_selection_user_created_idx');
        });

        $this->addChecks();
        $this->createPolicyGuards();
        $this->createSelectionGuards();
        $this->createDependencyGuards();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('plan_offering_route_selections');
        Schema::dropIfExists('plan_offering_route_policy_histories');
        Schema::dropIfExists('plan_offering_routes');
        Schema::dropIfExists('plan_offering_route_policies');
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE plan_offering_route_policies ADD CONSTRAINT route_policy_hash_chk CHECK (CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement('ALTER TABLE plan_offering_route_policies ADD CONSTRAINT route_policy_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE plan_offering_routes ADD CONSTRAINT offering_route_type_chk CHECK (`route_type` IN ('primary', 'fallback'))");
        DB::statement("ALTER TABLE plan_offering_routes ADD CONSTRAINT offering_route_priority_chk CHECK ((`route_type` = 'primary' AND `priority` = 0) OR (`route_type` = 'fallback' AND `priority` >= 1))");
        DB::statement("ALTER TABLE plan_offering_routes ADD CONSTRAINT offering_route_disclosure_chk CHECK (`route_type` = 'primary' OR (`disclosure_fa` IS NOT NULL AND CHAR_LENGTH(TRIM(`disclosure_fa`)) > 0))");
        DB::statement('ALTER TABLE plan_offering_route_policy_histories ADD CONSTRAINT route_history_counts_chk CHECK (`route_count` >= 1 AND `fallback_count` < `route_count`)');
        DB::statement("ALTER TABLE plan_offering_route_selections ADD CONSTRAINT route_selection_actor_chk CHECK (`actor_type` IN ('customer', 'agent'))");
        DB::statement("ALTER TABLE plan_offering_route_selections ADD CONSTRAINT route_selection_mode_chk CHECK (`selection_mode` IN ('customer_selects', 'system_selects', 'hybrid'))");
        DB::statement('ALTER TABLE plan_offering_route_selections ADD CONSTRAINT route_selection_units_chk CHECK (`units` >= 1)');
        DB::statement('ALTER TABLE plan_offering_route_selections ADD CONSTRAINT route_selection_capacity_chk CHECK (`capacity_hard_limit` >= 1 AND `capacity_held_units` + `capacity_committed_units` <= `capacity_hard_limit` AND `capacity_available_units` = `capacity_hard_limit` - `capacity_held_units` - `capacity_committed_units`)');
        DB::statement("ALTER TABLE plan_offering_route_selections ADD CONSTRAINT route_selection_disclosure_chk CHECK (`fallback_used` = 0 OR (`disclosure_fa_snapshot` IS NOT NULL AND CHAR_LENGTH(TRIM(`disclosure_fa_snapshot`)) > 0))");
    }

    private function createPolicyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_route_policies_insert_guard
BEFORE INSERT ON plan_offering_route_policies
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM plan_offerings WHERE id = NEW.plan_offering_id AND state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy requires a draft offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_route_policies_update_guard
BEFORE UPDATE ON plan_offering_route_policies
FOR EACH ROW
BEGIN
    IF NOT (OLD.plan_offering_id <=> NEW.plan_offering_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy offering is immutable.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy version must increment once.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM plan_offerings WHERE id = OLD.plan_offering_id AND state = 'draft') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy is mutable only for a draft offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_routes_insert_guard
BEFORE INSERT ON plan_offering_routes
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offering_route_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = NEW.plan_offering_route_policy_id AND offering.state = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route requires a draft offering policy.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_routes_update_guard
BEFORE UPDATE ON plan_offering_routes
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offering_route_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = OLD.plan_offering_route_policy_id AND offering.state = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route is mutable only for a draft offering.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_routes_delete_guard
BEFORE DELETE ON plan_offering_routes
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offering_route_policies policy
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE policy.id = OLD.plan_offering_route_policy_id AND offering.state = 'draft'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route is removable only for a draft offering.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER offering_route_histories_update_guard BEFORE UPDATE ON plan_offering_route_policy_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy history is append-only.'");
        DB::unprepared("CREATE TRIGGER offering_route_histories_delete_guard BEFORE DELETE ON plan_offering_route_policy_histories FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route policy history is append-only.'");
    }

    private function createSelectionGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_route_selections_insert_guard
BEFORE INSERT ON plan_offering_route_selections
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offering_routes route
        INNER JOIN plan_offering_route_policies policy ON policy.id = route.plan_offering_route_policy_id
        WHERE route.id = NEW.plan_offering_route_id
          AND policy.id = NEW.plan_offering_route_policy_id
          AND policy.plan_offering_id = NEW.plan_offering_id
          AND route.sales_server_id = NEW.selected_sales_server_id
          AND route.panel_service_target_id = NEW.selected_service_target_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route selection snapshot does not match policy.';
    END IF;
    IF NEW.requested_route_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM plan_offering_routes
        WHERE id = NEW.requested_route_id AND plan_offering_route_policy_id = NEW.plan_offering_route_policy_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Requested route does not belong to policy.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM panel_capacity_reservations reservation
        INNER JOIN panel_target_capacities capacity ON capacity.id = reservation.panel_target_capacity_id
        WHERE reservation.id = NEW.capacity_reservation_id
          AND capacity.panel_service_target_id = NEW.selected_service_target_id
          AND reservation.state = 'held'
          AND reservation.units = NEW.units
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route selection capacity hold is invalid.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER offering_route_selections_update_guard BEFORE UPDATE ON plan_offering_route_selections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route selections are immutable.'");
        DB::unprepared("CREATE TRIGGER offering_route_selections_delete_guard BEFORE DELETE ON plan_offering_route_selections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Route selections are immutable.'");
    }

    private function createDependencyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_route_targets_archive_guard
BEFORE UPDATE ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived' AND EXISTS (
        SELECT 1 FROM plan_offering_routes route
        INNER JOIN plan_offering_route_policies policy ON policy.id = route.plan_offering_route_policy_id
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE route.panel_service_target_id = OLD.id AND offering.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active offering route depends on service target.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER offering_route_servers_archive_guard
BEFORE UPDATE ON sales_servers
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived' AND EXISTS (
        SELECT 1 FROM plan_offering_routes route
        INNER JOIN plan_offering_route_policies policy ON policy.id = route.plan_offering_route_policy_id
        INNER JOIN plan_offerings offering ON offering.id = policy.plan_offering_id
        WHERE route.sales_server_id = OLD.id AND offering.state = 'active'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active offering route depends on sales server.';
    END IF;
END
SQL);
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_policies_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_policies_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_routes_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_routes_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_routes_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_histories_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_selections_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_selections_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_selections_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_targets_archive_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS offering_route_servers_archive_guard');
    }
};
