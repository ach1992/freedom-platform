<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-001 PAY-003 SEC-003 SEC-009 DAT-003 */
    public function up(): void
    {
        Schema::table('processed_telegram_updates', function (Blueprint $table): void {
            $table->longText('payload_ciphertext')->nullable()->after('payload_hash');
            $table->unsignedInteger('payload_size')->nullable()->after('payload_ciphertext');
            $table->dateTime('received_at', 6)->nullable()->after('correlation_id');
            $table->dateTime('queued_at', 6)->nullable()->after('received_at');
            $table->dateTime('processing_started_at', 6)->nullable()->after('queued_at');
            $table->dateTime('failed_at', 6)->nullable()->after('processed_at');
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('failed_at');
            $table->string('last_error_class', 191)->nullable()->after('attempt_count');
            $table->string('last_error_code', 64)->nullable()->after('last_error_class');
            $table->index(['state', 'received_at'], 'telegram_update_state_received_index');
        });

        Schema::create('telegram_start_attributions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('bot_id', 64);
            $table->char('payload_hash', 64);
            $table->text('payload_ciphertext');
            $table->unsignedBigInteger('first_update_id');
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['bot_id', 'payload_hash', 'user_id'], 'telegram_start_payload_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_start_attributions');

        Schema::table('processed_telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_update_state_received_index');
            $table->dropColumn([
                'payload_ciphertext',
                'payload_size',
                'received_at',
                'queued_at',
                'processing_started_at',
                'failed_at',
                'attempt_count',
                'last_error_class',
                'last_error_code',
            ]);
        });
    }
};
