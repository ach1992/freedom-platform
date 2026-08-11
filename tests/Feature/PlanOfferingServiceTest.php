<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\OfferingOperationCode;
use App\Modules\Catalog\Domain\OfferingOperationPolicy;
use App\Modules\Catalog\Domain\OfferingPackageDefinition;
use App\Modules\Catalog\Domain\OfferingPackageType;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Catalog\Domain\ProductVisibility;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-002 CAT-003 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
final class PlanOfferingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
    }

    public function test_draft_definition_is_replay_safe_versioned_dependency_guarded_and_audited(): void
    {
        $ownerId = $this->administrator(true);
        $dependencies = $this->dependencies();
        $service = $this->app->make(PlanOfferingService::class);
        $context = $this->context($ownerId, 'plan-offering-create-000001');
        $definition = $this->definition($dependencies, 2_500_000);

        $created = $service->create($definition, $context);
        $replay = $service->create($definition, $context);
        self::assertTrue($created->changed);
        self::assertTrue($replay->replayed);
        self::assertSame($created->targetId, $replay->targetId);
        self::assertSame(1, DB::table('plan_offerings')->count());
        self::assertSame(1, DB::table('plan_offering_histories')->count());
        self::assertSame(2, DB::table('plan_offering_protocol_profiles')->count());
        self::assertSame(2, DB::table('plan_offering_required_capabilities')->count());
        self::assertSame(1, DB::table('plan_offering_operations')->count());
        self::assertSame(1, DB::table('plan_offering_packages')->count());

        try {
            $service->create($this->definition($dependencies, 2_600_000), $context);
            self::fail('Expected offering mutation fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Catalog mutation fingerprint conflict.', $exception->getMessage());
        }

        $offeringId = $created->targetId;
        $updated = $service->update(
            $offeringId,
            1,
            $this->definition($dependencies, 2_750_000),
            $this->context($ownerId, 'plan-offering-update-000001'),
        );
        self::assertTrue($updated->changed);
        self::assertSame(2_750_000, DB::table('plan_offerings')->where('id', $offeringId)->value('base_price_irr'));
        self::assertSame(2, DB::table('plan_offerings')->where('id', $offeringId)->value('version'));

        try {
            $service->activate(
                $offeringId,
                2,
                $this->context($ownerId, 'plan-offering-activate-00001'),
            );
            self::fail('Expected fail-closed activation while target evidence is unverified.');
        } catch (DomainException) {
            self::assertSame('draft', DB::table('plan_offerings')->where('id', $offeringId)->value('state'));
        }

        try {
            $service->setVisibility(
                $offeringId,
                2,
                ProductVisibility::Visible,
                $this->context($ownerId, 'plan-offering-visible-000001'),
            );
            self::fail('Expected draft visibility rejection.');
        } catch (DomainException) {
            self::assertSame('hidden', DB::table('plan_offerings')->where('id', $offeringId)->value('visibility'));
        }

        $service->archive(
            $offeringId,
            2,
            $this->context($ownerId, 'plan-offering-archive-000001'),
        );
        self::assertSame('archived', DB::table('plan_offerings')->where('id', $offeringId)->value('state'));
        self::assertSame(3, DB::table('plan_offering_histories')->where('plan_offering_id', $offeringId)->count());

        try {
            $service->update(
                $offeringId,
                3,
                $this->definition($dependencies, 3_000_000),
                $this->context($ownerId, 'plan-offering-update-archived'),
            );
            self::fail('Expected archived offering immutability.');
        } catch (DomainException) {
            self::assertSame(2_750_000, DB::table('plan_offerings')->where('id', $offeringId)->value('base_price_irr'));
        }

        $audit = DB::table('audit_logs')
            ->where('target_type', 'plan_offering')
            ->where('target_id', (string) $offeringId)
            ->get(['before_safe_data', 'after_safe_data'])
            ->map(static fn (object $row): string => (string) $row->before_safe_data.(string) $row->after_safe_data)
            ->implode('\n');
        foreach (['اشتراکی', 'ده گیگابایت', 'Premium shared'] as $excluded) {
            self::assertStringNotContainsString($excluded, $audit);
        }
    }

    public function test_unauthorized_administrator_cannot_create_offering(): void
    {
        $administratorId = $this->administrator(false);
        $dependencies = $this->dependencies();
        $service = $this->app->make(PlanOfferingService::class);

        try {
            $service->create(
                $this->definition($dependencies, 1_000_000),
                $this->context($administratorId, 'plan-offering-unauthorized1'),
            );
            self::fail('Expected offering authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('plan_offerings')->count());
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', 'plan-offering-unauthorized1')->count());
        }
    }

    /** @return array{product_id: int, server_id: int, target_id: int, tag_id: int, profile_ids: list<int>} */
    private function dependencies(): array
    {
        $now = now('UTC');
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'plan-category-'.Str::lower(Str::random(6)),
            'name_fa' => 'دسته',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'code' => 'plan-product-'.Str::lower(Str::random(6)),
            'name_fa' => 'محصول',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'visibility' => 'visible',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'plan-server-'.Str::lower(Str::random(6)),
            'name_fa' => 'سرور',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'disabled',
            'visibility' => 'hidden',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'plan-connection-'.Str::lower(Str::random(6)),
            'provider_type' => 'fake',
            'name_fa' => 'پنل',
            'name_en' => null,
            'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'disabled',
            'last_test_status' => null,
            'last_panel_version' => null,
            'last_capabilities_hash' => null,
            'last_tested_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => 'plan-target-'.Str::lower(Str::random(6)),
            'kind' => 'inbound',
            'name_fa' => 'هدف',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'configuration'),
            'configuration_key_version' => 1,
            'state' => 'disabled',
            'capability_status' => 'declared',
            'capability_evidence_hash' => null,
            'capability_verified_at' => null,
            'verified_connection_version' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $profileIds = [];
        foreach (['vless', 'trojan'] as $index => $family) {
            $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
                'code' => 'plan-profile-'.$family.'-'.Str::lower(Str::random(4)),
                'name_fa' => 'پروفایل',
                'name_en' => null,
                'protocol_family' => $family,
                'transport' => 'ws',
                'security_layer' => 'tls',
                'host' => null,
                'sni' => null,
                'path' => '/vpn-'.$index,
                'port' => 443,
                'flow' => null,
                'state' => 'active',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $profileIds[] = $profileId;
            DB::table('panel_target_protocol_profiles')->insert([
                'panel_service_target_id' => $targetId,
                'panel_protocol_profile_id' => $profileId,
                'customer_selectable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['create_service', 'fetch_status'] as $capability) {
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId,
                'capability_code' => $capability,
                'verification_status' => 'declared',
                'evidence_hash' => null,
                'verified_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'eligible-'.Str::lower(Str::random(6)),
            'name_translation_key' => 'customer_tags.eligible',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'product_id' => $productId,
            'server_id' => $serverId,
            'target_id' => $targetId,
            'tag_id' => $tagId,
            'profile_ids' => $profileIds,
        ];
    }

    /**
     * @param  array{product_id: int, server_id: int, target_id: int, tag_id: int, profile_ids: list<int>}  $dependencies
     */
    private function definition(array $dependencies, int $basePriceIrr): PlanOfferingDefinition
    {
        return new PlanOfferingDefinition(
            'premium-shared',
            $dependencies['product_id'],
            null,
            $dependencies['server_id'],
            $dependencies['target_id'],
            new PlanOfferingServiceMode('shared', 'اشتراکی', 'Premium shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            $basePriceIrr,
            30,
            null,
            3,
            10,
            1,
            3,
            true,
            true,
            false,
            false,
            ['normal', 'loyal', 'vip'],
            [$dependencies['tag_id']],
            [
                new OfferingProtocolAssignment($dependencies['profile_ids'][0], true, true),
                new OfferingProtocolAssignment($dependencies['profile_ids'][1], true, false),
            ],
            ['create_service', 'fetch_status'],
            [
                new OfferingOperationPolicy(
                    OfferingOperationCode::Renew,
                    true,
                    true,
                    0,
                    true,
                    'create_service',
                ),
            ],
            [
                new OfferingPackageDefinition(
                    'extra-10gb',
                    OfferingPackageType::AddData,
                    'ده گیگابایت',
                    '10 GB',
                    500_000,
                    null,
                    10 * 1024 * 1024 * 1024,
                    true,
                    10,
                ),
            ],
        );
    }

    private function administrator(bool $owner): int
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
            'plan_offering_change',
            'Plan offering foundation test change.',
            $administratorId,
        );
    }
}
