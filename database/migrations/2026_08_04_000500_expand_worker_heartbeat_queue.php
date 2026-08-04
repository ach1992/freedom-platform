<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement OPS-001 OPS-003 DAT-003 RUN-003 */
    public function up(): void
    {
        Schema::table('worker_heartbeats', function (Blueprint $table): void {
            $table->string('queue', 191)->change();
        });
    }

    public function down(): void
    {
        $longestQueue = DB::table('worker_heartbeats')
            ->selectRaw('MAX(CHAR_LENGTH(queue)) AS maximum_length')
            ->value('maximum_length');

        if (is_numeric($longestQueue) && (int) $longestQueue > 64) {
            throw new \RuntimeException('Cannot contract worker heartbeat queue while values exceed 64 characters.');
        }

        Schema::table('worker_heartbeats', function (Blueprint $table): void {
            $table->string('queue', 64)->change();
        });
    }
};
