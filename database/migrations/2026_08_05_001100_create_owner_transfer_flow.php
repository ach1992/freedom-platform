<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    /** @requirement ACL-003 ADM-002 SEC-002 QUA-001 */
    public function up(): void
    {
        if (DB::table('administrators')->where('is_owner', true)->count() > 1) {
            throw new RuntimeException('Owner singleton invariant is already violated.');
        }

        DB::statement(<<<'SQL'
ALTER TABLE administrators
ADD COLUMN owner_guard TINYINT UNSIGNED
GENERATED ALWAYS AS (CASE WHEN is_owner = 1 THEN 1 ELSE NULL END) STORED
SQL);

        Schema::table('administrators', function (Blueprint $table): void {
            $table->unique('owner_guard', 'administrators_owner_singleton_unique');
        });

        Schema::create('owner_transfer_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('current_owner_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->foreignId('target_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->unsignedBigInteger('active_current_owner_id')->nullable()->unique();
            $table->foreign('active_current_owner_id', 'owner_transfer_active_owner_fk')
                ->references('id')
                ->on('administrators')
                ->restrictOnDelete();
            $table->unsignedBigInteger('active_target_administrator_id')->nullable()->unique();
            $table->foreign('active_target_administrator_id', 'owner_transfer_active_target_fk')
                ->references('id')
                ->on('administrators')
                ->restrictOnDelete();
            $table->string('request_fingerprint', 128)->unique();
            $table->char('signed_intent_hash', 64)->unique();
            $table->string('state', 32)->default('pending');
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->unsignedBigInteger('current_owner_permission_version');
            $table->unsignedBigInteger('target_permission_version');
            $table->dateTime('expires_at', 6);
            $table->foreignId('accepted_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('consumed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['state', 'expires_at']);
            $table->index(['current_owner_administrator_id', 'created_at'], 'owner_transfer_current_owner_index');
            $table->index(['target_administrator_id', 'created_at'], 'owner_transfer_target_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_transfer_requests');

        Schema::table('administrators', function (Blueprint $table): void {
            $table->dropUnique('administrators_owner_singleton_unique');
        });

        DB::statement('ALTER TABLE administrators DROP COLUMN owner_guard');
    }
};
