<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement PRD-001 SEC-002 DAT-001 QUA-001 */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['state', 'sort_order']);
            $table->unique(['parent_id', 'sort_order', 'code'], 'product_categories_tree_order_unique');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('state', 32)->default('draft');
            $table->string('visibility', 32)->default('hidden');
            $table->unsignedBigInteger('base_price_irr');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->index(['category_id', 'state', 'visibility']);
            $table->index(['state', 'visibility', 'sort_order']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('sku', 96)->unique();
            $table->string('name_fa', 191);
            $table->string('name_en', 191)->nullable();
            $table->string('state', 32)->default('draft');
            $table->unsignedBigInteger('price_irr');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps(6);
            $table->unique(['product_id', 'code'], 'product_variants_product_code_unique');
            $table->index(['product_id', 'state', 'sort_order']);
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('storage_disk', 64);
            $table->string('storage_path', 512);
            $table->char('content_hash', 64);
            $table->string('alt_fa', 191)->nullable();
            $table->string('alt_en', 191)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
            $table->unique(['product_id', 'content_hash'], 'product_images_product_hash_unique');
            $table->unique(['product_id', 'sort_order'], 'product_images_product_order_unique');
            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        Schema::create('product_catalog_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('version');
            $table->string('action', 64);
            $table->string('from_state', 32)->nullable();
            $table->string('to_state', 32)->nullable();
            $table->string('from_visibility', 32)->nullable();
            $table->string('to_visibility', 32)->nullable();
            $table->foreignId('actor_administrator_id')->constrained('administrators')->restrictOnDelete();
            $table->string('reason_code', 64);
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->unique(['entity_type', 'entity_id', 'version'], 'product_catalog_history_version_unique');
            $table->index(['entity_type', 'entity_id', 'created_at'], 'product_catalog_history_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_catalog_histories');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
