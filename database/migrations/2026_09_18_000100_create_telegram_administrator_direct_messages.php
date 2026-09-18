<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telegram_administrator_direct_messages')) {
            if (DB::table('telegram_administrator_direct_messages')->exists()) {
                throw new RuntimeException('Telegram administrator direct-message migration found a non-empty pre-existing table.');
            }
            Schema::drop('telegram_administrator_direct_messages');
        }

        Schema::create('telegram_administrator_direct_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id');
            $table->unique('public_id', 'tg_admin_direct_message_public_unique');
            $table->char('create_request_hash', 64);
            $table->unique('create_request_hash', 'tg_admin_direct_message_request_unique');
            $table->foreignId('actor_administrator_id')
                ->constrained('administrators', indexName: 'tg_admin_direct_message_actor_fk')
                ->restrictOnDelete();
            $table->string('bot_id', 20);
            $table->ulid('target_account_public_id');
            $table->string('target_telegram_user_id', 20);
            $table->string('content_type', 16);
            $table->longText('content_ciphertext');
            $table->char('content_integrity_hash', 64);
            $table->unsignedSmallInteger('content_length');
            $table->string('correlation_id', 64);
            $table->unique('correlation_id', 'tg_admin_direct_message_correlation_unique');
            $table->ulid('delivery_operation_public_id')->nullable();
            $table->unique('delivery_operation_public_id', 'tg_admin_direct_message_delivery_unique');
            $table->dateTime('expires_at', 6);
            $table->dateTime('queued_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['actor_administrator_id', 'created_at'], 'tg_admin_direct_message_actor_idx');
            $table->index(['target_account_public_id', 'created_at'], 'tg_admin_direct_message_target_idx');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE telegram_administrator_direct_messages
    ADD CONSTRAINT tg_admin_direct_message_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT tg_admin_direct_message_request_chk CHECK (create_request_hash REGEXP '^[0-9a-f]{64}$'),
    ADD CONSTRAINT tg_admin_direct_message_bot_chk CHECK (bot_id REGEXP '^[1-9][0-9]{5,19}$'),
    ADD CONSTRAINT tg_admin_direct_message_target_account_chk CHECK (
        target_account_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
        AND BINARY target_account_public_id = BINARY UPPER(target_account_public_id)
    ),
    ADD CONSTRAINT tg_admin_direct_message_target_telegram_chk CHECK (target_telegram_user_id REGEXP '^[1-9][0-9]{0,19}$'),
    ADD CONSTRAINT tg_admin_direct_message_type_chk CHECK (content_type = 'text'),
    ADD CONSTRAINT tg_admin_direct_message_cipher_chk CHECK (OCTET_LENGTH(content_ciphertext) BETWEEN 1 AND 65536),
    ADD CONSTRAINT tg_admin_direct_message_integrity_chk CHECK (content_integrity_hash REGEXP '^[0-9a-f]{64}$'),
    ADD CONSTRAINT tg_admin_direct_message_length_chk CHECK (content_length BETWEEN 1 AND 3500),
    ADD CONSTRAINT tg_admin_direct_message_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$'),
    ADD CONSTRAINT tg_admin_direct_message_delivery_chk CHECK (
        delivery_operation_public_id IS NULL
        OR (delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
            AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id))
    ),
    ADD CONSTRAINT tg_admin_direct_message_queue_shape_chk CHECK (
        (delivery_operation_public_id IS NULL AND queued_at IS NULL)
        OR (delivery_operation_public_id IS NOT NULL AND queued_at IS NOT NULL)
    ),
    ADD CONSTRAINT tg_admin_direct_message_time_chk CHECK (expires_at > created_at AND updated_at >= created_at)
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('telegram_administrator_direct_messages')) {
            return;
        }
        if (DB::table('telegram_administrator_direct_messages')->exists()) {
            throw new RuntimeException('Telegram administrator direct-message records must be retained; rollback requires an empty table.');
        }

        Schema::drop('telegram_administrator_direct_messages');
    }
};
