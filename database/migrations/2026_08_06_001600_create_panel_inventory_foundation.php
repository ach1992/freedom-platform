<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-002 CAT-004 PRV-001 ACL-002 SEC-001 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('panel_protocol_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->string('protocol_family', 64);
            $table->string('transport', 64)->nullable();
            $table->string('security_layer', 64)->nullable();
            $table->string('host', 253)->nullable();
            $table->string('sni', 253)->nullable();
            $table->string('path', 512)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('flow', 64)->nullable();
            $table->string('state', 32)->default('disabled');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['state', 'protocol_family'], 'panel_profiles_state_family_idx');
        });

        Schema::create('panel_service_targets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('panel_connection_id')->constrained('panel_connections')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('kind', 32);
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->longText('encrypted_configuration');
            $table->char('configuration_hash', 64);
            $table->unsignedInteger('configuration_key_version')->default(1);
            $table->string('state', 32)->default('disabled');
            $table->string('capability_status', 32)->default('declared');
            $table->char('capability_evidence_hash', 64)->nullable();
            $table->dateTime('capability_verified_at', 6)->nullable();
            $table->unsignedBigInteger('verified_connection_version')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['panel_connection_id', 'state'], 'panel_targets_connection_state_idx');
            $table->index(['state', 'capability_status'], 'panel_targets_state_capability_idx');
        });

        Schema::create('panel_target_capabilities', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('panel_service_target_id')->constrained('panel_service_targets')->restrictOnDelete();
            $table->string('capability_code', 64);
            $table->string('verification_status', 32)->default('declared');
            $table->char('evidence_hash', 64)->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['panel_service_target_id', 'capability_code'], 'panel_target_capability_unique');
            $table->index(['capability_code', 'verification_status'], 'panel_capability_status_idx');
        });

        Schema::create('panel_target_protocol_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('panel_service_target_id')->constrained('panel_service_targets')->restrictOnDelete();
            $table->foreignId('panel_protocol_profile_id')->constrained('panel_protocol_profiles')->restrictOnDelete();
            $table->boolean('customer_selectable')->default(false);
            $table->timestamps(6);
            $table->unique(
                ['panel_service_target_id', 'panel_protocol_profile_id'],
                'panel_target_profile_unique',
            );
        });

        Schema::create('sales_servers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('disabled');
            $table->string('visibility', 32)->default('hidden');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['visibility', 'state', 'sort_order'], 'sales_servers_listing_idx');
        });

        $this->createHistoryTable(
            'panel_protocol_profile_histories',
            'panel_protocol_profile_id',
            'panel_protocol_profiles',
            'panel_profile_history_parent_fk',
        );
        $this->createHistoryTable(
            'panel_service_target_histories',
            'panel_service_target_id',
            'panel_service_targets',
            'panel_target_history_parent_fk',
        );
        $this->createHistoryTable(
            'sales_server_histories',
            'sales_server_id',
            'sales_servers',
            'sales_server_history_parent_fk',
        );

        DB::statement("ALTER TABLE panel_protocol_profiles ADD CONSTRAINT panel_profiles_state_chk CHECK (`state` IN ('disabled', 'active', 'maintenance', 'archived'))");
        DB::statement('ALTER TABLE panel_protocol_profiles ADD CONSTRAINT panel_profiles_name_chk CHECK (CHAR_LENGTH(`name_fa`) > 0)');
        DB::statement('ALTER TABLE panel_protocol_profiles ADD CONSTRAINT panel_profiles_family_chk CHECK (CHAR_LENGTH(`protocol_family`) > 0)');
        DB::statement('ALTER TABLE panel_protocol_profiles ADD CONSTRAINT panel_profiles_port_chk CHECK (`port` IS NULL OR (`port` BETWEEN 1 AND 65535))');
        DB::statement('ALTER TABLE panel_protocol_profiles ADD CONSTRAINT panel_profiles_version_chk CHECK (`version` >= 1)');

        DB::statement("ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_kind_chk CHECK (`kind` IN ('inbound', 'group', 'template', 'host'))");
        DB::statement("ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_state_chk CHECK (`state` IN ('disabled', 'active', 'maintenance', 'archived'))");
        DB::statement("ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_capability_status_chk CHECK (`capability_status` IN ('declared', 'verified', 'stale'))");
        DB::statement('ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_configuration_chk CHECK (CHAR_LENGTH(`encrypted_configuration`) > 0 AND CHAR_LENGTH(`configuration_hash`) = 64)');
        DB::statement('ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_key_version_chk CHECK (`configuration_key_version` >= 1)');
        DB::statement("ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_capability_evidence_chk CHECK ((`capability_status` = 'declared' AND `capability_evidence_hash` IS NULL AND `capability_verified_at` IS NULL AND `verified_connection_version` IS NULL) OR (`capability_status` IN ('verified', 'stale') AND `capability_evidence_hash` IS NOT NULL AND `capability_verified_at` IS NOT NULL AND `verified_connection_version` IS NOT NULL))");
        DB::statement('ALTER TABLE panel_service_targets ADD CONSTRAINT panel_targets_version_chk CHECK (`version` >= 1)');

        DB::statement("ALTER TABLE panel_target_capabilities ADD CONSTRAINT panel_capabilities_status_chk CHECK (`verification_status` IN ('declared', 'verified', 'stale'))");
        DB::statement("ALTER TABLE panel_target_capabilities ADD CONSTRAINT panel_capabilities_evidence_chk CHECK ((`verification_status` = 'declared' AND `evidence_hash` IS NULL AND `verified_at` IS NULL) OR (`verification_status` IN ('verified', 'stale') AND `evidence_hash` IS NOT NULL AND `verified_at` IS NOT NULL))");
        DB::statement('ALTER TABLE panel_target_capabilities ADD CONSTRAINT panel_capabilities_code_chk CHECK (CHAR_LENGTH(`capability_code`) > 0)');

        DB::statement("ALTER TABLE sales_servers ADD CONSTRAINT sales_servers_state_chk CHECK (`state` IN ('disabled', 'active', 'maintenance', 'archived'))");
        DB::statement("ALTER TABLE sales_servers ADD CONSTRAINT sales_servers_visibility_chk CHECK (`visibility` IN ('hidden', 'listed'))");
        DB::statement("ALTER TABLE sales_servers ADD CONSTRAINT sales_servers_listing_state_chk CHECK (`visibility` <> 'listed' OR `state` = 'active')");
        DB::statement('ALTER TABLE sales_servers ADD CONSTRAINT sales_servers_name_chk CHECK (CHAR_LENGTH(`name_fa`) > 0)');
        DB::statement('ALTER TABLE sales_servers ADD CONSTRAINT sales_servers_version_chk CHECK (`version` >= 1)');

        $this->createTriggers();
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('sales_server_histories');
        Schema::dropIfExists('panel_service_target_histories');
        Schema::dropIfExists('panel_protocol_profile_histories');
        Schema::dropIfExists('sales_servers');
        Schema::dropIfExists('panel_target_protocol_profiles');
        Schema::dropIfExists('panel_target_capabilities');
        Schema::dropIfExists('panel_service_targets');
        Schema::dropIfExists('panel_protocol_profiles');
    }

    private function createHistoryTable(
        string $tableName,
        string $foreignKey,
        string $parentTable,
        string $parentForeignName,
    ): void {
        Schema::create($tableName, function (Blueprint $table) use ($tableName, $foreignKey, $parentTable, $parentForeignName): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger($foreignKey);
            $table->foreign($foreignKey, $parentForeignName)
                ->references('id')
                ->on($parentTable)
                ->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 96);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique([$foreignKey, 'version'], $tableName.'_version_unique');
            $table->index([$foreignKey, 'created_at'], $tableName.'_created_idx');
        });
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_connections_archive_targets_guard
BEFORE UPDATE ON panel_connections
FOR EACH ROW
BEGIN
    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (
           SELECT 1 FROM panel_service_targets
           WHERE panel_connection_id = OLD.id AND state <> 'archived'
       )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Panel connection has non-archived service targets.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_profiles_update_guard
BEFORE UPDATE ON panel_protocol_profiles
FOR EACH ROW
BEGIN
    IF OLD.state = 'active' AND (
        NOT (OLD.protocol_family <=> NEW.protocol_family)
        OR NOT (OLD.transport <=> NEW.transport)
        OR NOT (OLD.security_layer <=> NEW.security_layer)
        OR NOT (OLD.host <=> NEW.host)
        OR NOT (OLD.sni <=> NEW.sni)
        OR NOT (OLD.path <=> NEW.path)
        OR NOT (OLD.port <=> NEW.port)
        OR NOT (OLD.flow <=> NEW.flow)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Active protocol profile definition is immutable.';
    END IF;

    IF NEW.state = 'archived' AND OLD.state <> 'archived'
       AND EXISTS (
           SELECT 1
           FROM panel_target_protocol_profiles assignment
           INNER JOIN panel_service_targets target ON target.id = assignment.panel_service_target_id
           WHERE assignment.panel_protocol_profile_id = OLD.id AND target.state <> 'archived'
       )
    THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Protocol profile is assigned to a non-archived service target.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_targets_insert_guard
BEFORE INSERT ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_targets_update_guard
BEFORE UPDATE ON panel_service_targets
FOR EACH ROW
BEGIN
    IF NEW.state = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Service target activation requires verified adapter evidence.';
    END IF;

    IF OLD.state IN ('active', 'maintenance') AND (
        NOT (OLD.panel_connection_id <=> NEW.panel_connection_id)
        OR NOT (OLD.kind <=> NEW.kind)
        OR NOT (OLD.encrypted_configuration <=> NEW.encrypted_configuration)
        OR NOT (OLD.configuration_hash <=> NEW.configuration_hash)
        OR NOT (OLD.configuration_key_version <=> NEW.configuration_key_version)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational service target configuration is immutable.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_protocol_profile_histories_update_guard
BEFORE UPDATE ON panel_protocol_profile_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_protocol_profile_histories_delete_guard
BEFORE DELETE ON panel_protocol_profile_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_service_target_histories_update_guard
BEFORE UPDATE ON panel_service_target_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_service_target_histories_delete_guard
BEFORE DELETE ON panel_service_target_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER sales_server_histories_update_guard
BEFORE UPDATE ON sales_server_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER sales_server_histories_delete_guard
BEFORE DELETE ON sales_server_histories
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History rows are append-only.'
SQL);
    }

    private function dropTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS panel_connections_archive_targets_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_profiles_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_targets_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_targets_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_protocol_profile_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_protocol_profile_histories_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_service_target_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS panel_service_target_histories_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sales_server_histories_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS sales_server_histories_delete_guard');
    }
};
