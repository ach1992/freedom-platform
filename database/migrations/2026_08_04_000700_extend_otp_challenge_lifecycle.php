<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-004 ONB-005 SEC-003 DAT-003 INT-002 */
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->foreignId('phone_number_id')->nullable()->after('user_id')
                ->constrained('phone_numbers')->restrictOnDelete();
            $table->unsignedBigInteger('telegram_account_id')->nullable()->after('phone_number_id');
            $table->foreign('telegram_account_id', 'otp_telegram_account_fk')
                ->references('id')
                ->on('telegram_accounts')
                ->restrictOnDelete();
            $table->char('request_ip_hash', 64)->nullable()->after('destination_lookup_hash');
            $table->char('request_idempotency_hash', 64)->nullable()
                ->unique('otp_request_idempotency_unique');
            $table->char('active_scope_hash', 64)->nullable()
                ->unique('otp_active_scope_unique');
            $table->unsignedSmallInteger('code_key_version')->nullable()->after('code_hash');
            $table->string('verification_policy', 32)->nullable()->after('maximum_attempts');
            $table->unsignedInteger('verification_policy_version')->nullable()
                ->after('verification_policy');
            $table->string('provider_code', 64)->nullable()->after('verification_policy_version');
            $table->string('delivery_status', 32)->nullable()->after('provider_code');
            $table->dateTime('issued_at', 6)->nullable()->after('delivery_status');
            $table->dateTime('resend_available_at', 6)->nullable()->after('issued_at');
            $table->index(['phone_number_id', 'purpose', 'created_at'], 'otp_phone_purpose_time_index');
            $table->index(['telegram_account_id', 'created_at'], 'otp_telegram_time_index');
            $table->index(['provider_code', 'delivery_status', 'created_at'], 'otp_provider_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropForeign(['phone_number_id']);
            $table->dropForeign('otp_telegram_account_fk');
            $table->dropIndex('otp_phone_purpose_time_index');
            $table->dropIndex('otp_telegram_time_index');
            $table->dropIndex('otp_provider_delivery_index');
            $table->dropUnique('otp_request_idempotency_unique');
            $table->dropUnique('otp_active_scope_unique');
            $table->dropColumn([
                'phone_number_id',
                'telegram_account_id',
                'request_ip_hash',
                'request_idempotency_hash',
                'active_scope_hash',
                'code_key_version',
                'verification_policy',
                'verification_policy_version',
                'provider_code',
                'delivery_status',
                'issued_at',
                'resend_available_at',
            ]);
        });
    }
};
