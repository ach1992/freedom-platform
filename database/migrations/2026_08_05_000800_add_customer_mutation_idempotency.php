<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement USR-002 USR-003 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->unique(
                ['action', 'request_fingerprint'],
                'audit_action_request_fingerprint_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropUnique('audit_action_request_fingerprint_unique');
        });
    }
};
