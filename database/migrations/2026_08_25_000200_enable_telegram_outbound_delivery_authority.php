<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 OPS-003 QUA-004 QUA-007 QUA-010 */
    public function up(): void
    {
        if (! Schema::hasTable('outbox_messages')) {
            throw new RuntimeException('Telegram outbound delivery authority requires the common Transactional Outbox.');
        }

        if (! Schema::hasTable('telegram_delivery_operations')) {
            if (DB::connection()->getDriverName() === 'mysql') {
                $this->createTable();
            } else {
                $this->createPortableTable();
            }
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->ensureCapability();
            $this->createOutboxInsertGuard();
            $this->createOperationInsertGuard();
            $this->createOperationUpdateGuard();
            $this->createOperationDeleteGuard();
            $this->createOutboxUpdateGuard();
            $this->createOutboxDeleteGuard();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_delivery_operations') && DB::table('telegram_delivery_operations')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram outbound delivery authority while operation evidence exists.');
        }
        if (DB::table('outbox_messages')->where('event_type', 'telegram.delivery.requested')->exists()) {
            throw new RuntimeException('Cannot roll back Telegram outbound delivery authority while Outbox commands exist.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_operations_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS outbox_telegram_delivery_envelope_insert_guard');
        Schema::dropIfExists('telegram_delivery_operations');

        if (Schema::hasTable('telegram_delivery_authority_capability')) {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_insert_guard');
            Schema::dropIfExists('telegram_delivery_authority_capability');
        }
    }

    private function ensureCapability(): void
    {
        $expectedHash = $this->capabilityHash();
        if (! Schema::hasTable('telegram_delivery_authority_capability')) {
            $this->createCapabilityTable($expectedHash);
        } else {
            $rows = DB::table('telegram_delivery_authority_capability')->get(['id', 'capability_hash']);
            if ($rows->isEmpty()) {
                if (DB::table('telegram_delivery_operations')->exists()
                    || DB::table('outbox_messages')->where('event_type', 'telegram.delivery.requested')->exists()) {
                    throw new RuntimeException('Telegram delivery database capability is missing while durable delivery authority exists.');
                }

                DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_delete_guard');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_update_guard');
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_delivery_capability_insert_guard');
                DB::table('telegram_delivery_authority_capability')->insert([
                    'id' => 1,
                    'capability_hash' => $expectedHash,
                    'created_at' => now('UTC'),
                ]);
            } elseif ($rows->count() !== 1
                || (int) $rows->first()->id !== 1
                || ! is_string($rows->first()->capability_hash)
                || ! hash_equals($expectedHash, $rows->first()->capability_hash)) {
                throw new RuntimeException('Telegram delivery database capability does not match the application key.');
            }
        }

        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_delivery_capability_insert_guard BEFORE INSERT ON telegram_delivery_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_delivery_capability_update_guard BEFORE UPDATE ON telegram_delivery_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.'; END");
        DB::unprepared("CREATE OR REPLACE TRIGGER telegram_delivery_capability_delete_guard BEFORE DELETE ON telegram_delivery_authority_capability FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery database capability is immutable.'; END");
    }

    private function createCapabilityTable(string $expectedHash): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_authority_capability (
  `id` TINYINT UNSIGNED NOT NULL,
  `capability_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `telegram_delivery_capability_singleton_chk` CHECK (`id` = 1),
  CONSTRAINT `telegram_delivery_capability_hash_chk` CHECK (`capability_hash` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        DB::table('telegram_delivery_authority_capability')->insert([
            'id' => 1,
            'capability_hash' => $expectedHash,
            'created_at' => now('UTC'),
        ]);
    }

    private function createPortableTable(): void
    {
        Schema::create('telegram_delivery_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->char('request_key_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('correlation_id', 64);
            $table->string('action', 16);
            $table->string('bot_id', 20);
            $table->bigInteger('recipient_chat_id');
            $table->unsignedBigInteger('target_message_id')->nullable();
            $table->text('presentation_text')->nullable();
            $table->uuid('outbox_event_id')->unique();
            $table->string('state', 32);
            $table->unsignedInteger('state_version');
            $table->unsignedSmallInteger('provider_attempts')->default(0);
            $table->dateTime('provider_boundary_started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'updated_at'], 'telegram_delivery_operations_state_idx');
        });
    }

    private function createTable(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE telegram_delivery_operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) NOT NULL,
    request_key_hash CHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    correlation_id VARCHAR(64) NOT NULL,
    action VARCHAR(16) NOT NULL,
    bot_id VARCHAR(20) NOT NULL,
    recipient_chat_id BIGINT NOT NULL,
    target_message_id BIGINT UNSIGNED NULL,
    presentation_text TEXT NULL,
    outbox_event_id CHAR(36) NOT NULL,
    state VARCHAR(32) NOT NULL,
    state_version INT UNSIGNED NOT NULL,
    provider_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    provider_boundary_started_at DATETIME(6) NULL,
    completed_at DATETIME(6) NULL,
    telegram_message_id BIGINT UNSIGNED NULL,
    result_code VARCHAR(64) NULL,
    retry_after_seconds INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY telegram_delivery_operations_public_unique (public_id),
    UNIQUE KEY telegram_delivery_operations_request_unique (request_key_hash),
    UNIQUE KEY telegram_delivery_operations_outbox_unique (outbox_event_id),
    KEY telegram_delivery_operations_state_idx (state, updated_at),
    CONSTRAINT telegram_delivery_operations_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    CONSTRAINT telegram_delivery_operations_request_hash_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT telegram_delivery_operations_fingerprint_chk CHECK (request_fingerprint REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT telegram_delivery_operations_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$'),
    CONSTRAINT telegram_delivery_operations_action_chk CHECK (action IN ('send','edit','delete')),
    CONSTRAINT telegram_delivery_operations_bot_chk CHECK (bot_id REGEXP '^[1-9][0-9]{5,19}$'),
    CONSTRAINT telegram_delivery_operations_recipient_chk CHECK (recipient_chat_id <> 0),
    CONSTRAINT telegram_delivery_operations_request_shape_chk CHECK (
        (action = 'send' AND target_message_id IS NULL AND presentation_text IS NOT NULL AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)
        OR (action = 'edit' AND target_message_id IS NOT NULL AND target_message_id > 0 AND presentation_text IS NOT NULL AND CHAR_LENGTH(presentation_text) BETWEEN 1 AND 4096)
        OR (action = 'delete' AND target_message_id IS NOT NULL AND target_message_id > 0 AND presentation_text IS NULL)
    ),
    CONSTRAINT telegram_delivery_operations_state_chk CHECK (
        state IN ('prepared','sending','retryable','succeeded','failed_final','uncertain','review_required')
    ),
    CONSTRAINT telegram_delivery_operations_state_version_chk CHECK (state_version >= 1),
    CONSTRAINT telegram_delivery_operations_attempts_chk CHECK (provider_attempts <= 100),
    CONSTRAINT telegram_delivery_operations_result_shape_chk CHECK (
        (state = 'prepared'
            AND provider_attempts = 0
            AND provider_boundary_started_at IS NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'sending'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'retryable'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'succeeded'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL
            AND ((action = 'delete' AND telegram_message_id IS NULL)
                 OR (action IN ('send','edit') AND telegram_message_id IS NOT NULL AND telegram_message_id > 0)))
        OR (state IN ('failed_final','uncertain')
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds IS NULL)
        OR (state = 'review_required'
            AND provider_attempts >= 1
            AND provider_boundary_started_at IS NOT NULL
            AND completed_at IS NOT NULL
            AND telegram_message_id IS NULL
            AND result_code IS NOT NULL
            AND retry_after_seconds BETWEEN 1 AND 86400)
    )
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin
SQL);
    }

    private function createOutboxInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_insert_guard
BEFORE INSERT ON outbox_messages
FOR EACH ROW
BEGIN
    IF LOWER(NEW.event_type) = 'telegram.delivery.requested' THEN
    IF NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
           OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
           OR BINARY NEW.id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
           OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_telegram_delivery_correlation_id, '')
           OR HEX(NEW.event_type) <> HEX('telegram.delivery.requested')
           OR COALESCE(JSON_TYPE(NEW.payload), '') <> 'OBJECT'
           OR COALESCE(JSON_LENGTH(NEW.payload), -1) <> 1
           OR COALESCE(JSON_TYPE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '') <> 'STRING'
           OR COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '') NOT REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
           OR HEX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), ''))
                <> HEX(UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')), '')))
           OR HEX(CAST(NEW.payload AS CHAR)) <> HEX(CONCAT(
                '{"telegram_delivery_operation_public_id":"',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')),
                '"}'
           ))
           OR HEX(NEW.payload_hash) <> HEX(LOWER(SHA2(CAST(NEW.payload AS CHAR), 256)))
           OR HEX(NEW.event_key) <> HEX(CONCAT(
                'telegram-delivery-requested:',
                JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id'))
           ))
           OR HEX(NEW.aggregate_type) <> HEX('telegram_delivery_operation')
           OR HEX(NEW.aggregate_id) <> HEX(JSON_UNQUOTE(JSON_EXTRACT(NEW.payload, '$.telegram_delivery_operation_public_id')))
           OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command must use the exact canonical safe envelope.';
        END IF;

        SET NEW.dispatch_state = 'authority_pending';
        SET NEW.lease_token = NULL;
        SET NEW.leased_until = NULL;
        SET NEW.processed_at = NULL;
        SET NEW.attempts = 0;
        SET NEW.review_reason = NULL;
        SET NEW.last_error_class = NULL;
        SET NEW.last_error_code = NULL;
    END IF;
END
SQL);
    }

    private function createOperationInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_insert_guard
BEFORE INSERT ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    DECLARE valid_outbox_count INT DEFAULT 0;
    DECLARE expected_presentation_hash CHAR(64) DEFAULT NULL;

    SET expected_presentation_hash = CASE
        WHEN NEW.presentation_text IS NULL THEN NULL
        ELSE LOWER(SHA2(NEW.presentation_text, 256))
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
       OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
       OR BINARY NEW.public_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '')
       OR BINARY NEW.request_key_hash <> BINARY COALESCE(@app_telegram_delivery_request_hash, '')
       OR BINARY NEW.request_fingerprint <> BINARY COALESCE(@app_telegram_delivery_fingerprint, '')
       OR BINARY NEW.correlation_id <> BINARY COALESCE(@app_telegram_delivery_correlation_id, '')
       OR BINARY NEW.action <> BINARY COALESCE(@app_telegram_delivery_action, '')
       OR BINARY NEW.bot_id <> BINARY COALESCE(@app_telegram_delivery_bot_id, '')
       OR NEW.recipient_chat_id <> COALESCE(@app_telegram_delivery_recipient_chat_id, 0)
       OR NOT (NEW.target_message_id <=> @app_telegram_delivery_target_message_id)
       OR NOT (expected_presentation_hash <=> @app_telegram_delivery_presentation_hash)
       OR BINARY NEW.outbox_event_id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
       OR BINARY NEW.state <> BINARY 'prepared'
       OR NEW.state_version <> 1
       OR NEW.provider_attempts <> 0
       OR NEW.provider_boundary_started_at IS NOT NULL
       OR NEW.completed_at IS NOT NULL
       OR NEW.telegram_message_id IS NOT NULL
       OR NEW.result_code IS NOT NULL
       OR NEW.retry_after_seconds IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation creation authority is invalid.';
    END IF;

    SELECT COUNT(*) INTO valid_outbox_count
    FROM outbox_messages outbox_row
    WHERE BINARY outbox_row.id = BINARY NEW.outbox_event_id
      AND BINARY outbox_row.event_type = BINARY 'telegram.delivery.requested'
      AND BINARY outbox_row.event_key = BINARY CONCAT('telegram-delivery-requested:', NEW.public_id)
      AND BINARY outbox_row.aggregate_type = BINARY 'telegram_delivery_operation'
      AND BINARY outbox_row.aggregate_id = BINARY NEW.public_id
      AND BINARY outbox_row.correlation_id = BINARY NEW.correlation_id
      AND outbox_row.dispatch_state = 'authority_pending'
      AND outbox_row.processed_at IS NULL
      AND outbox_row.lease_token IS NULL
      AND outbox_row.leased_until IS NULL
      AND outbox_row.attempts = 0
      AND outbox_row.review_reason IS NULL
      AND outbox_row.last_error_class IS NULL
      AND outbox_row.last_error_code IS NULL
      AND COALESCE(JSON_TYPE(outbox_row.payload), '') = 'OBJECT'
      AND COALESCE(JSON_LENGTH(outbox_row.payload), -1) = 1
      AND BINARY COALESCE(JSON_UNQUOTE(JSON_EXTRACT(outbox_row.payload, '$.telegram_delivery_operation_public_id')), '') = BINARY NEW.public_id
      AND HEX(CAST(outbox_row.payload AS CHAR)) = HEX(CONCAT('{"telegram_delivery_operation_public_id":"', NEW.public_id, '"}'))
      AND HEX(outbox_row.payload_hash) = HEX(LOWER(SHA2(CAST(outbox_row.payload AS CHAR), 256)));

    IF valid_outbox_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation requires one exact quarantined Outbox command.';
    END IF;
END
SQL);
    }

    private function createOperationUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_update_guard
BEFORE UPDATE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM telegram_delivery_authority_capability capability_row
        WHERE capability_row.id = 1
          AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
    )
       OR COALESCE(@app_telegram_delivery_effect_authority, '') <> 'telegram_delivery_effect_v1'
       OR BINARY OLD.public_id <> BINARY COALESCE(@app_telegram_delivery_effect_public_id, '')
       OR OLD.state_version <> COALESCE(@app_telegram_delivery_effect_expected_version, 0)
       OR NEW.state_version <> OLD.state_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation transition authority is invalid.';
    END IF;

    IF OLD.id <> NEW.id
       OR BINARY OLD.public_id <> BINARY NEW.public_id
       OR BINARY OLD.request_key_hash <> BINARY NEW.request_key_hash
       OR BINARY OLD.request_fingerprint <> BINARY NEW.request_fingerprint
       OR BINARY OLD.correlation_id <> BINARY NEW.correlation_id
       OR BINARY OLD.action <> BINARY NEW.action
       OR BINARY OLD.bot_id <> BINARY NEW.bot_id
       OR OLD.recipient_chat_id <> NEW.recipient_chat_id
       OR NOT (OLD.target_message_id <=> NEW.target_message_id)
       OR NOT (OLD.presentation_text <=> NEW.presentation_text)
       OR BINARY OLD.outbox_event_id <> BINARY NEW.outbox_event_id
       OR NOT (OLD.created_at <=> NEW.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation immutable identity cannot be retargeted.';
    END IF;

    IF OLD.state IN ('prepared','retryable') AND NEW.state = 'sending' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts + 1
           OR NEW.provider_boundary_started_at IS NULL
           OR NEW.completed_at IS NOT NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NOT NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery provider boundary transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'retryable' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NOT NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery retryable result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'succeeded' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL
           OR (NEW.action = 'delete' AND NEW.telegram_message_id IS NOT NULL)
           OR (NEW.action IN ('send','edit') AND (NEW.telegram_message_id IS NULL OR NEW.telegram_message_id < 1))
           OR (NEW.action = 'edit' AND NEW.telegram_message_id <> NEW.target_message_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery success result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state IN ('failed_final','uncertain') THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery terminal result transition is invalid.';
        END IF;
    ELSEIF OLD.state = 'sending' AND NEW.state = 'review_required' THEN
        IF NEW.provider_attempts <> OLD.provider_attempts
           OR NOT (NEW.provider_boundary_started_at <=> OLD.provider_boundary_started_at)
           OR NEW.completed_at IS NULL
           OR NEW.telegram_message_id IS NOT NULL
           OR NEW.result_code IS NULL
           OR NEW.retry_after_seconds IS NULL
           OR NEW.retry_after_seconds < 1
           OR NEW.retry_after_seconds > 86400 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery review-required result transition is invalid.';
        END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation state transition is not allowed.';
    END IF;
END
SQL);
    }

    private function createOperationDeleteGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER telegram_delivery_operations_delete_guard
BEFORE DELETE ON telegram_delivery_operations
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery operation evidence is non-deletable.';
END
SQL);
    }

    private function createOutboxUpdateGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW
BEGIN
    DECLARE final_authority_count INT DEFAULT 0;

    IF HEX(OLD.event_type) = HEX('telegram.delivery.requested') THEN
        IF HEX(OLD.id) <> HEX(NEW.id)
           OR HEX(OLD.event_key) <> HEX(NEW.event_key)
           OR HEX(OLD.event_type) <> HEX(NEW.event_type)
           OR HEX(OLD.aggregate_type) <> HEX(NEW.aggregate_type)
           OR HEX(OLD.aggregate_id) <> HEX(NEW.aggregate_id)
           OR HEX(CAST(OLD.payload AS CHAR)) <> HEX(CAST(NEW.payload AS CHAR))
           OR HEX(OLD.payload_hash) <> HEX(NEW.payload_hash)
           OR HEX(OLD.correlation_id) <> HEX(NEW.correlation_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command identity is immutable.';
        END IF;

        IF HEX(NEW.dispatch_state) NOT IN (
            HEX('authority_pending'), HEX('pending'), HEX('leased'), HEX('retry'), HEX('processed'), HEX('review_required')
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox command must use an exact dispatch lifecycle state.';
        END IF;

        IF OLD.dispatch_state = 'authority_pending' AND NEW.dispatch_state <> 'authority_pending' THEN
            IF NOT EXISTS (
                SELECT 1
                FROM telegram_delivery_authority_capability capability_row
                WHERE capability_row.id = 1
                  AND BINARY capability_row.capability_hash = BINARY SHA2(COALESCE(@app_telegram_delivery_capability, ''), 256)
            )
               OR COALESCE(@app_telegram_delivery_authority, '') <> 'telegram_delivery_queue_v1'
               OR BINARY NEW.id <> BINARY COALESCE(@app_telegram_delivery_outbox_event_id, '')
               OR BINARY NEW.aggregate_id <> BINARY COALESCE(@app_telegram_delivery_public_id, '')
               OR HEX(NEW.dispatch_state) <> HEX('pending')
               OR NEW.processed_at IS NOT NULL
               OR NEW.lease_token IS NOT NULL
               OR NEW.leased_until IS NOT NULL
               OR NEW.attempts <> 0
               OR NEW.review_reason IS NOT NULL
               OR NEW.last_error_class IS NOT NULL
               OR NEW.last_error_code IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox authority can only release into a clean pending state.';
            END IF;

            SELECT COUNT(*) INTO final_authority_count
            FROM telegram_delivery_operations operation_row
            WHERE BINARY operation_row.outbox_event_id = BINARY NEW.id
              AND BINARY operation_row.public_id = BINARY NEW.aggregate_id
              AND BINARY operation_row.correlation_id = BINARY NEW.correlation_id
              AND BINARY operation_row.request_key_hash = BINARY COALESCE(@app_telegram_delivery_request_hash, '')
              AND BINARY operation_row.request_fingerprint = BINARY COALESCE(@app_telegram_delivery_fingerprint, '')
              AND BINARY operation_row.action = BINARY COALESCE(@app_telegram_delivery_action, '')
              AND BINARY operation_row.bot_id = BINARY COALESCE(@app_telegram_delivery_bot_id, '')
              AND operation_row.recipient_chat_id = COALESCE(@app_telegram_delivery_recipient_chat_id, 0)
              AND (operation_row.target_message_id <=> @app_telegram_delivery_target_message_id)
              AND operation_row.state = 'prepared'
              AND operation_row.state_version = 1
              AND operation_row.provider_attempts = 0;

            IF final_authority_count <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox dispatch requires the exact final operation authority.';
            END IF;
        ELSEIF OLD.dispatch_state <> 'authority_pending' AND NEW.dispatch_state = 'authority_pending' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox lifecycle cannot return to authority_pending.';
        END IF;
    ELSEIF LOWER(NEW.event_type) = 'telegram.delivery.requested' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Existing Outbox rows cannot be converted into Telegram delivery commands.';
    END IF;
END
SQL);
    }

    private function createOutboxDeleteGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER outbox_telegram_delivery_envelope_delete_guard
BEFORE DELETE ON outbox_messages
FOR EACH ROW
BEGIN
    IF HEX(OLD.event_type) = HEX('telegram.delivery.requested') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telegram delivery Outbox commands are non-deletable.';
    END IF;
END
SQL);
    }

    private function capabilityHash(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Telegram delivery database capability key is unavailable.');
        }

        return hash('sha256', hash_hmac('sha256', 'telegram-delivery-database-authority-v1', $key));
    }
};
