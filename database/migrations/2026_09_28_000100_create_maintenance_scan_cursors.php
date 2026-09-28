<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement OPS-003 WAL-002 PAY-002 DAT-003 QUA-004 */
    public function up(): void
    {
        Schema::create('maintenance_scan_cursors', function (Blueprint $table): void {
            $table->string('cursor_name', 128)->primary();
            $table->unsignedBigInteger('last_scanned_id')->nullable();
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_scan_cursors');
    }
};
