<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement UPD-001 RUN-003 OPS-003 */
    public function up(): void
    {
        Schema::table('worker_heartbeats', function (Blueprint $table): void {
            $table->string('boot_id', 32)->nullable()->after('release_version');
        });
    }

    public function down(): void
    {
        Schema::table('worker_heartbeats', function (Blueprint $table): void {
            $table->dropColumn('boot_id');
        });
    }
};
