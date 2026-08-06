<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-006 CAT-008 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('trial_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->unique()->constrained('plan_offerings')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->bigInteger('data_bytes');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('daily_capacity');
            $table->string('phone_verification_policy', 32)->default('none');
            $table->boolean('membership_required')->default(false);
            $table->boolean('one_per_user')->default(true);
            $table->boolean('one_per_phone')->default(true);
            $table->boolean('administrator_regrant_allowed')->default(false);
            $table->boolean('fallback_allowed')->default(false);
            $table->string('tag_match_mode', 16)->default('all');
            $table->string('delivery_template_key', 191);
            $table->char('configuration_hash', 64);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
        });

        Schema::create('trial_policy_tiers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('trial_policy_id')->constrained('trial_policies')->restrictOnDelete();
            $table->string('tier_code', 32);
            $table->dateTime('created_at', 6);
            $table->unique(['trial_policy_id', 'tier_code'], 'trial_policy_tier_unique');
        });

        Schema::create('trial_policy_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('trial_policy_id')->constrained('trial_policies')->restrictOnDelete();
            $table->unsignedBigInteger('customer_tag_id');
            $table->foreign('customer_tag_id', 'trial_policy_tag_customer_fk')
                ->references('id')->on('customer_tags')->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['trial_policy_id', 'customer_tag_id'], 'trial_policy_tag_unique');
        });

        Schema::create('trial_policy_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('trial_policy_id')->constrained('trial_policies')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->char('configuration_hash', 64);
            $table->boolean('enabled');
            $table->unsignedInteger('tier_count');
            $table->unsignedInteger('tag_count');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['trial_policy_id', 'version'], 'trial_policy_history_version_unique');
        });

        Schema::create('trial_daily_capacity_counters', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('trial_policy_id')->constrained('trial_policies')->restrictOnDelete();
            $table->date('capacity_date');
            $table->unsignedInteger('hard_limit_snapshot');
            $table->unsignedInteger('reserved_count')->default(0);
            $table->unsignedInteger('committed_count')->default(0);
            $table->unsignedInteger('released_count')->default(0);
            $table->unsignedInteger('expired_count')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->unique(['trial_policy_id', 'capacity_date'], 'trial_daily_capacity_unique');
        });

        Schema::create('trial_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->foreignId('trial_policy_id')->constrained('trial_policies')->restrictOnDelete();
            $table->unsignedBigInteger('trial_policy_version');
            $table->char('policy_configuration_hash', 64);
            $table->foreignId('plan_offering_id')->constrained('plan_offerings')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('phone_number_id')->nullable()->constrained('phone_numbers')->restrictOnDelete();
            $table->unsignedBigInteger('active_user_id')->nullable();
            $table->foreign('active_user_id', 'trial_reservation_active_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unsignedBigInteger('active_phone_number_id')->nullable();
            $table->foreign('active_phone_number_id', 'trial_reservation_active_phone_fk')
                ->references('id')->on('phone_numbers')->restrictOnDelete();
            $table->foreignId('trial_daily_capacity_counter_id')
                ->constrained('trial_daily_capacity_counters')->restrictOnDelete();
            $table->unsignedBigInteger('plan_offering_route_selection_id');
            $table->foreign('plan_offering_route_selection_id', 'trial_reservation_route_selection_fk')
                ->references('id')->on('plan_offering_route_selections')->restrictOnDelete();
            $table->string('state', 16)->default('reserved');
            $table->unsignedBigInteger('version')->default(1);
            $table->date('capacity_date');
            $table->bigInteger('data_bytes');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('daily_capacity_snapshot');
            $table->string('phone_verification_policy_snapshot', 32);
            $table->boolean('membership_required_snapshot');
            $table->boolean('one_per_user_snapshot');
            $table->boolean('one_per_phone_snapshot');
            $table->boolean('fallback_allowed_snapshot');
            $table->boolean('fallback_used_snapshot');
            $table->text('disclosure_fa_snapshot')->nullable();
            $table->string('delivery_template_key_snapshot', 191);
            $table->char('eligibility_snapshot_hash', 64);
            $table->dateTime('expires_at', 6);
            $table->dateTime('committed_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->dateTime('eligibility_reset_at', 6)->nullable();
            $table->unsignedBigInteger('eligibility_reset_by_administrator_id')->nullable();
            $table->foreign('eligibility_reset_by_administrator_id', 'trial_reservation_reset_actor_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->timestamps(6);
            $table->unique(['trial_policy_id', 'active_user_id'], 'trial_reservation_active_user_unique');
            $table->unique(['trial_policy_id', 'active_phone_number_id'], 'trial_reservation_active_phone_unique');
            $table->index(['user_id', 'state', 'created_at'], 'trial_reservation_user_state_idx');
            $table->index(['phone_number_id', 'state', 'created_at'], 'trial_reservation_phone_state_idx');
            $table->index(['trial_policy_id', 'capacity_date', 'state'], 'trial_reservation_policy_date_idx');
        });

        Schema::create('trial_reservation_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('trial_reservation_id')->constrained('trial_reservations')->restrictOnDelete();
            $table->string('command_key', 128)->unique();
            $table->char('payload_hash', 64);
            $table->string('action', 64);
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16);
            $table->unsignedBigInteger('reservation_version');
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->string('source_code', 64);
            $table->string('reason_code', 64);
            $table->text('reason')->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['trial_reservation_id', 'reservation_version'], 'trial_reservation_event_version_unique');
            $table->index(['trial_reservation_id', 'created_at'], 'trial_reservation_event_created_idx');
        });

        $this->addChecks();
        $this->createPolicyGuards();
        $this->createPolicyChildGuards();
        $this->createCounterGuards();
        $this->createReservationGuards();
        $this->createImmutableGuards();
        $this->createOfferingActivationGuard();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('trial_reservation_events');
        Schema::dropIfExists('trial_reservations');
        Schema::dropIfExists('trial_daily_capacity_counters');
        Schema::dropIfExists('trial_policy_histories');
        Schema::dropIfExists('trial_policy_tags');
        Schema::dropIfExists('trial_policy_tiers');
        Schema::dropIfExists('trial_policies');
    }

    private function addChecks(): void
    {
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_positive_chk CHECK (`data_bytes` >= 1 AND `duration_days` >= 1 AND `daily_capacity` >= 1)');
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_phone_chk CHECK (`phone_verification_policy` IN (\'none\', \'telegram_contact_only\', \'sms_otp_only\', \'either\', \'both\'))');
        DB::statement("ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_tag_match_chk CHECK (`tag_match_mode` IN ('any', 'all'))");
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_abuse_chk CHECK (`one_per_user` = 1 OR `one_per_phone` = 1)');
        DB::statement("ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_phone_only_chk CHECK (`one_per_user` = 1 OR `phone_verification_policy` <> 'none')");
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_delivery_key_chk CHECK (CHAR_LENGTH(`delivery_template_key`) >= 3)');
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_hash_chk CHECK (CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement('ALTER TABLE trial_policies ADD CONSTRAINT trial_policy_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE trial_policy_tiers ADD CONSTRAINT trial_policy_tier_chk CHECK (`tier_code` IN ('new', 'normal', 'loyal', 'vip'))");

        DB::statement('ALTER TABLE trial_daily_capacity_counters ADD CONSTRAINT trial_capacity_counts_chk CHECK (`hard_limit_snapshot` >= 1 AND `reserved_count` + `committed_count` <= `hard_limit_snapshot`)');
        DB::statement('ALTER TABLE trial_daily_capacity_counters ADD CONSTRAINT trial_capacity_version_chk CHECK (`version` >= 1)');

        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_state_chk CHECK (`state` IN ('reserved', 'committed', 'released', 'expired'))");
        DB::statement('ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_positive_chk CHECK (`trial_policy_version` >= 1 AND `version` >= 1 AND `data_bytes` >= 1 AND `duration_days` >= 1 AND `daily_capacity_snapshot` >= 1)');
        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_phone_policy_chk CHECK (`phone_verification_policy_snapshot` IN ('none', 'telegram_contact_only', 'sms_otp_only', 'either', 'both'))");
        DB::statement('ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_hashes_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64 AND CHAR_LENGTH(`policy_configuration_hash`) = 64 AND CHAR_LENGTH(`eligibility_snapshot_hash`) = 64)');
        DB::statement('ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_fallback_chk CHECK (`fallback_used_snapshot` = 0 OR `fallback_allowed_snapshot` = 1)');
        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_disclosure_chk CHECK ((`fallback_used_snapshot` = 0 AND `disclosure_fa_snapshot` IS NULL) OR (`fallback_used_snapshot` = 1 AND `disclosure_fa_snapshot` IS NOT NULL AND CHAR_LENGTH(TRIM(`disclosure_fa_snapshot`)) > 0))");
        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_timestamps_chk CHECK ((`state` = 'reserved' AND `committed_at` IS NULL AND `released_at` IS NULL AND `expired_at` IS NULL) OR (`state` = 'committed' AND `committed_at` IS NOT NULL AND `released_at` IS NULL AND `expired_at` IS NULL) OR (`state` = 'released' AND `committed_at` IS NULL AND `released_at` IS NOT NULL AND `expired_at` IS NULL) OR (`state` = 'expired' AND `committed_at` IS NULL AND `released_at` IS NULL AND `expired_at` IS NOT NULL))");
        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_user_guard_chk CHECK ((`state` IN ('reserved', 'committed') AND ((`one_per_user_snapshot` = 1 AND `eligibility_reset_at` IS NULL AND `active_user_id` = `user_id`) OR (`one_per_user_snapshot` = 0 AND `active_user_id` IS NULL) OR (`state` = 'committed' AND `eligibility_reset_at` IS NOT NULL AND `active_user_id` IS NULL))) OR (`state` IN ('released', 'expired') AND `active_user_id` IS NULL))");
        DB::statement("ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_phone_guard_chk CHECK ((`state` IN ('reserved', 'committed') AND ((`one_per_phone_snapshot` = 1 AND `phone_number_id` IS NOT NULL AND `eligibility_reset_at` IS NULL AND `active_phone_number_id` = `phone_number_id`) OR (`one_per_phone_snapshot` = 0 AND `active_phone_number_id` IS NULL) OR (`one_per_phone_snapshot` = 1 AND `phone_number_id` IS NULL AND `active_phone_number_id` IS NULL) OR (`state` = 'committed' AND `eligibility_reset_at` IS NOT NULL AND `active_phone_number_id` IS NULL))) OR (`state` IN ('released', 'expired') AND `active_phone_number_id` IS NULL))");
        DB::statement('ALTER TABLE trial_reservations ADD CONSTRAINT trial_reservation_reset_chk CHECK ((`eligibility_reset_at` IS NULL AND `eligibility_reset_by_administrator_id` IS NULL) OR (`state` = \'committed\' AND `eligibility_reset_at` IS NOT NULL AND `eligibility_reset_by_administrator_id` IS NOT NULL))');

        DB::statement("ALTER TABLE trial_reservation_events ADD CONSTRAINT trial_event_action_chk CHECK (`action` IN ('trial.reserve', 'trial.commit', 'trial.release', 'trial.expire', 'trial.eligibility_reset'))");
        DB::statement("ALTER TABLE trial_reservation_events ADD CONSTRAINT trial_event_state_chk CHECK ((`from_state` IS NULL OR `from_state` IN ('reserved', 'committed', 'released', 'expired')) AND `to_state` IN ('reserved', 'committed', 'released', 'expired'))");
        DB::statement('ALTER TABLE trial_reservation_events ADD CONSTRAINT trial_event_hash_chk CHECK (CHAR_LENGTH(`payload_hash`) = 64)');
        DB::statement('ALTER TABLE trial_reservation_events ADD CONSTRAINT trial_event_version_chk CHECK (`reservation_version` >= 1)');
    }

    private function createPolicyGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_policies_insert_guard
BEFORE INSERT ON trial_policies
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings
        WHERE id = NEW.plan_offering_id AND state = 'draft' AND trial_allowed = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy requires a draft trial-enabled Offering.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_policies_update_guard
BEFORE UPDATE ON trial_policies
FOR EACH ROW
BEGIN
    IF NOT (OLD.plan_offering_id <=> NEW.plan_offering_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy Offering is immutable.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy version must advance exactly once.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings
        WHERE id = NEW.plan_offering_id AND state = 'draft' AND trial_allowed = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy changes require a draft trial-enabled Offering.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_policies_delete_guard
BEFORE DELETE ON trial_policies
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policies are immutable and cannot be deleted.';
END
SQL);
    }

    private function createPolicyChildGuards(): void
    {
        foreach (['trial_policy_tiers', 'trial_policy_tags'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_insert_guard BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM trial_policies tp JOIN plan_offerings po ON po.id = tp.plan_offering_id WHERE tp.id = NEW.trial_policy_id AND po.state = 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy children require a draft Offering.'; END IF; END");
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy child rows are immutable; replace through service.'; END");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM trial_policies tp JOIN plan_offerings po ON po.id = tp.plan_offering_id WHERE tp.id = OLD.trial_policy_id AND po.state = 'draft') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial policy children require a draft Offering.'; END IF; END");
        }
    }

    private function createCounterGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_capacity_insert_guard
BEFORE INSERT ON trial_daily_capacity_counters
FOR EACH ROW
BEGIN
    IF NEW.reserved_count <> 0 OR NEW.committed_count <> 0 OR NEW.released_count <> 0 OR NEW.expired_count <> 0 OR NEW.version <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity counters must start empty.';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM trial_policies WHERE id = NEW.trial_policy_id AND enabled = 1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity requires an enabled policy.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_capacity_update_guard
BEFORE UPDATE ON trial_daily_capacity_counters
FOR EACH ROW
BEGIN
    IF NOT (OLD.trial_policy_id <=> NEW.trial_policy_id)
       OR NOT (OLD.capacity_date <=> NEW.capacity_date)
       OR NOT (OLD.hard_limit_snapshot <=> NEW.hard_limit_snapshot) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity identity and hard limit are immutable.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity version must advance exactly once.';
    END IF;
    IF NEW.reserved_count < OLD.reserved_count
       OR NEW.committed_count < OLD.committed_count
       OR NEW.released_count < OLD.released_count
       OR NEW.expired_count < OLD.expired_count THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity counters are monotonic.';
    END IF;
    IF (NEW.reserved_count - OLD.reserved_count) + (NEW.committed_count - OLD.committed_count) + (NEW.released_count - OLD.released_count) + (NEW.expired_count - OLD.expired_count) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity updates must account for one transition.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_capacity_delete_guard
BEFORE DELETE ON trial_daily_capacity_counters
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial capacity counters are immutable.';
END
SQL);
    }

    private function createReservationGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_reservations_insert_guard
BEFORE INSERT ON trial_reservations
FOR EACH ROW
BEGIN
    IF NEW.state <> 'reserved' OR NEW.version <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservations must start reserved at version one.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM trial_policies
        WHERE id = NEW.trial_policy_id
          AND enabled = 1
          AND version = NEW.trial_policy_version
          AND configuration_hash = NEW.policy_configuration_hash
          AND plan_offering_id = NEW.plan_offering_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservation policy snapshot is stale or invalid.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM trial_daily_capacity_counters
        WHERE id = NEW.trial_daily_capacity_counter_id
          AND trial_policy_id = NEW.trial_policy_id
          AND capacity_date = NEW.capacity_date
          AND hard_limit_snapshot = NEW.daily_capacity_snapshot
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservation capacity snapshot is invalid.';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM plan_offering_route_selections
        WHERE id = NEW.plan_offering_route_selection_id
          AND plan_offering_id = NEW.plan_offering_id
          AND purpose_code = 'trial'
          AND state = 'held'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservation requires a held trial route selection.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_reservations_update_guard
BEFORE UPDATE ON trial_reservations
FOR EACH ROW
BEGIN
    IF NOT (OLD.command_key <=> NEW.command_key)
       OR NOT (OLD.payload_hash <=> NEW.payload_hash)
       OR NOT (OLD.trial_policy_id <=> NEW.trial_policy_id)
       OR NOT (OLD.trial_policy_version <=> NEW.trial_policy_version)
       OR NOT (OLD.policy_configuration_hash <=> NEW.policy_configuration_hash)
       OR NOT (OLD.plan_offering_id <=> NEW.plan_offering_id)
       OR NOT (OLD.user_id <=> NEW.user_id)
       OR NOT (OLD.phone_number_id <=> NEW.phone_number_id)
       OR NOT (OLD.trial_daily_capacity_counter_id <=> NEW.trial_daily_capacity_counter_id)
       OR NOT (OLD.plan_offering_route_selection_id <=> NEW.plan_offering_route_selection_id)
       OR NOT (OLD.capacity_date <=> NEW.capacity_date)
       OR NOT (OLD.data_bytes <=> NEW.data_bytes)
       OR NOT (OLD.duration_days <=> NEW.duration_days)
       OR NOT (OLD.daily_capacity_snapshot <=> NEW.daily_capacity_snapshot)
       OR NOT (OLD.phone_verification_policy_snapshot <=> NEW.phone_verification_policy_snapshot)
       OR NOT (OLD.membership_required_snapshot <=> NEW.membership_required_snapshot)
       OR NOT (OLD.one_per_user_snapshot <=> NEW.one_per_user_snapshot)
       OR NOT (OLD.one_per_phone_snapshot <=> NEW.one_per_phone_snapshot)
       OR NOT (OLD.fallback_allowed_snapshot <=> NEW.fallback_allowed_snapshot)
       OR NOT (OLD.fallback_used_snapshot <=> NEW.fallback_used_snapshot)
       OR NOT (OLD.disclosure_fa_snapshot <=> NEW.disclosure_fa_snapshot)
       OR NOT (OLD.delivery_template_key_snapshot <=> NEW.delivery_template_key_snapshot)
       OR NOT (OLD.eligibility_snapshot_hash <=> NEW.eligibility_snapshot_hash)
       OR NOT (OLD.expires_at <=> NEW.expires_at)
       OR NOT (OLD.correlation_id <=> NEW.correlation_id)
       OR NOT (OLD.source_code <=> NEW.source_code)
       OR NOT (OLD.reason_code <=> NEW.reason_code)
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservation immutable snapshot cannot change.';
    END IF;
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservation version must advance exactly once.';
    END IF;
    IF NOT (
        (OLD.state = 'reserved' AND NEW.state IN ('committed', 'released', 'expired'))
        OR (OLD.state = 'committed' AND NEW.state = 'committed' AND OLD.eligibility_reset_at IS NULL AND NEW.eligibility_reset_at IS NOT NULL)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid trial reservation transition.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_reservations_delete_guard
BEFORE DELETE ON trial_reservations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial reservations are immutable.';
END
SQL);
    }

    private function createImmutableGuards(): void
    {
        foreach (['trial_policy_histories', 'trial_reservation_events'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_update_guard BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial history and event rows are immutable.'; END");
            DB::unprepared("CREATE TRIGGER {$table}_delete_guard BEFORE DELETE ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial history and event rows are immutable.'; END");
        }
    }

    private function createOfferingActivationGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trial_offering_activation_guard
BEFORE UPDATE ON plan_offerings
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' AND OLD.state <> 'active' AND NEW.trial_allowed = 1 THEN
        IF NOT EXISTS (
            SELECT 1 FROM trial_policies
            WHERE plan_offering_id = NEW.id AND enabled = 1
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Trial-enabled Offering requires an enabled trial policy.';
        END IF;
    END IF;
END
SQL);
    }

    private function dropTriggers(): void
    {
        foreach ([
            'trial_offering_activation_guard',
            'trial_reservation_events_delete_guard',
            'trial_reservation_events_update_guard',
            'trial_policy_histories_delete_guard',
            'trial_policy_histories_update_guard',
            'trial_reservations_delete_guard',
            'trial_reservations_update_guard',
            'trial_reservations_insert_guard',
            'trial_capacity_delete_guard',
            'trial_capacity_update_guard',
            'trial_capacity_insert_guard',
            'trial_policy_tags_delete_guard',
            'trial_policy_tags_update_guard',
            'trial_policy_tags_insert_guard',
            'trial_policy_tiers_delete_guard',
            'trial_policy_tiers_update_guard',
            'trial_policy_tiers_insert_guard',
            'trial_policies_delete_guard',
            'trial_policies_update_guard',
            'trial_policies_insert_guard',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
