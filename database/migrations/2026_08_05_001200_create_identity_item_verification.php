<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement USR-001 SEC-003 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('identity_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 32);
            $table->text('encrypted_value');
            $table->char('lookup_hash', 64);
            $table->char('active_lookup_hash', 64)->nullable()->unique();
            $table->unsignedSmallInteger('hash_key_version');
            $table->string('masked_value', 191);
            $table->string('state', 32)->default('pending');
            $table->boolean('ownership_check_required')->default(false);
            $table->string('ownership_check_status', 32)->default('not_required');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('verified_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->foreignId('rejected_by_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('decision_reason_code', 64)->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('submitted_at', 6);
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('rejected_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['user_id', 'type'], 'identity_items_user_type_unique');
            $table->index(['type', 'state']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('identity_item_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('identity_item_id')->constrained('identity_items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('masked_value', 191);
            $table->string('ownership_check_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_administrator_id')->nullable()->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['identity_item_id', 'version'], 'identity_item_history_version_unique');
            $table->index(['identity_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_item_histories');
        Schema::dropIfExists('identity_items');
    }
};
