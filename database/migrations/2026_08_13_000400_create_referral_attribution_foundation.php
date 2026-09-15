<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement REF-001 ONB-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::create('referral_identities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('token', 32)->unique();
            $table->dateTime('created_at', 6);
        });
        DB::statement("ALTER TABLE referral_identities ADD CONSTRAINT referral_identity_token_chk CHECK (`token` REGEXP '^[0-9a-f]{32}$')");

        DB::statement(<<<'SQL'
INSERT INTO referral_identities (user_id, token, created_at)
SELECT id, LOWER(SUBSTRING(SHA2(CONCAT(UUID(), ':', id), 256), 1, 32)), UTC_TIMESTAMP(6)
FROM users
ORDER BY id
SQL);

        Schema::table('purchase_settlements', function (Blueprint $table): void {
            $table->unique(['id', 'user_id'], 'purchase_settlement_id_user_unique');
        });

        Schema::create('referral_relationships', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('referred_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('inviter_referral_identity_id')->constrained('referral_identities')->restrictOnDelete();
            $table->unsignedBigInteger('locked_purchase_settlement_id')->nullable()->unique();
            $table->dateTime('bound_at', 6);
            $table->dateTime('locked_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['inviter_user_id', 'created_at'], 'referral_relationship_inviter_created_idx');
        });
        DB::statement('ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationship_no_self_chk CHECK (`referred_user_id` <> `inviter_user_id`)');
        DB::statement('ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationship_lock_pair_chk CHECK ((`locked_purchase_settlement_id` IS NULL AND `locked_at` IS NULL) OR (`locked_purchase_settlement_id` IS NOT NULL AND `locked_at` IS NOT NULL))');
        DB::statement('ALTER TABLE referral_relationships ADD CONSTRAINT referral_relationship_locked_settlement_user_fk FOREIGN KEY (`locked_purchase_settlement_id`, `referred_user_id`) REFERENCES `purchase_settlements` (`id`, `user_id`) ON DELETE RESTRICT');

        Schema::create('referral_attribution_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('relationship_id')->constrained('referral_relationships')->restrictOnDelete();
            $table->string('event_type', 16);
            $table->foreignId('inviter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->nullable()->constrained('purchase_settlements')->restrictOnDelete();
            $table->dateTime('occurred_at', 6);
            $table->index(['relationship_id', 'occurred_at'], 'referral_event_relationship_time_idx');
        });
        DB::statement("ALTER TABLE referral_attribution_events ADD CONSTRAINT referral_event_type_chk CHECK (`event_type` IN ('bound','corrected','locked'))");

        $this->createIdentityGuards();
        $this->createRelationshipGuards();
        $this->createEventGuards();
        $this->createAutomaticIdentityTrigger();
        $this->createPurchaseLockTrigger();
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS purchase_settlements_after_insert_referral_lock');
        DB::unprepared('DROP TRIGGER IF EXISTS users_after_insert_referral_identity');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_attribution_events_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_attribution_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_attribution_events_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_relationships_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_relationships_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_relationships_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_identities_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS referral_identities_update_guard');

        Schema::dropIfExists('referral_attribution_events');
        Schema::dropIfExists('referral_relationships');
        Schema::dropIfExists('referral_identities');

        Schema::table('purchase_settlements', function (Blueprint $table): void {
            $table->dropUnique('purchase_settlement_id_user_unique');
        });
    }

    private function createIdentityGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_identities_update_guard
BEFORE UPDATE ON referral_identities
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral identities are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_identities_delete_guard
BEFORE DELETE ON referral_identities
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral identities are non-deletable.';
END
SQL);
    }

    private function createRelationshipGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_relationships_insert_guard
BEFORE INSERT ON referral_relationships
FOR EACH ROW
BEGIN
    DECLARE valid_identity_count INT DEFAULT 0;
    DECLARE successful_purchase_count INT DEFAULT 0;

    IF NEW.referred_user_id = NEW.inviter_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Self-referral is forbidden.';
    END IF;

    IF NEW.locked_purchase_settlement_id IS NOT NULL OR NEW.locked_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral relationship must start unlocked.';
    END IF;

    SELECT COUNT(*) INTO valid_identity_count
    FROM referral_identities
    WHERE id = NEW.inviter_referral_identity_id
      AND user_id = NEW.inviter_user_id;

    IF valid_identity_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral inviter identity does not match inviter user.';
    END IF;

    SELECT COUNT(*) INTO successful_purchase_count
    FROM purchase_settlements
    WHERE user_id = NEW.referred_user_id;

    IF successful_purchase_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral inviter cannot be bound after successful purchase.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_relationships_update_guard
BEFORE UPDATE ON referral_relationships
FOR EACH ROW
BEGIN
    DECLARE valid_identity_count INT DEFAULT 0;
    DECLARE successful_purchase_count INT DEFAULT 0;
    DECLARE inviter_changed BOOLEAN DEFAULT FALSE;
    DECLARE lock_changed BOOLEAN DEFAULT FALSE;

    IF NOT (NEW.referred_user_id <=> OLD.referred_user_id)
       OR NOT (NEW.bound_at <=> OLD.bound_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral relationship identity is immutable.';
    END IF;

    SET inviter_changed = NOT (NEW.inviter_user_id <=> OLD.inviter_user_id)
        OR NOT (NEW.inviter_referral_identity_id <=> OLD.inviter_referral_identity_id);
    SET lock_changed = NOT (NEW.locked_purchase_settlement_id <=> OLD.locked_purchase_settlement_id)
        OR NOT (NEW.locked_at <=> OLD.locked_at);

    IF OLD.locked_purchase_settlement_id IS NOT NULL OR OLD.locked_at IS NOT NULL THEN
        IF inviter_changed OR lock_changed OR NOT (NEW.updated_at <=> OLD.updated_at) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked referral relationship is immutable.';
        END IF;
    END IF;

    IF NEW.referred_user_id = NEW.inviter_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Self-referral is forbidden.';
    END IF;

    SELECT COUNT(*) INTO valid_identity_count
    FROM referral_identities
    WHERE id = NEW.inviter_referral_identity_id
      AND user_id = NEW.inviter_user_id;

    IF valid_identity_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral inviter identity does not match inviter user.';
    END IF;

    IF inviter_changed THEN
        IF OLD.locked_purchase_settlement_id IS NOT NULL OR OLD.locked_at IS NOT NULL
           OR NEW.locked_purchase_settlement_id IS NOT NULL OR NEW.locked_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral inviter correction is forbidden after lock.';
        END IF;

        SELECT COUNT(*) INTO successful_purchase_count
        FROM purchase_settlements
        WHERE user_id = NEW.referred_user_id;

        IF successful_purchase_count > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral inviter correction is forbidden after successful purchase.';
        END IF;
    END IF;

    IF lock_changed THEN
        IF OLD.locked_purchase_settlement_id IS NOT NULL OR OLD.locked_at IS NOT NULL
           OR NEW.locked_purchase_settlement_id IS NULL OR NEW.locked_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral lock transition is invalid.';
        END IF;
    END IF;

    IF NOT inviter_changed AND NOT lock_changed AND NOT (NEW.updated_at <=> OLD.updated_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral relationship has no mutable metadata.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_relationships_delete_guard
BEFORE DELETE ON referral_relationships
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral relationships are non-deletable.';
END
SQL);
    }

    private function createEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_attribution_events_insert_guard
BEFORE INSERT ON referral_attribution_events
FOR EACH ROW
BEGIN
    DECLARE current_inviter_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE current_lock_settlement_id BIGINT UNSIGNED DEFAULT NULL;

    SELECT inviter_user_id, locked_purchase_settlement_id
      INTO current_inviter_id, current_lock_settlement_id
    FROM referral_relationships
    WHERE id = NEW.relationship_id
    LIMIT 1;

    IF current_inviter_id IS NULL OR current_inviter_id <> NEW.inviter_user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral event inviter does not match relationship.';
    END IF;

    IF NEW.event_type = 'bound' THEN
        IF NEW.actor_administrator_id IS NOT NULL OR NEW.purchase_settlement_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bound referral event metadata is invalid.';
        END IF;
    ELSEIF NEW.event_type = 'corrected' THEN
        IF NEW.actor_administrator_id IS NULL OR NEW.purchase_settlement_id IS NOT NULL OR current_lock_settlement_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Corrected referral event metadata is invalid.';
        END IF;
    ELSEIF NEW.event_type = 'locked' THEN
        IF NEW.actor_administrator_id IS NOT NULL
           OR NEW.purchase_settlement_id IS NULL
           OR current_lock_settlement_id IS NULL
           OR current_lock_settlement_id <> NEW.purchase_settlement_id THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Locked referral event metadata is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral event type is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_attribution_events_update_guard
BEFORE UPDATE ON referral_attribution_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral attribution events are immutable.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER referral_attribution_events_delete_guard
BEFORE DELETE ON referral_attribution_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Referral attribution events are non-deletable.';
END
SQL);
    }

    private function createAutomaticIdentityTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER users_after_insert_referral_identity
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO referral_identities (user_id, token, created_at)
    VALUES (
        NEW.id,
        LOWER(SUBSTRING(SHA2(CONCAT(UUID(), ':', NEW.id, ':', UTC_TIMESTAMP(6)), 256), 1, 32)),
        UTC_TIMESTAMP(6)
    );
END
SQL);
    }

    private function createPurchaseLockTrigger(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER purchase_settlements_after_insert_referral_lock
AFTER INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    DECLARE relationship_id_value BIGINT UNSIGNED DEFAULT NULL;
    DECLARE inviter_user_id_value BIGINT UNSIGNED DEFAULT NULL;

    SELECT id, inviter_user_id
      INTO relationship_id_value, inviter_user_id_value
    FROM referral_relationships
    WHERE referred_user_id = NEW.user_id
      AND locked_purchase_settlement_id IS NULL
    LIMIT 1
    FOR UPDATE;

    IF relationship_id_value IS NOT NULL THEN
        UPDATE referral_relationships
        SET locked_purchase_settlement_id = NEW.id,
            locked_at = NEW.settled_at,
            updated_at = NEW.settled_at
        WHERE id = relationship_id_value;

        INSERT INTO referral_attribution_events (
            relationship_id,
            event_type,
            inviter_user_id,
            actor_administrator_id,
            purchase_settlement_id,
            occurred_at
        ) VALUES (
            relationship_id_value,
            'locked',
            inviter_user_id_value,
            NULL,
            NEW.id,
            NEW.settled_at
        );
    END IF;
END
SQL);
    }
};
