<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement CAT-001 CAT-002 DAT-003 QUA-001 */
final class ProductCatalogFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_tables_match_the_phase_boundary_without_premature_price_or_image_models(): void
    {
        self::assertTrue(Schema::hasColumns('product_categories', [
            'parent_id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
            'state', 'sort_order', 'version',
        ]));
        self::assertTrue(Schema::hasColumns('products', [
            'category_id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
            'state', 'visibility', 'sort_order', 'version',
        ]));
        self::assertTrue(Schema::hasColumns('product_variants', [
            'product_id', 'code', 'sku', 'name_fa', 'name_en', 'description_fa', 'description_en',
            'state', 'sort_order', 'version',
        ]));
        self::assertFalse(Schema::hasColumn('products', 'base_price_irr'));
        self::assertFalse(Schema::hasColumn('product_variants', 'price_irr'));
        self::assertFalse(Schema::hasTable('product_images'));
        self::assertTrue(Schema::hasTable('product_category_histories'));
        self::assertTrue(Schema::hasTable('product_histories'));
        self::assertTrue(Schema::hasTable('product_variant_histories'));
        self::assertFalse(Schema::hasTable('product_catalog_histories'));
    }

    public function test_multiple_root_categories_may_share_sort_order_without_nullable_unique_ambiguity(): void
    {
        $now = now('UTC');
        DB::table('product_categories')->insert([
            [
                'parent_id' => null,
                'code' => 'root-one',
                'name_fa' => 'ریشه یک',
                'state' => 'draft',
                'sort_order' => 10,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'parent_id' => null,
                'code' => 'root-two',
                'name_fa' => 'ریشه دو',
                'state' => 'draft',
                'sort_order' => 10,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        self::assertSame(2, DB::table('product_categories')->whereNull('parent_id')->where('sort_order', 10)->count());
    }

    public function test_database_rejects_invalid_catalog_state_and_self_parenting(): void
    {
        $now = now('UTC');
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'constraint-root',
            'name_fa' => 'ریشه',
            'state' => 'draft',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('product_categories')->where('id', $categoryId)->update(['parent_id' => $categoryId]);
            self::fail('Expected database self-parent constraint failure.');
        } catch (QueryException) {
            self::assertNull(DB::table('product_categories')->where('id', $categoryId)->value('parent_id'));
        }

        $this->expectException(QueryException::class);
        DB::table('product_categories')->insert([
            'parent_id' => null,
            'code' => 'invalid-state',
            'name_fa' => 'نامعتبر',
            'state' => 'invalid',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_entity_specific_history_has_database_referential_integrity(): void
    {
        $administratorId = $this->administrator();

        $this->expectException(QueryException::class);
        DB::table('product_category_histories')->insert([
            'category_id' => 999999,
            'version' => 1,
            'action' => 'catalog.category.create',
            'from_state' => null,
            'to_state' => 'draft',
            'from_parent_id' => null,
            'to_parent_id' => null,
            'from_sort_order' => null,
            'to_sort_order' => 0,
            'from_content_hash' => null,
            'to_content_hash' => str_repeat('a', 64),
            'before_safe_data' => null,
            'after_safe_data' => '{}',
            'actor_administrator_id' => $administratorId,
            'reason_code' => 'test',
            'reason' => 'Test history integrity.',
            'correlation_id' => 'catalog-history-correlation-0001',
            'created_at' => now('UTC'),
        ]);
    }

    private function administrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
