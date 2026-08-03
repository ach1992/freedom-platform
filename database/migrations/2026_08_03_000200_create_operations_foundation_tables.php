<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('actor_type', 64);
            $table->string('actor_id', 191)->nullable();
            $table->string('action', 191);
            $table->string('target_type', 128)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 64);
            $table->string('request_fingerprint', 128)->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['target_type', 'target_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('correlation_id');
        });

        Schema::create('scheduled_task_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('task_name', 191);
            $table->uuid('run_id')->unique();
            $table->string('state', 32);
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metrics')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps(6);
            $table->index(['task_name', 'started_at']);
        });

        Schema::create('worker_heartbeats', function (Blueprint $table): void {
            $table->string('worker_id', 191)->primary();
            $table->string('queue', 64);
            $table->string('host_hash', 64);
            $table->string('release_version', 64)->nullable();
            $table->timestamp('last_seen_at', 6);
            $table->timestamps(6);
            $table->index(['queue', 'last_seen_at']);
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('severity', 32);
            $table->string('event_name', 191);
            $table->char('deduplication_key', 64);
            $table->string('correlation_id', 64);
            $table->json('safe_context')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at', 6);
            $table->timestamp('last_seen_at', 6);
            $table->timestamp('acknowledged_at', 6)->nullable();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['event_name', 'deduplication_key'], 'alert_event_dedup_unique');
            $table->index(['severity', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('worker_heartbeats');
        Schema::dropIfExists('scheduled_task_runs');
        Schema::dropIfExists('audit_logs');
    }
};
