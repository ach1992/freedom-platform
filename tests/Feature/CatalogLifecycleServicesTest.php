<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\ProductCategoryService;
use App\Modules\Catalog\Application\ProductService;
use App\Modules\Catalog\Application\ProductVariantService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-001 CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
final class CatalogLifecycleServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_category_product_and_variant_lifecycle_is_audited_replay_safe_and_dependency_guarded(): void
    {
        $ownerId = $this->administrator(true);
        $categories = $this->app->make(ProductCategoryService::class);
        $products = $this->app->make(ProductService::class);
        $variants = $this->app->make(ProductVariantService::class);

        $categoryContext = $this->context($ownerId, 'catalog-category-create-0001');
        $category = $categories->create(
            'vpn',
            'سرویس ویژه',
            'VPN',
            'catalog-sensitive-marker-must-not-enter-audit',
            null,
            null,
            10,
            $categoryContext,
        );
        $replay = $categories->create(
            'vpn',
            'سرویس ویژه',
            'VPN',
            'catalog-sensitive-marker-must-not-enter-audit',
            null,
            null,
            10,
            $categoryContext,
        );
        self::assertTrue($category->changed);
        self::assertTrue($replay->replayed);
        self::assertSame($category->targetId, $replay->targetId);
        self::assertSame(1, DB::table('product_categories')->count());
        self::assertSame(1, DB::table('product_category_histories')->count());

        $categories->activate(
            $category->targetId,
            1,
            $this->context($ownerId, 'catalog-category-activate-0001'),
        );
        $product = $products->create(
            $category->targetId,
            'premium',
            'محصول ویژه',
            'Premium',
            null,
            null,
            20,
            $this->context($ownerId, 'catalog-product-create-0001'),
        );
        $products->activate(
            $product->targetId,
            1,
            $this->context($ownerId, 'catalog-product-activate-0001'),
        );
        $products->show(
            $product->targetId,
            2,
            $this->context($ownerId, 'catalog-product-show-000001'),
        );

        $variant = $variants->create(
            $product->targetId,
            'one-month',
            'VPN-PREMIUM-30D',
            'یک ماهه',
            'One month',
            null,
            null,
            5,
            $this->context($ownerId, 'catalog-variant-create-0001'),
        );
        $variants->activate(
            $variant->targetId,
            1,
            $this->context($ownerId, 'catalog-variant-activate-001'),
        );

        $this->assertDomainFailure(fn () => $categories->archive(
            $category->targetId,
            2,
            $this->context($ownerId, 'catalog-category-archive-0001'),
        ));
        $this->assertDomainFailure(fn () => $products->archive(
            $product->targetId,
            3,
            $this->context($ownerId, 'catalog-product-archive-0001'),
        ));

        $products->hide(
            $product->targetId,
            3,
            $this->context($ownerId, 'catalog-product-hide-000001'),
        );
        $this->assertDomainFailure(fn () => $products->archive(
            $product->targetId,
            4,
            $this->context($ownerId, 'catalog-product-archive-0002'),
        ));
        $variants->archive(
            $variant->targetId,
            2,
            $this->context($ownerId, 'catalog-variant-archive-0001'),
        );
        $products->archive(
            $product->targetId,
            4,
            $this->context($ownerId, 'catalog-product-archive-0003'),
        );
        $categories->archive(
            $category->targetId,
            2,
            $this->context($ownerId, 'catalog-category-archive-0002'),
        );

        self::assertSame('archived', DB::table('product_categories')->where('id', $category->targetId)->value('state'));
        self::assertSame('archived', DB::table('products')->where('id', $product->targetId)->value('state'));
        self::assertSame('hidden', DB::table('products')->where('id', $product->targetId)->value('visibility'));
        self::assertSame('archived', DB::table('product_variants')->where('id', $variant->targetId)->value('state'));
        self::assertSame(3, DB::table('product_category_histories')->where('category_id', $category->targetId)->count());
        self::assertSame(5, DB::table('product_histories')->where('product_id', $product->targetId)->count());
        self::assertSame(3, DB::table('product_variant_histories')->where('variant_id', $variant->targetId)->count());

        $audit = (string) DB::table('audit_logs')
            ->where('action', 'catalog.category.create')
            ->value('after_safe_data');
        self::assertStringNotContainsString('catalog-sensitive-marker', $audit);
        self::assertStringNotContainsString('سرویس ویژه', $audit);
    }

    public function test_cycle_visibility_category_move_version_and_fingerprint_conflicts_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $categories = $this->app->make(ProductCategoryService::class);
        $products = $this->app->make(ProductService::class);

        $root = $categories->create(
            'root', 'ریشه', null, null, null, null, 1,
            $this->context($ownerId, 'catalog-cycle-root-create-01'),
        );
        $child = $categories->create(
            'child', 'فرزند', null, null, null, $root->targetId, 1,
            $this->context($ownerId, 'catalog-cycle-child-create1'),
        );

        $this->assertDomainFailure(fn () => $categories->update(
            $root->targetId,
            1,
            'ریشه',
            null,
            null,
            null,
            $child->targetId,
            1,
            $this->context($ownerId, 'catalog-cycle-move-request1'),
        ));

        $product = $products->create(
            $root->targetId,
            'draft-product',
            'محصول پیش‌نویس',
            null,
            null,
            null,
            1,
            $this->context($ownerId, 'catalog-draft-product-create'),
        );
        $this->assertDomainFailure(fn () => $products->activate(
            $product->targetId,
            1,
            $this->context($ownerId, 'catalog-draft-product-active'),
        ));

        try {
            $categories->update(
                $root->targetId,
                99,
                'ریشه جدید',
                null,
                null,
                null,
                null,
                1,
                $this->context($ownerId, 'catalog-version-conflict-001'),
            );
            self::fail('Expected optimistic version conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Catalog version conflict.', $exception->getMessage());
        }

        $context = $this->context($ownerId, 'catalog-fingerprint-conflict');
        $categories->update($root->targetId, 1, 'ریشه جدید', null, null, null, null, 1, $context);
        try {
            $categories->update($root->targetId, 1, 'مقدار متفاوت', null, null, null, null, 1, $context);
            self::fail('Expected catalog fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Catalog mutation fingerprint conflict.', $exception->getMessage());
        }
    }

    public function test_seeded_sales_role_can_manage_catalog_and_unauthorized_admin_cannot_mutate(): void
    {
        $ownerId = $this->administrator(true);
        $salesId = $this->administrator();
        $unauthorizedId = $this->administrator();
        $roleId = (int) DB::table('roles')->where('code', 'sales_content')->value('id');
        $now = now('UTC');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $salesId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $ownerId,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->app->make(AdministratorPermissionAuthorizer::class)->authorize($salesId, 'catalog.manage');
        $service = $this->app->make(ProductCategoryService::class);
        $created = $service->create(
            'sales-category',
            'فروش',
            null,
            null,
            null,
            null,
            0,
            $this->context($salesId, 'catalog-sales-create-00001'),
        );
        self::assertSame('sales-category', DB::table('product_categories')->where('id', $created->targetId)->value('code'));

        try {
            $service->create(
                'unauthorized-category',
                'غیرمجاز',
                null,
                null,
                null,
                null,
                0,
                $this->context($unauthorizedId, 'catalog-unauthorized-0001'),
            );
            self::fail('Expected catalog authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('product_categories')->where('code', 'unauthorized-category')->count());
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', 'catalog-unauthorized-0001')->count());
        }
    }

    private function assertDomainFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected catalog domain failure.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    private function administrator(bool $owner = false): int
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
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'catalog_test_change',
            'Catalog lifecycle test change.',
            $administratorId,
        );
    }
}
