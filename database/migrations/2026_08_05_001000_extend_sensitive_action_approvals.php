<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ACL-003 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::table('sensitive_action_approvals', function (Blueprint $table): void {
            $table->foreignId('permission_id')
                ->nullable()
                ->after('requested_by_administrator_id')
                ->constrained('permissions', indexName: 'sensitive_approval_permission_fk')
                ->restrictOnDelete();
            $table->string('request_reason_code', 64)->nullable()->after('request_fingerprint');
            $table->text('request_reason')->nullable()->after('request_reason_code');
            $table->string('correlation_id', 64)->nullable()->after('request_reason');
            $table->boolean('independent_approval_required')->default(true)->after('correlation_id');
            $table->unsignedBigInteger('requester_permission_version')->nullable()->after('independent_approval_required');
            $table->unsignedBigInteger('approver_permission_version')->nullable()->after('requester_permission_version');
            $table->char('execution_fingerprint', 64)->nullable()->unique()->after('decided_at');
            $table->foreignId('consumed_by_administrator_id')
                ->nullable()
                ->after('execution_fingerprint')
                ->constrained('administrators', indexName: 'sensitive_approval_consumer_fk')
                ->restrictOnDelete();
            $table->dateTime('consumed_at', 6)->nullable()->after('consumed_by_administrator_id');
            $table->index(['permission_id', 'state', 'expires_at'], 'sensitive_approval_permission_state_idx');
            $table->index(['requested_by_administrator_id', 'state', 'expires_at'], 'sensitive_approval_requester_state_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sensitive_action_approvals', function (Blueprint $table): void {
            $table->dropIndex('sensitive_approval_permission_state_idx');
            $table->dropIndex('sensitive_approval_requester_state_idx');
            $table->dropForeign('sensitive_approval_permission_fk');
            $table->dropForeign('sensitive_approval_consumer_fk');
            $table->dropUnique(['execution_fingerprint']);
            $table->dropColumn([
                'permission_id',
                'request_reason_code',
                'request_reason',
                'correlation_id',
                'independent_approval_required',
                'requester_permission_version',
                'approver_permission_version',
                'execution_fingerprint',
                'consumed_by_administrator_id',
                'consumed_at',
            ]);
        });
    }
};
