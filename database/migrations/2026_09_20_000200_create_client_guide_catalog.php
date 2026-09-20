<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-007 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('client_guide_resources', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('code', 64)->unique();
            $table->string('title_fa', 191);
            $table->string('title_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('platform', 32);
            $table->string('language', 8)->default('any');
            $table->string('audience', 16)->default('all');
            $table->string('tier_code', 32)->nullable();
            $table->foreignId('customer_tag_id')->nullable()->constrained('customer_tags')->restrictOnDelete();
            $table->string('resource_url', 512);
            $table->text('tutorial_fa')->nullable();
            $table->text('tutorial_en')->nullable();
            $table->string('normal_emoji', 32)->nullable();
            $table->string('premium_emoji_id', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('state', 16)->default('active');
            $table->unsignedBigInteger('version')->default(1);
            $table->dateTime('last_validated_at', 6);
            $table->unsignedBigInteger('last_validated_by_administrator_id');
            $table->foreign('last_validated_by_administrator_id', 'client_guide_validator_fk')
                ->references('id')->on('administrators')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['state', 'sort_order', 'id'], 'client_guide_state_order_idx');
            $table->index(['audience', 'language', 'state', 'sort_order'], 'client_guide_audience_language_idx');
            $table->index(['tier_code', 'customer_tag_id', 'state'], 'client_guide_policy_idx');
        });

        DB::statement("ALTER TABLE client_guide_resources ADD CONSTRAINT client_guide_language_chk CHECK (`language` IN ('any','fa','en'))");
        DB::statement("ALTER TABLE client_guide_resources ADD CONSTRAINT client_guide_audience_chk CHECK (`audience` IN ('all','customers','agents'))");
        DB::statement("ALTER TABLE client_guide_resources ADD CONSTRAINT client_guide_state_chk CHECK (`state` IN ('active','disabled'))");
        DB::statement('ALTER TABLE client_guide_resources ADD CONSTRAINT client_guide_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE client_guide_resources ADD CONSTRAINT client_guide_customer_scope_chk CHECK ((`tier_code` IS NULL AND `customer_tag_id` IS NULL) OR `audience` = 'customers')");
    }

    public function down(): void
    {
        if (Schema::hasTable('client_guide_resources') && DB::table('client_guide_resources')->exists()) {
            throw new RuntimeException('Cannot roll back client-guide catalogue while resources exist.');
        }

        Schema::dropIfExists('client_guide_resources');
    }
};
