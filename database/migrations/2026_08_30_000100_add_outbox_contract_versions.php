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

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropIndex('outbox_contract_route_index');
            $table->dropColumn('contract_version');
        });
    }
};
