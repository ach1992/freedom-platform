<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement AGT-001 AGT-002 ACL-002 SEC-002 QUA-001 */
    public function up(): void
    {
        DB::table('agent_applications')->where('state', 'claimed')->update(['state' => 'under_review']);
        DB::table('agent_application_histories')->where('from_state', 'claimed')->update(['from_state' => 'under_review']);
        DB::table('agent_application_histories')->where('to_state', 'claimed')->update(['to_state' => 'under_review']);

        Schema::table('agent_application_histories', function (Blueprint $table): void {
            $table->string('correlation_id', 64)->nullable()->after('reason');
        });

        Schema::table('agent_applications', function (Blueprint $table): void {
            $table->timestamp('reapply_allowed_at')->nullable()->after('decision_reason');
            $table->timestamp('reapplication_released_at')->nullable()->after('reapply_allowed_at');
            $table->foreignId('reapplication_released_by_administrator_id')
                ->nullable()
                ->after('reapplication_released_at')
                ->constrained('administrators', indexName: 'agent_app_release_admin_fk')
                ->nullOnDelete();
            $table->string('reapplication_release_reason_code', 64)->nullable()->after('reapplication_released_by_administrator_id');
            $table->text('reapplication_release_reason')->nullable()->after('reapplication_release_reason_code');
            $table->index(['state', 'reapply_allowed_at'], 'agent_app_state_reapply_idx');
        });

        Schema::create('agent_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_profile_id')->constrained('agent_profiles')->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->foreignId('actor_administrator_id')
                ->nullable()
                ->constrained('administrators', indexName: 'agent_status_history_admin_fk')
                ->nullOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason')->nullable();
            $table->string('correlation_id', 64);
            $table->timestamp('created_at');
            $table->index(['agent_profile_id', 'created_at'], 'agent_status_history_profile_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_status_histories');

        Schema::table('agent_applications', function (Blueprint $table): void {
            $table->dropIndex('agent_app_state_reapply_idx');
            $table->dropForeign('agent_app_release_admin_fk');
            $table->dropColumn([
                'reapply_allowed_at',
                'reapplication_released_at',
                'reapplication_released_by_administrator_id',
                'reapplication_release_reason_code',
                'reapplication_release_reason',
            ]);
        });

        Schema::table('agent_application_histories', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });

        DB::table('agent_applications')->where('state', 'under_review')->update(['state' => 'claimed']);
        DB::table('agent_application_histories')->where('from_state', 'under_review')->update(['from_state' => 'claimed']);
        DB::table('agent_application_histories')->where('to_state', 'under_review')->update(['to_state' => 'claimed']);
    }
};