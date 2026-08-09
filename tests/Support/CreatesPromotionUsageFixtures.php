<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AccessControl\Application\AccessChangeContext;
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
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Promotions\Application\PromotionResolutionContext;
use App\Modules\Promotions\Application\PromotionResolutionReceipt;
use App\Modules\Promotions\Application\PromotionResolutionRequest;
use App\Modules\Promotions\Application\PromotionRuleService;
use App\Modules\Promotions\Application\PromotionRuleVersionReceipt;
use App\Modules\Promotions\Domain\PromotionAction;
use App\Modules\Promotions\Domain\PromotionAudience;
use App\Modules\Promotions\Domain\PromotionDiscountType;
use App\Modules\Promotions\Domain\PromotionRuleDefinition;
use App\Modules\Promotions\Domain\PromotionRuleKind;
use App\Modules\Promotions\Domain\PromotionRuleState;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesPromotionUsageFixtures
{
    protected function usageUser(string $accountType = 'customer'): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function usageAdministrator(): int
    {
        $existing = DB::table('administrators')
            ->where('is_owner', true)
            ->where('status', 'active')
            ->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->usageUser(),
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{id:int,product_id:int,server_id:int} */
    protected function usageOffering(int $basePriceIrr = 1_000_000, bool $discountEligible = true, string $suffix = 'usage'): array
    {
        $ownerId = $this->usageAdministrator();
        $dependencies = $this->usageOfferingDependencies($suffix);
        $service = $this->app->make(PlanOfferingService::class);
        $code = 'usage-offering-'.substr(hash('sha256', $suffix.Str::random(6)), 0, 12);
        $created = $service->create(
            $this->usageOfferingDefinition($dependencies, $basePriceIrr, $discountEligible, $code),
            new CatalogChangeContext(
                'usage-offering-'.substr(hash('sha256', $suffix), 0, 24),
                'usage-correlation-'.substr(hash('sha256', $suffix), 0, 24),
                'promotion_usage_test',
                'Promotion usage reservation test offering.',
                $ownerId,
            ),
        );

        return [
            'id' => $created->targetId,
            'product_id' => $dependencies['product_id'],
            'server_id' => $dependencies['server_id'],
        ];
    }

    protected function usageRule(
        int $offeringId,
        string $ruleCode,
        int $discountIrr = 100_000,
        ?int $totalUseLimit = null,
        ?int $perUserUseLimit = null,
        PromotionRuleKind $kind = PromotionRuleKind::Promotion,
        ?string $referralSourceCode = null,
        bool $allowsFreeOrder = false,
    ): PromotionRuleVersionReceipt {
        $owner = $this->usageAdministrator();
        $definition = new PromotionRuleDefinition(
            PromotionRuleState::Active,
            10,
            PromotionDiscountType::Fixed,
            $discountIrr,
            null,
            0,
            null,
            null,
            null,
            $totalUseLimit,
            $perUserUseLimit,
            false,
            PromotionAudience::Both,
            null,
            null,
            $offeringId,
            null,
            null,
            PromotionAction::Purchase,
            $referralSourceCode,
            $allowsFreeOrder,
        );

        return $this->app->make(PromotionRuleService::class)->create(
            'usage.rule.'.substr(hash('sha256', $ruleCode.Str::random(6)), 0, 24),
            $ruleCode,
            $kind,
            $definition,
            new AccessChangeContext(
                hash('sha256', 'usage-rule-request:'.$ruleCode),
                substr(hash('sha256', 'usage-rule-correlation:'.$ruleCode), 0, 64),
                'promotion_usage_test',
                'Promotion usage reservation test rule.',
                $owner,
            ),
        );
    }

    protected function usageResolution(
        int $userId,
        int $offeringId,
        int $inputPriceIrr,
        string $suffix,
        ?string $referralSourceCode = null,
    ): PromotionResolutionReceipt {
        return $this->app->make(PromotionRuleService::class)->resolve(
            new PromotionResolutionRequest(
                'usage.resolve.'.substr(hash('sha256', $suffix), 0, 24),
                $userId,
                $offeringId,
                PromotionAction::Purchase,
                $inputPriceIrr,
                0,
                0,
                false,
                $referralSourceCode,
            ),
            new PromotionResolutionContext($userId),
        );
    }

    protected function usageQuote(
        int $userId,
        int $offeringId,
        string $discountReferenceCode,
        int $discountIrr,
        DateTimeImmutable $expiresAt,
        string $suffix,
    ): QuoteReceipt {
        return $this->app->make(QuoteService::class)->create(
            'usage.quote.'.substr(hash('sha256', $suffix), 0, 24),
            $userId,
            $offeringId,
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                $discountReferenceCode,
                $discountIrr,
                $expiresAt,
            ),
            substr(hash('sha256', 'usage-quote:'.$suffix), 0, 64),
        );
    }

    /** @return array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>} */
    private function usageOfferingDependencies(string $suffix): array
    {
        $now = now('UTC');
        $token = substr(hash('sha256', $suffix.Str::random(6)), 0, 10);
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'usage-category-'.$token,
            'name_fa' => 'Usage category',
            'name_en' => 'Usage category',
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
            'code' => 'usage-product-'.$token,
            'name_fa' => 'Usage product',
            'name_en' => 'Usage product',
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
            'code' => 'usage-server-'.$token,
            'name_fa' => 'Usage server',
            'name_en' => 'Usage server',
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
            'code' => 'usage-connection-'.$token,
            'provider_type' => 'fake',
            'name_fa' => 'Usage panel',
            'name_en' => 'Usage panel',
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
            'code' => 'usage-target-'.$token,
            'kind' => 'inbound',
            'name_fa' => 'Usage target',
            'name_en' => 'Usage target',
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'usage-target-'.$token),
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
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'usage-profile-'.$token,
            'name_fa' => 'Usage profile',
            'name_en' => 'Usage profile',
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/usage',
            'port' => 443,
            'flow' => null,
            'state' => 'active',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_target_protocol_profiles')->insert([
            'panel_service_target_id' => $targetId,
            'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
            'code' => 'usage-tag-'.$token,
            'name_translation_key' => 'customer_tags.usage',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'product_id' => $productId,
            'server_id' => $serverId,
            'target_id' => $targetId,
            'tag_id' => $tagId,
            'profile_ids' => [$profileId],
        ];
    }

    /**
     * @param  array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>}  $dependencies
     */
    private function usageOfferingDefinition(array $dependencies, int $basePriceIrr, bool $discountEligible, string $code): PlanOfferingDefinition
    {
        return new PlanOfferingDefinition(
            $code,
            $dependencies['product_id'],
            null,
            $dependencies['server_id'],
            $dependencies['target_id'],
            new PlanOfferingServiceMode('shared', 'Shared', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            $basePriceIrr,
            30,
            null,
            2,
            0,
            1,
            1,
            $discountEligible,
            false,
            false,
            false,
            ['normal'],
            [$dependencies['tag_id']],
            [new OfferingProtocolAssignment($dependencies['profile_ids'][0], true, true)],
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
                    'usage-extra-'.$code,
                    OfferingPackageType::AddData,
                    'Usage package',
                    'Usage package',
                    100_000,
                    null,
                    1024 * 1024 * 1024,
                    true,
                    0,
                ),
            ],
        );
    }
}
