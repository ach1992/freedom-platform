<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('panel_mutation_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('action', 96);
            $table->string('target_type', 64);
            $table->string('target_id', 191);
            $table->string('request_fingerprint', 128);
            $table->char('request_payload_hmac', 64);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->dateTime('created_at', 6);
            $table->unique(['action', 'request_fingerprint'], 'panel_mutation_action_fingerprint_unique');
            $table->index(['target_type', 'target_id', 'created_at'], 'panel_mutation_target_created_idx');
        });

        Schema::create('panel_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('provider_type', 32);
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->string('base_url', 512);
            $table->longText('encrypted_credentials');
            $table->unsignedInteger('credential_key_version')->default(1);
            $table->string('tls_policy', 32)->default('system_ca');
            $table->string('custom_ca_disk', 64)->nullable();
            $table->string('custom_ca_path', 512)->nullable();
            $table->char('certificate_pin_sha256', 64)->nullable();
            $table->string('network_policy', 32)->default('public_only');
            $table->string('state', 32)->default('disabled');
            $table->string('last_test_status', 32)->nullable();
            $table->string('last_panel_version', 191)->nullable();
            $table->char('last_capabilities_hash', 64)->nullable();
            $table->dateTime('last_tested_at', 6)->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['provider_type', 'state'], 'panel_connections_provider_state_idx');
            $table->index(['state', 'last_tested_at'], 'panel_connections_state_tested_idx');
        });

        Schema::create('panel_connection_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('panel_connection_id')->constrained('panel_connections')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 96);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['panel_connection_id', 'version'], 'panel_connection_history_version_unique');
            $table->index(['panel_connection_id', 'created_at'], 'panel_connection_history_created_idx');
        });

        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_provider_chk CHECK (`provider_type` IN ('fake', 'marzban', 'pasarguard'))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_base_url_chk CHECK (`base_url` LIKE 'https://%')");
        DB::statement('ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_credentials_chk CHECK (CHAR_LENGTH(`encrypted_credentials`) > 0)');
        DB::statement('ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_credential_version_chk CHECK (`credential_key_version` >= 1)');
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_tls_policy_chk CHECK (`tls_policy` IN ('system_ca', 'custom_ca', 'certificate_pin'))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_tls_material_chk CHECK ((`tls_policy` = 'system_ca' AND `custom_ca_disk` IS NULL AND `custom_ca_path` IS NULL AND `certificate_pin_sha256` IS NULL) OR (`tls_policy` = 'custom_ca' AND `custom_ca_disk` IS NOT NULL AND `custom_ca_path` IS NOT NULL AND `certificate_pin_sha256` IS NULL) OR (`tls_policy` = 'certificate_pin' AND `custom_ca_disk` IS NULL AND `custom_ca_path` IS NULL AND `certificate_pin_sha256` IS NOT NULL))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_network_policy_chk CHECK (`network_policy` IN ('public_only', 'private_allowed'))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_state_chk CHECK (`state` IN ('disabled', 'active', 'maintenance', 'archived'))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_test_status_chk CHECK (`last_test_status` IS NULL OR `last_test_status` IN ('success', 'failure'))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_test_evidence_chk CHECK ((`last_test_status` IS NULL AND `last_tested_at` IS NULL AND `last_panel_version` IS NULL AND `last_capabilities_hash` IS NULL) OR (`last_test_status` = 'failure' AND `last_tested_at` IS NOT NULL) OR (`last_test_status` = 'success' AND `last_tested_at` IS NOT NULL AND `last_panel_version` IS NOT NULL AND `last_capabilities_hash` IS NOT NULL))");
        DB::statement("ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_active_tested_chk CHECK (`state` <> 'active' OR (`last_test_status` = 'success' AND `last_tested_at` IS NOT NULL AND `last_panel_version` IS NOT NULL AND `last_capabilities_hash` IS NOT NULL))");
        DB::statement('ALTER TABLE panel_connections ADD CONSTRAINT panel_connections_version_chk CHECK (`version` >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_connection_histories');
        Schema::dropIfExists('panel_connections');
        Schema::dropIfExists('panel_mutation_receipts');
    }
};
