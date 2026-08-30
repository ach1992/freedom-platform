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
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('contract_version')
                ->default(1)
                ->after('event_type');
            $table->index(
                ['event_type', 'contract_version'],
                'outbox_contract_route_index',
            );
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE outbox_messages
    ADD CONSTRAINT outbox_contract_version_chk
    CHECK (contract_version BETWEEN 1 AND 65535)
SQL);

        $telegramTerminalGuardExists = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', 'outbox_telegram_delivery_envelope_update_guard')
            ->exists();
        $ordering = $telegramTerminalGuardExists
            ? "\nPRECEDES outbox_telegram_delivery_envelope_update_guard"
            : '';

        DB::unprepared(<<<SQL
CREATE OR REPLACE TRIGGER outbox_contract_version_update_guard
BEFORE UPDATE ON outbox_messages
FOR EACH ROW{$ordering}
BEGIN
    IF OLD.contract_version <> NEW.contract_version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Outbox durable contract version is immutable.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outbox_messages', 'contract_version')) {
            return;
        }

        if (DB::table('outbox_messages')->where('contract_version', '<>', 1)->exists()) {
            throw new RuntimeException(
                'Outbox contract versioning cannot be removed while non-v1 durable history exists.',
            );
        }

        DB::unprepared('DROP TRIGGER IF EXISTS outbox_contract_version_update_guard');
        DB::unprepared('ALTER TABLE outbox_messages DROP CONSTRAINT outbox_contract_version_chk');

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropIndex('outbox_contract_route_index');
            $table->dropColumn('contract_version');
        });
    }
};
