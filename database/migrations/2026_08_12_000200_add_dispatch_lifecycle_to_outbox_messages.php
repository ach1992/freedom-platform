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
            $table->string('dispatch_state', 32)->default('pending')->after('processed_at');
            $table->string('lease_token', 64)->nullable()->after('dispatch_state');
            $table->timestamp('leased_until', 6)->nullable()->after('lease_token');
            $table->string('review_reason', 64)->nullable()->after('leased_until');
            $table->index(['dispatch_state', 'available_at', 'leased_until'], 'outbox_dispatch_claim_index');
        });

        DB::table('outbox_messages')
            ->whereNotNull('processed_at')
            ->update(['dispatch_state' => 'processed']);
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropIndex('outbox_dispatch_claim_index');
            $table->dropColumn(['dispatch_state', 'lease_token', 'leased_until', 'review_reason']);
        });
    }
};
