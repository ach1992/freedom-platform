<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 QUA-007 */
    public function up(): void
    {
        if (! Schema::hasTable('telegram_accounts')) {
            throw new RuntimeException('Telegram interaction authority requires Telegram identity accounts.');
        }

        if ($this->authorityReady()) {
            return;
        }

        $this->resetInterruptedInstallIfSafe();
        $this->createTables();

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->ensureCapability();
            $this->installConstraints();
            $this->installGuards();
        }
    }

    public function down(): void
    {
        foreach (['telegram_interaction_callbacks', 'telegram_interaction_transitions', 'telegram_interaction_sessions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Telegram interaction authority while durable interaction state exists.');
            }
        }

        $this->dropGuards();
        Schema::dropIfExists('telegram_interaction_callbacks');
        Schema::dropIfExists('telegram_interaction_transitions');
        Schema::dropIfExists('telegram_interaction_sessions');

        if (Schema::hasTable('telegram_interaction_authority_capability')) {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_capability_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_capability_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_capability_insert_guard');
            Schema::dropIfExists('telegram_interaction_authority_capability');
        }
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('telegram_interaction_sessions')) {
            Schema::create('telegram_interaction_sessions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('bot_id');
                $table->unsignedBigInteger('telegram_user_id');
                $table->unsignedBigInteger('active_telegram_account_id')->nullable()->unique();
                $table->foreign('active_telegram_account_id', 'telegram_interaction_active_account_fk')
                    ->references('id')->on('telegram_accounts')->restrictOnDelete();
                $table->string('flow', 64);
                $table->string('state', 64);
                $table->string('status', 16);
                $table->json('payload');
                $table->char('payload_hash', 64);
                $table->unsignedBigInteger('version');
                $table->dateTime('expires_at', 6);
                $table->dateTime('terminal_at', 6)->nullable();
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->index(['telegram_account_id', 'created_at'], 'telegram_interaction_session_account_idx');
                $table->index(['bot_id', 'telegram_user_id'], 'telegram_interaction_session_actor_idx');
                $table->index(['status', 'expires_at'], 'telegram_interaction_session_status_expiry_idx');
            });
        }

        if (! Schema::hasTable('telegram_interaction_transitions')) {
            Schema::create('telegram_interaction_transitions', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('telegram_interaction_session_id')
                    ->constrained('telegram_interaction_sessions', indexName: 'telegram_interaction_transition_session_fk')
                    ->restrictOnDelete();
                $table->char('request_hash', 64)->unique();
                $table->char('command_hash', 64);
                $table->string('transition_type', 16);
                $table->unsignedBigInteger('from_version');
                $table->unsignedBigInteger('to_version');
                $table->string('from_state', 64)->nullable();
                $table->string('to_state', 64);
                $table->string('to_status', 16);
                $table->json('to_payload');
                $table->char('to_payload_hash', 64);
                $table->dateTime('to_expires_at', 6);
                $table->dateTime('created_at', 6);
                $table->unique(['telegram_interaction_session_id', 'to_version'], 'telegram_interaction_transition_version_unique');
                $table->index(['telegram_interaction_session_id', 'created_at'], 'telegram_interaction_transition_session_idx');
            });
        }

        if (! Schema::hasTable('telegram_interaction_callbacks')) {
            Schema::create('telegram_interaction_callbacks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->ulid('public_id')->unique();
                $table->foreignId('telegram_interaction_session_id')
                    ->constrained('telegram_interaction_sessions', indexName: 'telegram_interaction_callback_session_fk')
                    ->restrictOnDelete();
                $table->foreignId('telegram_account_id')->constrained('telegram_accounts')->restrictOnDelete();
                $table->unsignedBigInteger('session_version');
                $table->char('issue_request_hash', 64)->unique();
                $table->char('issue_command_hash', 64);
                $table->char('token_hash', 64)->unique();
                $table->longText('token_ciphertext');
                $table->string('action', 64);
                $table->json('action_payload');
                $table->char('action_payload_hash', 64);
                $table->string('state', 16);
                $table->unsignedBigInteger('accepted_update_id')->nullable();
                $table->dateTime('accepted_at', 6)->nullable();
                $table->dateTime('completed_at', 6)->nullable();
                $table->dateTime('expires_at', 6);
                $table->dateTime('created_at', 6);
                $table->dateTime('updated_at', 6);
                $table->index(['telegram_interaction_session_id', 'session_version'], 'telegram_interaction_callback_session_version_idx');
                $table->index(['state', 'expires_at'], 'telegram_interaction_callback_state_expiry_idx');
            });
        }
    }

    private function authorityReady(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        foreach ([
            'telegram_interaction_sessions',
            'telegram_interaction_transitions',
            'telegram_interaction_callbacks',
            'telegram_interaction_authority_capability',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        $capability = DB::table('telegram_interaction_authority_capability')->where('id', 1)->first(['capability_hash']);
        if ($capability === null
            || ! is_string($capability->capability_hash)
            || ! hash_equals($this->capabilityHash(), $capability->capability_hash)) {
            return false;
        }

        $triggerNames = [
            'telegram_interaction_capability_insert_guard',
            'telegram_interaction_capability_update_guard',
            'telegram_interaction_capability_delete_guard',
            'telegram_accounts_interaction_identity_update_guard',
            'telegram_interaction_sessions_insert_guard',            'telegram_interaction_sessions_update_guard',
            'telegram_interaction_sessions_delete_guard',
            'telegram_interaction_transitions_insert_guard',
            'telegram_interaction_transitions_update_guard',
            'telegram_interaction_transitions_delete_guard',
            'telegram_interaction_callbacks_insert_guard',
            'telegram_interaction_callbacks_update_guard',
            'telegram_interaction_callbacks_delete_guard',
        ];
        $triggers = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
            ->whereIn('TRIGGER_NAME', $triggerNames)
            ->count();
        if ($triggers !== count($triggerNames)) {
            return false;
        }

        $checkCount = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', [
                'telegram_interaction_sessions',
                'telegram_interaction_transitions',
                'telegram_interaction_callbacks',
            ])
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->where('CONSTRAINT_NAME', 'like', 'telegram_interaction_%')
            ->count();

        return $checkCount === 18;
    }

    private function resetInterruptedInstallIfSafe(): void
    {
        $tables = [
            'telegram_interaction_callbacks',
            'telegram_interaction_transitions',
            'telegram_interaction_sessions',
        ];
        if (DB::connection()->getDriverName() === 'mysql') {
            $tables[] = 'telegram_interaction_authority_capability';
        }

        $existing = array_values(array_filter($tables, static fn (string $table): bool => Schema::hasTable($table)));
        if ($existing === []) {
            return;
        }

        foreach ($existing as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Telegram interaction authority migration cannot repair an incomplete authority surface after durable rows exist.');
            }
        }

        $this->dropGuards();
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function ensureCapability(): void
    {
        $expectedHash = $this->capabilityHash();
        if (! Schema::hasTable('telegram_interaction_authority_capability')) {
            DB::statement(<<<'SQL'
CREATE TABLE telegram_interaction_authority_capability (
  `id` TINYINT UNSIGNED NOT NULL,
  `capability_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `telegram_interaction_capability_singleton_chk` CHECK (`id` = 1),
  CONSTRAINT `telegram_interaction_capability_hash_chk` CHECK (`capability_hash` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
            DB::table('telegram_interaction_authority_capability')->insert([
                'id' => 1,
                'capability_hash' => $expectedHash,
                'created_at' => now('UTC'),
            ]);
        } else {
            $rows = DB::table('telegram_interaction_authority_capability')->get(['id', 'capability_hash']);
            if ($rows->count() !== 1
                || (int) $rows->first()->id !== 1
                || ! is_string($rows->first()->capability_hash)
                || ! hash_equals($expectedHash, $rows->first()->capability_hash)) {
                throw new RuntimeException('Telegram interaction database capability does not match the application key.');
            }
        }

        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_capability_insert_guard BEFORE INSERT ON telegram_interaction_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_capability_update_guard BEFORE UPDATE ON telegram_interaction_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_capability_delete_guard BEFORE DELETE ON telegram_interaction_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction database capability is immutable.'; END");
    }

    private function installConstraints(): void
    {
        foreach ([
            "ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_name_chk CHECK (`flow` REGEXP '^[a-z][a-z0-9_.-]{0,63}$' AND `state` REGEXP '^[a-z][a-z0-9_.-]{0,63}$')",
            "ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_status_chk CHECK (`status` IN ('active','cancelled','completed','expired'))",
            "ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_shape_chk CHECK ((`status` = 'active' AND `active_telegram_account_id` = `telegram_account_id` AND `terminal_at` IS NULL) OR (`status` <> 'active' AND `active_telegram_account_id` IS NULL AND `terminal_at` IS NOT NULL))",
            "ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_payload_chk CHECK (JSON_VALID(`payload`) = 1 AND JSON_TYPE(`payload`) = 'OBJECT' AND OCTET_LENGTH(`payload`) <= 4096 AND `payload_hash` REGEXP '^[0-9a-f]{64}$')",
            'ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_version_chk CHECK (`version` >= 1)',
            'ALTER TABLE telegram_interaction_sessions ADD CONSTRAINT telegram_interaction_session_expiry_chk CHECK (`expires_at` > `created_at`)',
            "ALTER TABLE telegram_interaction_transitions ADD CONSTRAINT telegram_interaction_transition_hash_chk CHECK (`request_hash` REGEXP '^[0-9a-f]{64}$' AND `command_hash` REGEXP '^[0-9a-f]{64}$' AND `to_payload_hash` REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE telegram_interaction_transitions ADD CONSTRAINT telegram_interaction_transition_type_chk CHECK (`transition_type` IN ('start','transition','cancel','complete','expire'))",
            "ALTER TABLE telegram_interaction_transitions ADD CONSTRAINT telegram_interaction_transition_status_chk CHECK (`to_status` IN ('active','cancelled','completed','expired'))",
            "ALTER TABLE telegram_interaction_transitions ADD CONSTRAINT telegram_interaction_transition_shape_chk CHECK (`to_version` = `from_version` + 1 AND ((`transition_type` = 'start' AND `from_version` = 0 AND `from_state` IS NULL AND `to_status` = 'active') OR (`transition_type` = 'transition' AND `from_version` >= 1 AND `from_state` IS NOT NULL AND `to_status` = 'active') OR (`transition_type` = 'cancel' AND `to_status` = 'cancelled') OR (`transition_type` = 'complete' AND `to_status` = 'completed') OR (`transition_type` = 'expire' AND `to_status` = 'expired')))",
            "ALTER TABLE telegram_interaction_transitions ADD CONSTRAINT telegram_interaction_transition_payload_chk CHECK (JSON_VALID(`to_payload`) = 1 AND JSON_TYPE(`to_payload`) = 'OBJECT' AND OCTET_LENGTH(`to_payload`) <= 4096)",
            "ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_hash_chk CHECK (`issue_request_hash` REGEXP '^[0-9a-f]{64}$' AND `issue_command_hash` REGEXP '^[0-9a-f]{64}$' AND `token_hash` REGEXP '^[0-9a-f]{64}$' AND `action_payload_hash` REGEXP '^[0-9a-f]{64}$')",
            "ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_action_chk CHECK (`action` REGEXP '^[a-z][a-z0-9_.-]{0,63}$')",
            "ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_payload_chk CHECK (JSON_VALID(`action_payload`) = 1 AND JSON_TYPE(`action_payload`) = 'OBJECT' AND OCTET_LENGTH(`action_payload`) <= 4096)",
            "ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_state_chk CHECK (`state` IN ('pending','accepted','completed'))",
            "ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_shape_chk CHECK ((`state` = 'pending' AND `accepted_update_id` IS NULL AND `accepted_at` IS NULL AND `completed_at` IS NULL) OR (`state` = 'accepted' AND `accepted_update_id` IS NOT NULL AND `accepted_at` IS NOT NULL AND `completed_at` IS NULL) OR (`state` = 'completed' AND `accepted_update_id` IS NOT NULL AND `accepted_at` IS NOT NULL AND `completed_at` IS NOT NULL))",
            'ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_version_chk CHECK (`session_version` >= 1)',
            'ALTER TABLE telegram_interaction_callbacks ADD CONSTRAINT telegram_interaction_callback_expiry_chk CHECK (`expires_at` > `created_at`)',
        ] as $statement) {
            DB::statement($statement);
        }
    }

    private function installGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_accounts_interaction_identity_update_guard
BEFORE UPDATE ON telegram_accounts
FOR EACH ROW
BEGIN
    IF (OLD.user_id <> NEW.user_id OR OLD.bot_id <> NEW.bot_id OR OLD.telegram_user_id <> NEW.telegram_user_id)
       AND EXISTS (
           SELECT 1 FROM telegram_interaction_sessions session_row
           WHERE session_row.active_telegram_account_id = OLD.id
             AND session_row.status = 'active'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram account identity cannot change while interaction authority is active.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_interaction_sessions_insert_guard
BEFORE INSERT ON telegram_interaction_sessions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM telegram_interaction_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_interaction_capability, ''), 256)
    ) OR NOT EXISTS (
        SELECT 1 FROM telegram_accounts account_row
        WHERE account_row.id = NEW.telegram_account_id
          AND account_row.user_id = NEW.user_id
          AND account_row.bot_id = NEW.bot_id
          AND account_row.telegram_user_id = NEW.telegram_user_id
    ) OR COALESCE(@app_telegram_interaction_authority, '') <> 'session_start_v1'
       OR NEW.telegram_account_id <> COALESCE(@app_telegram_interaction_account_id, 0)
       OR NEW.active_telegram_account_id <> NEW.telegram_account_id
       OR NEW.version <> 1 OR NEW.status <> 'active' OR NEW.terminal_at IS NOT NULL
       OR BINARY NEW.payload_hash <> BINARY SHA2(CAST(NEW.payload AS CHAR), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction session creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_interaction_sessions_update_guard
BEFORE UPDATE ON telegram_interaction_sessions
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM telegram_interaction_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_interaction_capability, ''), 256)
    ) OR NEW.id <> COALESCE(@app_telegram_interaction_session_id, 0)
       OR NEW.telegram_account_id <> COALESCE(@app_telegram_interaction_account_id, 0)
       OR OLD.version <> COALESCE(@app_telegram_interaction_expected_version, 0)
       OR NEW.version <> OLD.version + 1
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.telegram_account_id <> NEW.telegram_account_id
       OR OLD.user_id <> NEW.user_id OR OLD.bot_id <> NEW.bot_id OR OLD.telegram_user_id <> NEW.telegram_user_id
       OR BINARY OLD.flow <> BINARY NEW.flow
       OR OLD.created_at <> NEW.created_at
       OR BINARY NEW.payload_hash <> BINARY SHA2(CAST(NEW.payload AS CHAR), 256) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction session update authority is invalid.';
    END IF;

    IF COALESCE(@app_telegram_interaction_authority, '') = 'session_transition_v1' THEN
        IF OLD.status <> 'active' OR NEW.status <> 'active'
           OR OLD.active_telegram_account_id <> OLD.telegram_account_id
           OR NEW.active_telegram_account_id <> NEW.telegram_account_id
           OR NEW.terminal_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction active transition is invalid.';
        END IF;
    ELSEIF COALESCE(@app_telegram_interaction_authority, '') IN ('session_cancel_v1','session_complete_v1','session_expire_v1') THEN
        IF OLD.status <> 'active' OR OLD.active_telegram_account_id <> OLD.telegram_account_id
           OR NEW.active_telegram_account_id IS NOT NULL OR NEW.terminal_at IS NULL
           OR BINARY OLD.state <> BINARY NEW.state OR BINARY OLD.payload <> BINARY NEW.payload
           OR BINARY OLD.payload_hash <> BINARY NEW.payload_hash OR OLD.expires_at <> NEW.expires_at
           OR (COALESCE(@app_telegram_interaction_authority, '') = 'session_cancel_v1' AND NEW.status <> 'cancelled')
           OR (COALESCE(@app_telegram_interaction_authority, '') = 'session_complete_v1' AND NEW.status <> 'completed')
           OR (COALESCE(@app_telegram_interaction_authority, '') = 'session_expire_v1' AND NEW.status <> 'expired') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction terminal transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction session authority is unknown.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_sessions_delete_guard BEFORE DELETE ON telegram_interaction_sessions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction sessions are non-deletable.'; END");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_interaction_transitions_insert_guard
BEFORE INSERT ON telegram_interaction_transitions
FOR EACH ROW
BEGIN
    DECLARE matching_session_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_session_count
    FROM telegram_interaction_sessions session_row
    WHERE session_row.id = NEW.telegram_interaction_session_id
      AND session_row.telegram_account_id = COALESCE(@app_telegram_interaction_account_id, 0)
      AND session_row.version = NEW.to_version
      AND BINARY session_row.state = BINARY NEW.to_state
      AND BINARY session_row.status = BINARY NEW.to_status
      AND BINARY session_row.payload_hash = BINARY NEW.to_payload_hash
      AND session_row.expires_at = NEW.to_expires_at;

    IF NOT EXISTS (
        SELECT 1 FROM telegram_interaction_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_interaction_capability, ''), 256)
    ) OR COALESCE(@app_telegram_interaction_authority, '') <> 'session_transition_record_v1'
       OR NEW.telegram_interaction_session_id <> COALESCE(@app_telegram_interaction_session_id, 0)
       OR NEW.from_version <> COALESCE(@app_telegram_interaction_expected_version, 0)
       OR BINARY NEW.request_hash <> BINARY COALESCE(@app_telegram_interaction_request_hash, '')
       OR BINARY NEW.to_payload_hash <> BINARY SHA2(CAST(NEW.to_payload AS CHAR), 256)
       OR matching_session_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction transition evidence authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_transitions_update_guard BEFORE UPDATE ON telegram_interaction_transitions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction transition history is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_transitions_delete_guard BEFORE DELETE ON telegram_interaction_transitions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction transition history is non-deletable.'; END");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_interaction_callbacks_insert_guard
BEFORE INSERT ON telegram_interaction_callbacks
FOR EACH ROW
BEGIN
    DECLARE matching_session_count INT DEFAULT 0;

    SELECT COUNT(*) INTO matching_session_count
    FROM telegram_interaction_sessions session_row
    WHERE session_row.id = NEW.telegram_interaction_session_id
      AND session_row.telegram_account_id = NEW.telegram_account_id
      AND session_row.active_telegram_account_id = NEW.telegram_account_id
      AND session_row.status = 'active'
      AND session_row.version = NEW.session_version
      AND NEW.expires_at <= session_row.expires_at;

    IF NOT EXISTS (
        SELECT 1 FROM telegram_interaction_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_interaction_capability, ''), 256)
    ) OR COALESCE(@app_telegram_interaction_authority, '') <> 'callback_issue_v1'
       OR NEW.telegram_account_id <> COALESCE(@app_telegram_interaction_account_id, 0)
       OR NEW.telegram_interaction_session_id <> COALESCE(@app_telegram_interaction_session_id, 0)
       OR NEW.session_version <> COALESCE(@app_telegram_interaction_expected_version, 0)
       OR BINARY NEW.issue_request_hash <> BINARY COALESCE(@app_telegram_interaction_request_hash, '')
       OR BINARY NEW.token_hash <> BINARY COALESCE(@app_telegram_interaction_callback_hash, '')
       OR BINARY NEW.action_payload_hash <> BINARY SHA2(CAST(NEW.action_payload AS CHAR), 256)
       OR NEW.state <> 'pending' OR NEW.accepted_update_id IS NOT NULL OR NEW.accepted_at IS NOT NULL OR NEW.completed_at IS NOT NULL
       OR matching_session_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction callback creation authority is invalid.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_interaction_callbacks_update_guard
BEFORE UPDATE ON telegram_interaction_callbacks
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM telegram_interaction_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_interaction_capability, ''), 256)
    ) OR NEW.id <> COALESCE(@app_telegram_interaction_callback_id, 0)
       OR NEW.telegram_account_id <> COALESCE(@app_telegram_interaction_account_id, 0)
       OR NEW.telegram_interaction_session_id <> COALESCE(@app_telegram_interaction_session_id, 0)
       OR NEW.session_version <> COALESCE(@app_telegram_interaction_expected_version, 0)
       OR BINARY NEW.issue_request_hash <> BINARY COALESCE(@app_telegram_interaction_request_hash, '')
       OR BINARY NEW.token_hash <> BINARY COALESCE(@app_telegram_interaction_callback_hash, '')
       OR OLD.id <> NEW.id OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR OLD.telegram_interaction_session_id <> NEW.telegram_interaction_session_id
       OR OLD.telegram_account_id <> NEW.telegram_account_id OR OLD.session_version <> NEW.session_version
       OR BINARY OLD.issue_request_hash <> BINARY NEW.issue_request_hash OR BINARY OLD.issue_command_hash <> BINARY NEW.issue_command_hash
       OR BINARY OLD.token_hash <> BINARY NEW.token_hash OR BINARY OLD.token_ciphertext <> BINARY NEW.token_ciphertext
       OR BINARY OLD.action <> BINARY NEW.action OR BINARY OLD.action_payload <> BINARY NEW.action_payload
       OR BINARY OLD.action_payload_hash <> BINARY NEW.action_payload_hash OR OLD.expires_at <> NEW.expires_at OR OLD.created_at <> NEW.created_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction callback update authority is invalid.';
    END IF;

    IF COALESCE(@app_telegram_interaction_authority, '') = 'callback_accept_v1' THEN
        IF OLD.state <> 'pending' OR NEW.state <> 'accepted'
           OR NEW.accepted_update_id <> COALESCE(@app_telegram_interaction_update_id, 0)
           OR NEW.accepted_at IS NULL OR NEW.completed_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram callback acceptance transition is invalid.';
        END IF;
    ELSEIF COALESCE(@app_telegram_interaction_authority, '') = 'callback_complete_v1' THEN
        IF OLD.state <> 'accepted' OR NEW.state <> 'completed'
           OR NOT (OLD.accepted_update_id <=> NEW.accepted_update_id)
           OR NOT (OLD.accepted_at <=> NEW.accepted_at)
           OR NEW.completed_at IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram callback completion transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction callback authority is unknown.';
    END IF;
END
SQL);

        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_interaction_callbacks_delete_guard BEFORE DELETE ON telegram_interaction_callbacks FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram interaction callbacks are non-deletable.'; END");
    }

    private function dropGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_accounts_interaction_identity_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_callbacks_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_callbacks_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_callbacks_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_transitions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_transitions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_transitions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_sessions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_sessions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_interaction_sessions_insert_guard');
    }

    private function capabilityHash(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Telegram interaction database capability key is unavailable.');
        }

        return hash('sha256', hash_hmac('sha256', 'telegram-interaction-database-authority-v1', $key));
    }
};
