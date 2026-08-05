<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement CAT-001 CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['parent_id', 'state', 'sort_order'], 'product_categories_parent_state_order_idx');
            $table->index(['state', 'sort_order', 'id'], 'product_categories_state_order_idx');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('draft');
            $table->string('visibility', 32)->default('hidden');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['category_id', 'state', 'visibility', 'sort_order'], 'products_category_state_visibility_idx');
            $table->index(['state', 'visibility', 'sort_order', 'id'], 'products_state_visibility_order_idx');
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('sku', 96)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->unique(['product_id', 'code'], 'product_variants_product_code_unique');
            $table->index(['product_id', 'state', 'sort_order', 'id'], 'product_variants_product_state_order_idx');
        });

        Schema::create('product_category_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->foreignId('from_parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('to_parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->unsignedInteger('from_sort_order')->nullable();
            $table->unsignedInteger('to_sort_order');
            $table->char('from_content_hash', 64)->nullable();
            $table->char('to_content_hash', 64);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['category_id', 'version'], 'product_category_history_version_unique');
            $table->index(['category_id', 'created_at'], 'product_category_history_created_idx');
        });

        Schema::create('product_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->string('from_visibility', 32)->nullable();
            $table->string('to_visibility', 32);
            $table->foreignId('from_category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('to_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->unsignedInteger('from_sort_order')->nullable();
            $table->unsignedInteger('to_sort_order');
            $table->char('from_content_hash', 64)->nullable();
            $table->char('to_content_hash', 64);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['product_id', 'version'], 'product_history_version_unique');
            $table->index(['product_id', 'created_at'], 'product_history_created_idx');
        });

        Schema::create('product_variant_histories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('action', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32);
            $table->unsignedInteger('from_sort_order')->nullable();
            $table->unsignedInteger('to_sort_order');
            $table->char('from_content_hash', 64)->nullable();
            $table->char('to_content_hash', 64);
            $table->json('before_safe_data')->nullable();
            $table->json('after_safe_data');
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['variant_id', 'version'], 'product_variant_history_version_unique');
            $table->index(['variant_id', 'created_at'], 'product_variant_history_created_idx');
        });

        DB::statement("ALTER TABLE product_categories ADD CONSTRAINT product_categories_state_chk CHECK (`state` IN ('draft', 'active', 'archived'))");
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_categories_version_chk CHECK (`version` >= 1)');
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT product_categories_parent_self_chk CHECK (`parent_id` IS NULL OR `parent_id` <> `id`)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_state_chk CHECK (`state` IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_visibility_chk CHECK (`visibility` IN ('hidden', 'visible'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_visible_active_chk CHECK (`visibility` = 'hidden' OR `state` = 'active')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_archived_hidden_chk CHECK (`state` <> 'archived' OR `visibility` = 'hidden')");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_version_chk CHECK (`version` >= 1)');
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_state_chk CHECK (`state` IN ('draft', 'active', 'archived'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_version_chk CHECK (`version` >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_histories');
        Schema::dropIfExists('product_histories');
        Schema::dropIfExists('product_category_histories');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
