<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement ONB-004 SEC-003 SEC-009 DAT-003 */
    public function up(): void
    {
        Schema::table('processed_telegram_updates', function (Blueprint $table): void {
            $table->char('request_ip_hash', 64)->nullable()->after('payload_size');
        });
    }

    public function down(): void
    {
        Schema::table('processed_telegram_updates', function (Blueprint $table): void {
            $table->dropColumn('request_ip_hash');
        });
    }
};
