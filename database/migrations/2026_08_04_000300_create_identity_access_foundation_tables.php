<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-001 ONB-004 ONB-005 USR-001 USR-002 USR-003 AGT-001 AGT-002 ACL-001 ACL-002 ACL-003 SEC-002 SEC-003 */
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('bot_id');
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('username', 64)->nullable();
            $table->string('language_code', 16)->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('first_seen_at', 6);
            $table->timestamp('last_seen_at', 6);
            $table->timestamps(6);
            $table->unique(['bot_id', 'telegram_user_id'], 'telegram_account_identity_unique');
            $table->unique(['bot_id', 'user_id'], 'telegram_account_user_bot_unique');
            $table->index(['username', 'bot_id']);
        });

        Schema::create('customer_tiers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 32)->unique();
            $table->string('name_translation_key', 191);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('policy')->nullable();
            $table->timestamps(6);
            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('current_tier_id')->nullable()->constrained('customer_tiers')->restrictOnDelete();
            $table->boolean('tier_locked')->default(false);
            $table->string('tier_lock_reason_code', 64)->nullable();
            $table->string('phone_verification_status', 32)->default('unverified');
            $table->string('identity_verification_status', 32)->default('unverified');
            $table->timestamps(6);
            $table->index(['current_tier_id', 'tier_locked']);
            $table->index(['phone_verification_status', 'identity_verification_status'], 'customer_verification_status_index');
        });

        Schema::create('administrators', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->boolean('is_owner')->default(false);
            $table->unsignedBigInteger('permission_version')->default(1);
            $table->timestamp('last_authenticated_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['status', 'is_owner']);
        });

        Schema::create('customer_status_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('customer_tier_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_tier_id')->nullable()->constrained('customer_tiers')->restrictOnDelete();
            $table->foreignId('to_tier_id')->constrained('customer_tiers')->restrictOnDelete();
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('customer_tags', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name_translation_key', 191);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });

        Schema::create('customer_tag_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('tag_id')->constrained('customer_tags')->restrictOnDelete();
            $table->foreignId('assigned_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->timestamp('assigned_at', 6);
            $table->timestamp('removed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'tag_id'], 'customer_tag_assignment_unique');
            $table->index(['tag_id', 'removed_at']);
        });

        Schema::create('phone_numbers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->longText('encrypted_value');
            $table->char('lookup_hash', 64);
            $table->char('active_lookup_hash', 64)->nullable()->unique();
            $table->unsignedSmallInteger('hash_key_version');
            $table->string('status', 32)->default('unverified');
            $table->timestamp('verified_at', 6)->nullable();
            $table->timestamp('released_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'lookup_hash'], 'phone_user_lookup_unique');
            $table->index(['user_id', 'status']);
        });

        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 64);
            $table->string('channel', 32);
            $table->char('destination_lookup_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('maximum_attempts');
            $table->timestamp('expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('invalidated_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['destination_lookup_hash', 'purpose', 'expires_at'], 'otp_destination_purpose_expiry_index');
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name_translation_key', 191);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 128)->unique();
            $table->string('module', 64);
            $table->string('risk_level', 32)->default('standard');
            $table->boolean('requires_approval')->default(false);
            $table->timestamps(6);
            $table->index(['module', 'risk_level']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->timestamps(6);
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('administrator_role_assignments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->timestamp('granted_at', 6);
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['administrator_id', 'role_id'], 'administrator_role_unique');
            $table->index(['role_id', 'revoked_at']);
        });

        Schema::create('administrator_permission_overrides', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->string('effect', 16)->default('inherit');
            $table->foreignId('changed_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps(6);
            $table->unique(['administrator_id', 'permission_id'], 'administrator_permission_override_unique');
            $table->index(['permission_id', 'effect']);
        });

        Schema::create('sensitive_action_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('requested_by_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->foreignId('decided_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('action', 128);
            $table->string('target_type', 128)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->char('request_fingerprint', 64)->unique();
            $table->string('state', 32)->default('pending');
            $table->string('decision_reason_code', 64)->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('decided_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'expires_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('agent_applications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('active_customer_id')->nullable()->unique();
            $table->foreign('active_customer_id')->references('id')->on('users')->restrictOnDelete();
            $table->string('state', 32)->default('submitted');
            $table->foreignId('claimed_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->foreignId('decided_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('decision_reason_code', 64)->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedInteger('application_version')->default(1);
            $table->timestamp('submitted_at', 6);
            $table->timestamp('claimed_at', 6)->nullable();
            $table->timestamp('decided_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'submitted_at']);
            $table->index(['claimed_by_administrator_id', 'state'], 'agent_claimed_state_index');
        });

        Schema::create('agent_application_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('application_id')->constrained('agent_applications')->restrictOnDelete();
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['application_id', 'created_at']);
        });

        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('pricing_profile_code', 64)->nullable();
            $table->foreignId('approved_application_id')->unique()->constrained('agent_applications')->restrictOnDelete();
            $table->foreignId('approved_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->timestamp('approved_at', 6);
            $table->timestamp('suspended_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['status', 'pricing_profile_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_profiles');
        Schema::dropIfExists('agent_application_histories');
        Schema::dropIfExists('agent_applications');
        Schema::dropIfExists('sensitive_action_approvals');
        Schema::dropIfExists('administrator_permission_overrides');
        Schema::dropIfExists('administrator_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('otp_challenges');
        Schema::dropIfExists('phone_numbers');
        Schema::dropIfExists('customer_tag_assignments');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('customer_tier_histories');
        Schema::dropIfExists('customer_status_histories');
        Schema::dropIfExists('administrators');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('customer_tiers');
        Schema::dropIfExists('telegram_accounts');
    }
};
