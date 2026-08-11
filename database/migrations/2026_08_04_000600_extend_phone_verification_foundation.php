<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-004 ONB-005 USR-001 SEC-003 DAT-003 INT-002 */
    public function up(): void
    {
        $hasDuplicateActiveUsers = DB::table('phone_numbers')
            ->whereNotNull('active_lookup_hash')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateActiveUsers) {
            throw new RuntimeException('Cannot add active phone ownership constraint while duplicate active users exist.');
        }

        Schema::table('phone_numbers', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_user_id')->nullable()->after('user_id');
            $table->string('verification_policy', 32)->nullable()->after('status');
            $table->unsignedInteger('verification_policy_version')->nullable()->after('verification_policy');
            $table->string('last_verification_method', 32)->nullable()->after('verification_policy_version');
            $table->unsignedBigInteger('verified_via_telegram_account_id')->nullable()->after('last_verification_method');
            $table->unique('active_user_id', 'phone_active_user_unique');
            $table->foreign('active_user_id', 'phone_active_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('verified_via_telegram_account_id', 'phone_verified_telegram_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->restrictOnDelete();
            $table->index(['verification_policy', 'status'], 'phone_policy_status_index');
        });

        DB::table('phone_numbers')
            ->whereNotNull('active_lookup_hash')
            ->update(['active_user_id' => DB::raw('user_id')]);

        Schema::create('phone_verification_evidences', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('phone_number_id')->constrained('phone_numbers')->restrictOnDelete();
            $table->string('method', 32);
            $table->unsignedInteger('policy_version');
            $table->dateTime('verified_at', 6);
            $table->dateTime('invalidated_at', 6)->nullable();
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->foreign('telegram_account_id', 'phone_evidence_telegram_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['phone_number_id', 'method'], 'phone_evidence_method_unique');
            $table->index(['method', 'invalidated_at'], 'phone_evidence_active_method_index');
        });

        Schema::create('phone_verification_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('phone_number_id')->constrained('phone_numbers')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('method', 32)->nullable();
            $table->unsignedInteger('policy_version');
            $table->unsignedBigInteger('telegram_account_id')->nullable();
            $table->foreign('telegram_account_id', 'phone_event_telegram_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->restrictOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['user_id', 'created_at'], 'phone_event_user_time_index');
            $table->index(['phone_number_id', 'created_at'], 'phone_event_phone_time_index');
        });

        Schema::create('sms_delivery_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->ulid('otp_challenge_id')->nullable();
            $table->foreign('otp_challenge_id', 'sms_attempt_challenge_fk')
                ->references('id')
                ->on('otp_challenges')
                ->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->char('destination_lookup_hash', 64);
            $table->char('idempotency_key_hash', 64);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('outcome', 32);
            $table->longText('provider_message_id_ciphertext')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['idempotency_key_hash', 'attempt_number'], 'sms_attempt_idempotency_sequence_unique');
            $table->index(['provider_code', 'outcome', 'created_at'], 'sms_attempt_provider_outcome_index');
            $table->index(['otp_challenge_id', 'created_at'], 'sms_attempt_challenge_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_delivery_attempts');
        Schema::dropIfExists('phone_verification_events');
        Schema::dropIfExists('phone_verification_evidences');

        Schema::table('phone_numbers', function (Blueprint $table): void {
            $table->dropForeign('phone_verified_telegram_fk');
            $table->dropForeign('phone_active_user_fk');
            $table->dropIndex('phone_policy_status_index');
            $table->dropUnique('phone_active_user_unique');
            $table->dropColumn([
                'active_user_id',
                'verification_policy',
                'verification_policy_version',
                'last_verification_method',
                'verified_via_telegram_account_id',
            ]);
        });
    }
};
