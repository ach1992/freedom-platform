<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-003 CHN-001 ACL-002 SEC-001 SEC-003 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('required_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('channel_key', 64)->unique();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('chat_type', 16);
            $table->string('visibility', 16);
            $table->string('display_title', 191);
            $table->text('join_url_ciphertext');
            $table->char('join_url_hash', 64);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('state', 16)->default('draft');
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedBigInteger('verified_bot_id')->nullable();
            $table->string('verification_result_code', 64)->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'sort_order', 'id'], 'required_channels_listing_idx');
        });

        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_key_chk CHECK (`channel_key` REGEXP '^[a-z][a-z0-9_.-]{2,63}$')");
        DB::statement('ALTER TABLE required_channels ADD CONSTRAINT required_channels_chat_id_chk CHECK (`telegram_chat_id` < 0)');
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_chat_type_chk CHECK (`chat_type` IN ('group','supergroup','channel'))");
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_visibility_chk CHECK (`visibility` IN ('public','private'))");
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_title_chk CHECK (CHAR_LENGTH(TRIM(`display_title`)) BETWEEN 1 AND 191 AND `display_title` NOT REGEXP '[[:cntrl:]]')");
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_join_hash_chk CHECK (`join_url_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE required_channels ADD CONSTRAINT required_channels_join_cipher_chk CHECK (OCTET_LENGTH(`join_url_ciphertext`) BETWEEN 32 AND 8192)');
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_state_chk CHECK (`state` IN ('draft','active','disabled'))");
        DB::statement('ALTER TABLE required_channels ADD CONSTRAINT required_channels_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_verification_code_chk CHECK (`verification_result_code` IS NULL OR `verification_result_code` IN ('telegram_membership_creator','telegram_membership_administrator'))");
        DB::statement("ALTER TABLE required_channels ADD CONSTRAINT required_channels_verification_shape_chk CHECK ((`state` = 'active' AND `verified_bot_id` IS NOT NULL AND `verified_bot_id` >= 1 AND `verification_result_code` IS NOT NULL AND `verified_at` IS NOT NULL) OR (`state` <> 'active' AND `verified_bot_id` IS NULL AND `verification_result_code` IS NULL AND `verified_at` IS NULL))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER required_channels_insert_guard
BEFORE INSERT ON required_channels
FOR EACH ROW
BEGIN
    IF NEW.state <> 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram required channel must be created as draft.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER required_channels_update_guard
BEFORE UPDATE ON required_channels
FOR EACH ROW
BEGIN
    IF NEW.version <> OLD.version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram required-channel version must advance exactly once.';
    END IF;

    IF OLD.state = 'active' AND NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram required channel must be disabled before mutation.';
    END IF;

    IF OLD.state = 'active' AND NEW.state NOT IN ('active', 'disabled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram required channel can only transition to disabled.';
    END IF;

    IF OLD.state = 'disabled' AND NEW.state = 'draft' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Disabled Telegram required channel cannot return to draft.';
    END IF;

    IF OLD.state = 'active' AND (
        NOT (OLD.channel_key <=> NEW.channel_key)
        OR NOT (OLD.telegram_chat_id <=> NEW.telegram_chat_id)
        OR NOT (OLD.chat_type <=> NEW.chat_type)
        OR NOT (OLD.visibility <=> NEW.visibility)
        OR NOT (OLD.display_title <=> NEW.display_title)
        OR NOT (OLD.join_url_ciphertext <=> NEW.join_url_ciphertext)
        OR NOT (OLD.join_url_hash <=> NEW.join_url_hash)
        OR NOT (OLD.sort_order <=> NEW.sort_order)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active Telegram required-channel definition is immutable.';
    END IF;

    IF NEW.state = 'active' AND OLD.state <> 'active' AND (
        NOT (OLD.channel_key <=> NEW.channel_key)
        OR NOT (OLD.telegram_chat_id <=> NEW.telegram_chat_id)
        OR NOT (OLD.chat_type <=> NEW.chat_type)
        OR NOT (OLD.visibility <=> NEW.visibility)
        OR NOT (OLD.display_title <=> NEW.display_title)
        OR NOT (OLD.join_url_ciphertext <=> NEW.join_url_ciphertext)
        OR NOT (OLD.join_url_hash <=> NEW.join_url_hash)
        OR NOT (OLD.sort_order <=> NEW.sort_order)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram required-channel activation cannot change definition.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('required_channels');
    }
};
