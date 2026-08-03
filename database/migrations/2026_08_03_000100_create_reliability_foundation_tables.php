<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('scope', 64);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->string('state', 32)->default('reserved');
            $table->string('owner_reference', 191)->nullable();
            $table->json('result')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps(6);
            $table->unique(['scope', 'key_hash'], 'idempotency_scope_key_unique');
            $table->index(['state', 'expires_at']);
        });

        Schema::create('processed_telegram_updates', function (Blueprint $table): void {
            $table->string('bot_id', 64);
            $table->unsignedBigInteger('update_id');
            $table->char('payload_hash', 64);
            $table->string('state', 32)->default('accepted');
            $table->string('correlation_id', 64);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps(6);
            $table->primary(['bot_id', 'update_id'], 'telegram_bot_update_primary');
        });

        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 191)->unique();
            $table->string('event_type', 191);
            $table->string('aggregate_type', 128);
            $table->string('aggregate_id', 191);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('correlation_id', 64);
            $table->timestamp('available_at', 6);
            $table->timestamp('processed_at', 6)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_class', 191)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps(6);
            $table->index(['processed_at', 'available_at'], 'outbox_pending_index');
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('processed_telegram_updates');
        Schema::dropIfExists('idempotency_keys');
    }
};
