<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement COM-002 COM-003 ARCH-003 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        foreach ([
            'broadcast_campaign_tests',
            'broadcast_recipient_messages',
            'broadcast_recipients',
            'broadcast_message_versions',
            'broadcast_audiences',
            'broadcast_campaigns',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                throw new RuntimeException('Broadcast campaign migration found non-empty pre-existing authority tables.');
            }

            Schema::drop($table);
        }

        Schema::create('broadcast_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->char('create_request_hash', 64)->unique();
            $table->foreignId('actor_administrator_id')
                ->constrained('administrators', indexName: 'broadcast_campaign_actor_fk')
                ->restrictOnDelete();
            $table->string('bot_id', 20);
            $table->string('state', 24)->default('draft');
            $table->unsignedBigInteger('state_version')->default(1);
            $table->unsignedInteger('current_message_version')->default(1);
            $table->unsignedInteger('current_audience_version')->default(1);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('correlation_id', 64)->unique();
            $table->dateTime('audience_materialized_at', 6)->nullable();
            $table->dateTime('scheduled_at', 6)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['state', 'scheduled_at'], 'broadcast_campaign_state_schedule_idx');
            $table->index(['actor_administrator_id', 'created_at'], 'broadcast_campaign_actor_created_idx');
        });

        Schema::create('broadcast_audiences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('broadcast_campaign_id')
                ->constrained('broadcast_campaigns', indexName: 'broadcast_audience_campaign_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('filter_snapshot');
            $table->char('snapshot_hash', 64);
            $table->unsignedInteger('estimated_recipient_count')->default(0);
            $table->dateTime('created_at', 6);
            $table->unique(['broadcast_campaign_id', 'version'], 'broadcast_audience_campaign_version_unique');
        });

        Schema::create('broadcast_message_versions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('broadcast_campaign_id')
                ->constrained('broadcast_campaigns', indexName: 'broadcast_message_campaign_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('mode', 24);
            $table->string('source_kind', 24)->nullable();
            $table->text('text')->nullable();
            $table->text('caption_override')->nullable();
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->json('inline_keyboard_snapshot')->nullable();
            $table->char('content_hash', 64);
            $table->foreignId('actor_administrator_id')
                ->constrained('administrators', indexName: 'broadcast_message_actor_fk')
                ->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->unique(['broadcast_campaign_id', 'version'], 'broadcast_message_campaign_version_unique');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('broadcast_campaign_id')
                ->constrained('broadcast_campaigns', indexName: 'broadcast_recipient_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'broadcast_recipient_user_fk')
                ->restrictOnDelete();
            $table->foreignId('telegram_account_id')
                ->constrained('telegram_accounts', indexName: 'broadcast_recipient_account_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('delivery_state', 24)->default('queued');
            $table->string('lifecycle_state', 24)->default('none');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->char('claim_token_hash', 64)->nullable();
            $table->dateTime('claim_expires_at', 6)->nullable();
            $table->ulid('delivery_operation_public_id')->nullable()->unique();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['broadcast_campaign_id', 'user_id'], 'broadcast_recipient_campaign_user_unique');
            $table->unique(['broadcast_campaign_id', 'telegram_account_id'], 'broadcast_recipient_campaign_account_unique');
            $table->index(['broadcast_campaign_id', 'delivery_state', 'id'], 'broadcast_recipient_campaign_state_idx');
            $table->index(['delivery_state', 'claim_expires_at'], 'broadcast_recipient_claim_idx');
        });

        Schema::create('broadcast_recipient_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('broadcast_recipient_id')
                ->constrained('broadcast_recipients', indexName: 'broadcast_recipient_message_recipient_fk')
                ->restrictOnDelete();
            $table->foreignId('broadcast_message_version_id')
                ->nullable()
                ->constrained('broadcast_message_versions', indexName: 'broadcast_recipient_message_version_fk')
                ->restrictOnDelete();
            $table->string('action', 24);
            $table->char('request_key_hash', 64)->unique();
            $table->string('state', 24)->default('prepared');
            $table->ulid('delivery_operation_public_id')->nullable()->unique();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('result_code', 128)->nullable();
            $table->dateTime('provider_boundary_started_at', 6)->nullable();
            $table->dateTime('provider_boundary_finished_at', 6)->nullable();
            $table->foreignId('requested_by_administrator_id')
                ->nullable()
                ->constrained('administrators', indexName: 'broadcast_recipient_message_actor_fk')
                ->restrictOnDelete();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['broadcast_recipient_id', 'action', 'created_at'], 'broadcast_recipient_message_action_idx');
            $table->index(['state', 'created_at'], 'broadcast_recipient_message_state_idx');
        });

        Schema::create('broadcast_campaign_tests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->foreignId('broadcast_campaign_id')
                ->constrained('broadcast_campaigns', indexName: 'broadcast_campaign_test_campaign_fk')
                ->restrictOnDelete();
            $table->foreignId('broadcast_message_version_id')
                ->constrained('broadcast_message_versions', indexName: 'broadcast_campaign_test_message_fk')
                ->restrictOnDelete();
            $table->foreignId('owner_administrator_id')
                ->constrained('administrators', indexName: 'broadcast_campaign_test_owner_fk')
                ->restrictOnDelete();
            $table->foreignId('telegram_account_id')
                ->constrained('telegram_accounts', indexName: 'broadcast_campaign_test_account_fk')
                ->restrictOnDelete();
            $table->char('request_key_hash', 64)->unique();
            $table->string('state', 24);
            $table->ulid('delivery_operation_public_id')->nullable()->unique();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('result_code', 128)->nullable();
            $table->dateTime('provider_boundary_started_at', 6)->nullable();
            $table->dateTime('provider_boundary_finished_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_campaigns
    ADD CONSTRAINT broadcast_campaign_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT broadcast_campaign_request_chk CHECK (create_request_hash REGEXP '^[0-9a-f]{64}$'),
    ADD CONSTRAINT broadcast_campaign_bot_chk CHECK (bot_id REGEXP '^[1-9][0-9]{5,19}$'),
    ADD CONSTRAINT broadcast_campaign_state_chk CHECK (state IN ('draft','scheduled','active','paused','completed','cancelled')),
    ADD CONSTRAINT broadcast_campaign_versions_chk CHECK (
        state_version >= 1 AND current_message_version >= 1 AND current_audience_version >= 1
    ),
    ADD CONSTRAINT broadcast_campaign_correlation_chk CHECK (correlation_id REGEXP '^[A-Za-z0-9_.:-]{8,64}$'),
    ADD CONSTRAINT broadcast_campaign_schedule_chk CHECK (
        (state = 'scheduled' AND scheduled_at IS NOT NULL)
        OR state <> 'scheduled'
    ),
    ADD CONSTRAINT broadcast_campaign_terminal_chk CHECK (
        (state = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL)
        OR (state = 'cancelled' AND cancelled_at IS NOT NULL AND completed_at IS NULL)
        OR (state NOT IN ('completed','cancelled') AND completed_at IS NULL AND cancelled_at IS NULL)
    ),
    ADD CONSTRAINT broadcast_campaign_time_chk CHECK (
        updated_at >= created_at
        AND (audience_materialized_at IS NULL OR audience_materialized_at >= created_at)
        AND (started_at IS NULL OR started_at >= created_at)
        AND (completed_at IS NULL OR completed_at >= created_at)
        AND (cancelled_at IS NULL OR cancelled_at >= created_at)
    )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_audiences
    ADD CONSTRAINT broadcast_audience_version_chk CHECK (version >= 1),
    ADD CONSTRAINT broadcast_audience_json_chk CHECK (
        JSON_VALID(filter_snapshot)
        AND JSON_TYPE(filter_snapshot) = 'OBJECT'
        AND OCTET_LENGTH(filter_snapshot) BETWEEN 2 AND 32768
    ),
    ADD CONSTRAINT broadcast_audience_hash_chk CHECK (snapshot_hash REGEXP '^[0-9a-f]{64}$')
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_message_versions
    ADD CONSTRAINT broadcast_message_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT broadcast_message_version_chk CHECK (version >= 1),
    ADD CONSTRAINT broadcast_message_mode_chk CHECK (mode IN ('new_text','copy','forward')),
    ADD CONSTRAINT broadcast_message_source_kind_chk CHECK (source_kind IS NULL OR source_kind IN ('text','photo','video','animation','audio','document')),
    ADD CONSTRAINT broadcast_message_shape_chk CHECK (
        (mode = 'new_text'
            AND source_kind IS NULL
            AND text IS NOT NULL
            AND CHAR_LENGTH(text) BETWEEN 1 AND 4096
            AND caption_override IS NULL
            AND source_chat_id IS NULL
            AND source_message_id IS NULL)
        OR
        (mode IN ('copy','forward')
            AND source_kind IS NOT NULL
            AND text IS NULL
            AND source_chat_id IS NOT NULL
            AND source_message_id IS NOT NULL)
    ),
    ADD CONSTRAINT broadcast_message_caption_chk CHECK (
        caption_override IS NULL
        OR (
            mode = 'copy'
            AND source_kind <> 'text'
            AND CHAR_LENGTH(caption_override) <= 1024
            AND OCTET_LENGTH(caption_override) <= 4096
        )
    ),
    ADD CONSTRAINT broadcast_message_keyboard_chk CHECK (
        inline_keyboard_snapshot IS NULL
        OR (
            JSON_VALID(inline_keyboard_snapshot)
            AND JSON_TYPE(inline_keyboard_snapshot) = 'OBJECT'
            AND OCTET_LENGTH(inline_keyboard_snapshot) <= 16384
        )
    ),
    ADD CONSTRAINT broadcast_message_forward_keyboard_chk CHECK (
        mode <> 'forward' OR inline_keyboard_snapshot IS NULL
    ),
    ADD CONSTRAINT broadcast_message_hash_chk CHECK (content_hash REGEXP '^[0-9a-f]{64}$')
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_recipients
    ADD CONSTRAINT broadcast_recipient_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT broadcast_recipient_delivery_state_chk CHECK (
        delivery_state IN ('queued','sending','sent','failed_transient','failed_permanent','skipped','uncertain')
    ),
    ADD CONSTRAINT broadcast_recipient_lifecycle_state_chk CHECK (
        lifecycle_state IN ('none','edited','buttons','pinned','unpinned','deleted')
    ),
    ADD CONSTRAINT broadcast_recipient_claim_chk CHECK (
        (claim_token_hash IS NULL AND claim_expires_at IS NULL)
        OR (claim_token_hash REGEXP '^[0-9a-f]{64}$' AND claim_expires_at IS NOT NULL)
    ),
    ADD CONSTRAINT broadcast_recipient_delivery_operation_chk CHECK (
        delivery_operation_public_id IS NULL
        OR (
            delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
            AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id)
        )
    ),
    ADD CONSTRAINT broadcast_recipient_result_chk CHECK (
        (delivery_state = 'sent' AND telegram_message_id IS NOT NULL AND sent_at IS NOT NULL AND failure_code IS NULL)
        OR (delivery_state IN ('failed_transient','failed_permanent','uncertain') AND failure_code IS NOT NULL AND sent_at IS NULL)
        OR (delivery_state IN ('queued','sending','skipped') AND sent_at IS NULL)
    )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_recipient_messages
    ADD CONSTRAINT broadcast_recipient_message_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT broadcast_recipient_message_action_chk CHECK (
        action IN ('send','retry','edit','buttons','pin','unpin','delete')
    ),
    ADD CONSTRAINT broadcast_recipient_message_request_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$'),
    ADD CONSTRAINT broadcast_recipient_message_state_chk CHECK (
        state IN ('prepared','queued','sending','succeeded','retryable','failed','uncertain','skipped')
    ),
    ADD CONSTRAINT broadcast_recipient_message_delivery_chk CHECK (
        delivery_operation_public_id IS NULL
        OR (
            delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
            AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id)
        )
    ),
    ADD CONSTRAINT broadcast_recipient_message_boundary_chk CHECK (
        (state = 'sending' AND provider_boundary_started_at IS NOT NULL AND provider_boundary_finished_at IS NULL)
        OR (state <> 'sending')
    )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE broadcast_campaign_tests
    ADD CONSTRAINT broadcast_campaign_test_public_chk CHECK (
        public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
    ),
    ADD CONSTRAINT broadcast_campaign_test_request_chk CHECK (request_key_hash REGEXP '^[0-9a-f]{64}$'),
    ADD CONSTRAINT broadcast_campaign_test_state_chk CHECK (
        state IN ('prepared','queued','sending','succeeded','failed','uncertain')
    ),
    ADD CONSTRAINT broadcast_campaign_test_delivery_chk CHECK (
        delivery_operation_public_id IS NULL
        OR (
            delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
            AND BINARY delivery_operation_public_id = BINARY UPPER(delivery_operation_public_id)
        )
    ),
    ADD CONSTRAINT broadcast_campaign_test_boundary_chk CHECK (
        (state = 'sending' AND provider_boundary_started_at IS NOT NULL AND provider_boundary_finished_at IS NULL)
        OR state <> 'sending'
    )
SQL);
    }

    public function down(): void
    {
        foreach ([
            'broadcast_campaign_tests',
            'broadcast_recipient_messages',
            'broadcast_recipients',
            'broadcast_message_versions',
            'broadcast_audiences',
            'broadcast_campaigns',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Broadcast campaign records must be retained; rollback requires empty authority tables.');
            }
        }

        Schema::dropIfExists('broadcast_campaign_tests');
        Schema::dropIfExists('broadcast_recipient_messages');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_message_versions');
        Schema::dropIfExists('broadcast_audiences');
        Schema::dropIfExists('broadcast_campaigns');
    }
};
