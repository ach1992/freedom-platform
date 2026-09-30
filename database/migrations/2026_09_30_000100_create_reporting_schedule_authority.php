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
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('public_id', 26)->unique();
            $table->unsignedBigInteger('created_by_administrator_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('period', 32);
            $table->string('frequency', 16);
            $table->char('run_time_local', 5);
            $table->unsignedTinyInteger('weekday_iso')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->boolean('enabled')->default(true);
            $table->dateTime('next_run_at', 6);
            $table->char('lease_token_hash', 64)->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->dateTime('retry_not_before_at', 6)->nullable();
            $table->dateTime('last_due_at', 6)->nullable();
            $table->dateTime('last_queued_at', 6)->nullable();
            $table->char('last_delivery_operation_public_id', 26)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamps(6);

            $table->foreign('created_by_administrator_id', 'report_schedule_admin_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->foreign('actor_user_id', 'report_schedule_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['enabled', 'next_run_at', 'retry_not_before_at'], 'report_schedule_due_idx');
            $table->index(['created_by_administrator_id', 'enabled'], 'report_schedule_admin_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE report_schedules
ADD CONSTRAINT report_schedule_public_id_chk CHECK (
    public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY public_id = BINARY UPPER(public_id)
),
ADD CONSTRAINT report_schedule_period_chk CHECK (
    period IN ('today','yesterday','last_7_days','last_30_days','current_week','current_month','persian_month','previous_persian_month','last_3_months','last_6_months','last_year','all_time')
),
ADD CONSTRAINT report_schedule_frequency_chk CHECK (frequency IN ('daily','weekly','monthly')),
ADD CONSTRAINT report_schedule_time_chk CHECK (run_time_local REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
ADD CONSTRAINT report_schedule_shape_chk CHECK (
    (frequency = 'daily' AND weekday_iso IS NULL AND day_of_month IS NULL)
 OR (frequency = 'weekly' AND weekday_iso BETWEEN 1 AND 7 AND day_of_month IS NULL)
 OR (frequency = 'monthly' AND weekday_iso IS NULL AND day_of_month BETWEEN 1 AND 28)
),
ADD CONSTRAINT report_schedule_lease_chk CHECK (
    (lease_token_hash IS NULL AND lease_expires_at IS NULL)
 OR (lease_token_hash REGEXP '^[0-9a-f]{64}$' AND lease_expires_at IS NOT NULL)
),
ADD CONSTRAINT report_schedule_retry_chk CHECK (retry_count BETWEEN 0 AND 3),
ADD CONSTRAINT report_schedule_retry_time_chk CHECK (
    (retry_count = 0 AND retry_not_before_at IS NULL)
 OR (retry_count BETWEEN 1 AND 3 AND retry_not_before_at IS NOT NULL)
),
ADD CONSTRAINT report_schedule_last_operation_chk CHECK (
    last_delivery_operation_public_id IS NULL
 OR (last_delivery_operation_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$' AND BINARY last_delivery_operation_public_id = BINARY UPPER(last_delivery_operation_public_id))
)
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
