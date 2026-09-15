<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ADM-001 ACL-002 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::table('administrators', function (Blueprint $table): void {
            $table->dateTime('suspended_at', 6)->nullable()->after('last_authenticated_at');
            $table->dateTime('revoked_at', 6)->nullable()->after('suspended_at');
            $table->string('status_reason_code', 64)->nullable()->after('revoked_at');
        });

        Schema::create('administrator_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->unsignedBigInteger('permission_version');
            $table->unsignedInteger('revoked_role_count')->default(0);
            $table->unsignedInteger('removed_override_count')->default(0);
            $table->dateTime('created_at', 6);
            $table->index(['administrator_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrator_status_histories');

        Schema::table('administrators', function (Blueprint $table): void {
            $table->dropColumn(['suspended_at', 'revoked_at', 'status_reason_code']);
        });
    }
};
