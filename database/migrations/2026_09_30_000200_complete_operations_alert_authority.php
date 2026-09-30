<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement OPS-001 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 QUA-004 */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->unsignedInteger('activation_sequence')->default(1)->after('occurrence_count');
        });

        Schema::create('operational_alert_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->uuid('alert_id');
            $table->foreign('alert_id', 'operational_alert_events_alert_fk')
                ->references('id')
                ->on('alerts')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->foreignId('actor_administrator_id')
                ->constrained('administrators', indexName: 'operational_alert_events_actor_fk')
                ->restrictOnDelete();
            $table->char('request_key_hash', 64)->unique();
            $table->string('reason', 500);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['alert_id', 'created_at'], 'operational_alert_events_alert_created_idx');
        });

        Schema::create('operational_alert_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->uuid('alert_id');
            $table->foreign('alert_id', 'operational_alert_deliveries_alert_fk')
                ->references('id')
                ->on('alerts')
                ->restrictOnDelete();
            $table->unsignedInteger('activation_sequence');
            $table->string('audience', 32);
            $table->char('request_key_hash', 64)->unique();
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->char('lease_token_hash', 64)->nullable();
            $table->dateTime('leased_until', 6)->nullable();
            $table->ulid('telegram_operation_public_id')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->dateTime('queued_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(
                ['alert_id', 'activation_sequence', 'audience'],
                'operational_alert_delivery_episode_audience_unique',
            );
            $table->index(
                ['state', 'available_at', 'leased_until'],
                'operational_alert_delivery_claim_idx',
            );
        });

        DB::statement("ALTER TABLE operational_alert_events ADD CONSTRAINT operational_alert_events_type_chk CHECK (event_type IN ('acknowledged','resolved'))");
        DB::statement("ALTER TABLE operational_alert_events ADD CONSTRAINT operational_alert_events_hash_chk CHECK (CHAR_LENGTH(request_key_hash) = 64)");
        DB::statement("ALTER TABLE operational_alert_deliveries ADD CONSTRAINT operational_alert_delivery_audience_chk CHECK (audience IN ('report_channel','owner'))");
        DB::statement("ALTER TABLE operational_alert_deliveries ADD CONSTRAINT operational_alert_delivery_state_chk CHECK (state IN ('pending','retry','leased','queued','failed'))");
        DB::statement('ALTER TABLE operational_alert_deliveries ADD CONSTRAINT operational_alert_delivery_activation_chk CHECK (activation_sequence >= 1)');
        DB::statement('ALTER TABLE operational_alert_deliveries ADD CONSTRAINT operational_alert_delivery_attempts_chk CHECK (attempts <= 100)');
        DB::statement("ALTER TABLE operational_alert_deliveries ADD CONSTRAINT operational_alert_delivery_shape_chk CHECK ((state = 'leased' AND lease_token_hash IS NOT NULL AND leased_until IS NOT NULL AND telegram_operation_public_id IS NULL AND queued_at IS NULL) OR (state = 'queued' AND lease_token_hash IS NULL AND leased_until IS NULL AND telegram_operation_public_id IS NOT NULL AND queued_at IS NOT NULL) OR (state IN ('pending','retry','failed') AND lease_token_hash IS NULL AND leased_until IS NULL AND telegram_operation_public_id IS NULL AND queued_at IS NULL))");

        $this->installEventGuards();
    }

    public function down(): void
    {
        if (DB::table('operational_alert_events')->exists()
            || DB::table('operational_alert_deliveries')->exists()
        ) {
            throw new \RuntimeException('Cannot roll back operational alert authority while durable evidence exists.');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS operational_alert_events_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS operational_alert_events_delete_guard');
        Schema::dropIfExists('operational_alert_deliveries');
        Schema::dropIfExists('operational_alert_events');

        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropColumn('activation_sequence');
        });
    }

    private function installEventGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER operational_alert_events_update_guard
BEFORE UPDATE ON operational_alert_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational alert lifecycle events are immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER operational_alert_events_delete_guard
BEFORE DELETE ON operational_alert_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Operational alert lifecycle events are non-deletable.';
END
SQL);
    }
};
