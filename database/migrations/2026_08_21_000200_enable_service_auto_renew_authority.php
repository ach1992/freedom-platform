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
        $this->resetInterruptedInstallIfSafe();

        Schema::create('plan_offering_auto_renew_policies', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('plan_offering_id')->unique('sarp_offering_uq');
            $table->foreign('plan_offering_id', 'sarp_offering_fk')->references('id')->on('plan_offerings')->restrictOnDelete();
            $table->string('price_change_mode', 32);
            $table->bigInteger('absolute_increase_limit_irr')->nullable();
            $table->unsignedInteger('percentage_increase_limit_bps')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->foreignId('actor_administrator_id');
            $table->foreign('actor_administrator_id', 'sarp_actor_fk')->references('id')->on('administrators')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->timestamps(6);
        });

        Schema::create('plan_offering_auto_renew_policy_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('auto_renew_policy_id');
            $table->foreign('auto_renew_policy_id', 'sarph_policy_fk')->references('id')->on('plan_offering_auto_renew_policies')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('price_change_mode', 32);
            $table->bigInteger('absolute_increase_limit_irr')->nullable();
            $table->unsignedInteger('percentage_increase_limit_bps')->nullable();
            $table->foreignId('actor_administrator_id');
            $table->foreign('actor_administrator_id', 'sarph_actor_fk')->references('id')->on('administrators')->restrictOnDelete();
            $table->char('request_key_hash', 64)->unique('sarph_request_uq');
            $table->char('payload_hash', 64);
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['auto_renew_policy_id', 'version'], 'sarph_policy_version_idx');
        });

        Schema::create('service_auto_renew_configurations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('service_subscription_id')->unique('sarc_service_uq');
            $table->foreign('service_subscription_id', 'sarc_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
            $table->foreignId('renewal_package_id');
            $table->foreign('renewal_package_id', 'sarc_package_fk')->references('id')->on('plan_offering_packages')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->bigInteger('accepted_price_irr');
            $table->bigInteger('last_settled_price_irr')->nullable();
            $table->dateTime('observed_expires_at', 6)->nullable();
            $table->dateTime('expiry_observed_at', 6)->nullable();
            $table->char('observed_expiry_evidence_hash', 64)->nullable();
            $table->string('observed_expiry_source', 32)->nullable();
            $table->unsignedBigInteger('observed_remote_identity_generation')->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->string('last_correlation_id', 64);
            $table->timestamps(6);
            $table->index(['enabled', 'observed_expires_at'], 'sarc_due_idx');
        });

        Schema::create('service_auto_renew_configuration_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('auto_renew_configuration_id');
            $table->foreign('auto_renew_configuration_id', 'sarch_config_fk')->references('id')->on('service_auto_renew_configurations')->restrictOnDelete();
            $table->unsignedBigInteger('configuration_version');
            $table->foreignId('actor_user_id');
            $table->foreign('actor_user_id', 'sarch_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->boolean('enabled');
            $table->foreignId('renewal_package_id');
            $table->foreign('renewal_package_id', 'sarch_package_fk')->references('id')->on('plan_offering_packages')->restrictOnDelete();
            $table->bigInteger('accepted_price_irr');
            $table->dateTime('observed_expires_at', 6)->nullable();
            $table->char('observed_expiry_evidence_hash', 64)->nullable();
            $table->string('observed_expiry_source', 32)->nullable();
            $table->unsignedBigInteger('observed_remote_identity_generation')->nullable();
            $table->char('request_key_hash', 64)->unique('sarch_request_uq');
            $table->char('payload_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['auto_renew_configuration_id', 'configuration_version'], 'sarch_config_version_idx');
        });

        Schema::create('service_auto_renew_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique('sara_public_uq');
            $table->char('cycle_key', 64)->unique('sara_cycle_uq');
            $table->foreignId('auto_renew_configuration_id');
            $table->foreign('auto_renew_configuration_id', 'sara_config_fk')->references('id')->on('service_auto_renew_configurations')->restrictOnDelete();
            $table->foreignId('service_subscription_id');
            $table->foreign('service_subscription_id', 'sara_service_fk')->references('id')->on('service_subscriptions')->restrictOnDelete();
            $table->unsignedBigInteger('configuration_version');
            $table->unsignedBigInteger('remote_identity_generation');
            $table->dateTime('observed_expires_at', 6);
            $table->char('observed_expiry_evidence_hash', 64);
            $table->string('observed_expiry_source', 32);
            $table->string('state', 32)->default('pending');
            $table->string('reason_code', 64)->nullable();
            $table->bigInteger('baseline_price_irr');
            $table->bigInteger('current_price_irr')->nullable();
            $table->foreignId('quote_id')->nullable();
            $table->foreign('quote_id', 'sara_quote_fk')->references('id')->on('quotes')->restrictOnDelete();
            $table->foreignId('payment_eligibility_decision_id')->nullable();
            $table->foreign('payment_eligibility_decision_id', 'sara_eligibility_fk')->references('id')->on('payment_method_eligibility_decisions')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable();
            $table->foreign('payment_intent_id', 'sara_intent_fk')->references('id')->on('payment_intents')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->nullable();
            $table->foreign('purchase_settlement_id', 'sara_settlement_fk')->references('id')->on('purchase_settlements')->restrictOnDelete();
            $table->foreignId('provisioning_operation_id')->nullable();
            $table->foreign('provisioning_operation_id', 'sara_operation_fk')->references('id')->on('provisioning_operations')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->unsignedSmallInteger('commercial_generation')->default(0);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(
                ['auto_renew_configuration_id', 'configuration_version', 'remote_identity_generation', 'observed_expires_at'],
                'sara_config_cycle_idx',
            );
            $table->index(['state', 'next_retry_at', 'updated_at'], 'sara_state_retry_idx');
            $table->index(['service_subscription_id', 'created_at'], 'sara_service_created_idx');
        });

        Schema::create('service_auto_renew_attempt_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('auto_renew_attempt_id');
            $table->foreign('auto_renew_attempt_id', 'sarae_attempt_fk')->references('id')->on('service_auto_renew_attempts')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('reason_code', 64)->nullable();
            $table->foreignId('quote_id')->nullable();
            $table->foreign('quote_id', 'sarae_quote_fk')->references('id')->on('quotes')->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable();
            $table->foreign('payment_intent_id', 'sarae_intent_fk')->references('id')->on('payment_intents')->restrictOnDelete();
            $table->foreignId('purchase_settlement_id')->nullable();
            $table->foreign('purchase_settlement_id', 'sarae_settlement_fk')->references('id')->on('purchase_settlements')->restrictOnDelete();
            $table->foreignId('provisioning_operation_id')->nullable();
            $table->foreign('provisioning_operation_id', 'sarae_operation_fk')->references('id')->on('provisioning_operations')->restrictOnDelete();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['auto_renew_attempt_id', 'sequence'], 'sarae_attempt_sequence_uq');
        });

        Schema::create('service_auto_renew_notification_intents', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique('sarni_public_uq');
            $table->foreignId('auto_renew_attempt_id');
            $table->foreign('auto_renew_attempt_id', 'sarni_attempt_fk')->references('id')->on('service_auto_renew_attempts')->restrictOnDelete();
            $table->string('outcome', 32);
            $table->string('reason_code', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->unique(['auto_renew_attempt_id', 'outcome'], 'sarni_attempt_outcome_uq');
        });

    }

    public function down(): void
    {
        foreach ([
            'service_auto_renew_notification_intents',
            'service_auto_renew_attempt_events',
            'service_auto_renew_attempts',
            'service_auto_renew_configuration_histories',
            'service_auto_renew_configurations',
            'plan_offering_auto_renew_policy_histories',
            'plan_offering_auto_renew_policies',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back Service auto-renew authority while auto-renew authority rows exist.');
            }
        }

        $this->dropTriggers();
        Schema::dropIfExists('service_auto_renew_notification_intents');
        Schema::dropIfExists('service_auto_renew_attempt_events');
        Schema::dropIfExists('service_auto_renew_attempts');
        Schema::dropIfExists('service_auto_renew_configuration_histories');
        Schema::dropIfExists('service_auto_renew_configurations');
        Schema::dropIfExists('plan_offering_auto_renew_policy_histories');
        Schema::dropIfExists('plan_offering_auto_renew_policies');
    }

    private function resetInterruptedInstallIfSafe(): void
    {
        $tables = [
            'plan_offering_auto_renew_policies',
            'plan_offering_auto_renew_policy_histories',
            'service_auto_renew_configurations',
            'service_auto_renew_configuration_histories',
            'service_auto_renew_attempts',
            'service_auto_renew_attempt_events',
            'service_auto_renew_notification_intents',
        ];
        $existing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => Schema::hasTable($table),
        ));
        if ($existing === []) {
            return;
        }

        foreach ($existing as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Auto-renew authority migration cannot repair an interrupted install after authority rows exist.',
                );
            }
        }

        // MariaDB commits DDL per statement. If a previous attempt stopped after creating only
        // part of this new, still-empty authority surface, remove that unused partial surface and
        // rebuild it from the beginning instead of failing forever on CREATE TABLE/CONSTRAINT.
        $this->dropTriggers();
        foreach (array_reverse($tables) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarp_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarc_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sara_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sara_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sara_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarph_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarph_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarch_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarch_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarae_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarae_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_insert_authority_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sarni_delete_guard');
    }
};
