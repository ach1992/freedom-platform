<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait AgentPricingQuoteIntegrationTestSupport
{
    /** @return array{id:int,product_id:int,server_id:int,owner_id:int,dependencies:array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>},service:PlanOfferingService,code:string} */
    private function quoteOffering(int $basePriceIrr = 1_000_000, bool $discountEligible = true): array
    {
        $ownerId = $this->ownerAdministrator();
        $dependencies = $this->quoteDependencies();
        $code = 'agt-quote-'.Str::lower(Str::random(8));
        $service = $this->app->make(PlanOfferingService::class);
        $created = $service->create(
            $this->quoteOfferingDefinition($dependencies, $basePriceIrr, $discountEligible, $code),
            $this->catalogContext($ownerId, 'create-'.$code),
        );

        return [
            'id' => $created->targetId,
            'product_id' => $dependencies['product_id'],
            'server_id' => $dependencies['server_id'],
            'owner_id' => $ownerId,
            'dependencies' => $dependencies,
            'service' => $service,
            'code' => $code,
        ];
    }

    private function activePricingProfile(
        AgentPricingService $service,
        int $administratorId,
        string $profileCode,
        bool $discountCombinationAllowed,
    ): void
    {
        $service->createProfile(
            'quote.profile.create.'.$profileCode,
            $profileCode,
            new AgentPricingProfileDefinition(AgentPricingState::Active, $discountCombinationAllowed),
            $this->pricingContext($administratorId, 'profile-'.$profileCode),
        );
    }

    private function activePricingRule(
        AgentPricingService $service,
        int $administratorId,
        string $profileCode,
        string $ruleCode,
        AgentPricingRuleDefinition $definition,
    ): void
    {
        $service->createRule(
            'quote.rule.create.'.$profileCode.'.'.$ruleCode,
            $profileCode,
            $ruleCode,
            $definition,
            $this->pricingContext($administratorId, 'rule-'.$profileCode.'-'.$ruleCode),
        );
    }

    private function revisePricingRule(
        AgentPricingService $service,
        int $administratorId,
        string $profileCode,
        string $ruleCode,
        AgentPricingRuleDefinition $definition,
        string $suffix,
    ): void
    {
        $service->reviseRule(
            'quote.rule.revise.'.$profileCode.'.'.$suffix,
            $profileCode,
            $ruleCode,
            $definition,
            $this->pricingContext($administratorId, 'rule-revise-'.$profileCode.'-'.$suffix),
        );
    }

    private function agentSubject(string $profileCode, string $profileStatus = 'active', string $accountStatus = 'active'): int
    {
        $now = now('UTC');
        $userId = $this->quoteUser('agent', $accountStatus);
        $applicationId = (int) DB::table('agent_applications')->insertGetId([
            'customer_id' => $userId,
            'active_customer_id' => null,
            'state' => 'approved',
            'claimed_by_administrator_id' => null,
            'decided_by_administrator_id' => null,
            'decision_reason_code' => null,
            'decision_reason' => null,
            'application_version' => 1,
            'submitted_at' => $now,
            'claimed_at' => null,
            'decided_at' => $now,
            'reapply_allowed_at' => null,
            'reapplication_released_at' => null,
            'reapplication_released_by_administrator_id' => null,
            'reapplication_release_reason_code' => null,
            'reapplication_release_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('agent_profiles')->insert([
            'user_id' => $userId,
            'status' => $profileStatus,
            'pricing_profile_code' => $profileCode,
            'approved_application_id' => $applicationId,
            'approved_by_administrator_id' => null,
            'approved_at' => $now,
            'suspended_at' => $profileStatus === 'suspended' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function ownerAdministrator(): int
    {
        $existing = DB::table('administrators')->where('is_owner', true)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->quoteUser('customer'),
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function quoteUser(string $accountType, string $accountStatus = 'active'): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => $accountStatus,
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function pricingContext(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'agent-quote-pricing-request:'.$suffix),
            substr(hash('sha256', 'agent-quote-pricing-correlation:'.$suffix), 0, 64),
            'agent_quote_pricing_test',
            'Agent pricing Quote integration test reason.',
            $administratorId,
        );
    }

    private function catalogContext(int $administratorId, string $suffix): CatalogChangeContext
    {
        return new CatalogChangeContext(
            hash('sha256', 'agent-quote-catalog-request:'.$suffix),
            'agt-quote-'.substr(hash('sha256', $suffix), 0, 24),
            'agent_quote_catalog_test',
            'Agent pricing Quote integration catalog setup.',
            $administratorId,
        );
    }

    /** @return array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>} */
    private function quoteDependencies(): array
    {
        $now = now('UTC');
        $suffix = Str::lower(Str::random(8));
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null, 'code' => 'aq-cat-'.$suffix, 'name_fa' => 'دسته', 'name_en' => null,
            'description_fa' => null, 'description_en' => null, 'state' => 'active', 'sort_order' => 0,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId, 'code' => 'aq-product-'.$suffix, 'name_fa' => 'محصول', 'name_en' => null,
            'description_fa' => null, 'description_en' => null, 'state' => 'active', 'visibility' => 'visible',
            'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'aq-server-'.$suffix, 'name_fa' => 'سرور', 'name_en' => null, 'description_fa' => null,
            'description_en' => null, 'state' => 'disabled', 'visibility' => 'hidden', 'sort_order' => 0,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'aq-connection-'.$suffix, 'provider_type' => 'fake', 'name_fa' => 'پنل', 'name_en' => null,
            'base_url' => 'https://panel.example.com', 'encrypted_credentials' => 'ciphertext', 'credential_key_version' => 1,
            'tls_policy' => 'system_ca', 'custom_ca_disk' => null, 'custom_ca_path' => null,
            'certificate_pin_sha256' => null, 'network_policy' => 'public_only', 'state' => 'disabled',
            'last_test_status' => null, 'last_panel_version' => null, 'last_capabilities_hash' => null,
            'last_tested_at' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId, 'code' => 'aq-target-'.$suffix, 'kind' => 'inbound',
            'name_fa' => 'هدف', 'name_en' => null, 'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'aq-target-'.$suffix), 'configuration_key_version' => 1,
            'state' => 'disabled', 'capability_status' => 'declared', 'capability_evidence_hash' => null,
            'capability_verified_at' => null, 'verified_connection_version' => null, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $profileIds = [];
        foreach (['vless', 'trojan'] as $index => $family) {
            $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
                'code' => 'aq-profile-'.$family.'-'.$suffix, 'name_fa' => 'پروفایل', 'name_en' => null,
                'protocol_family' => $family, 'transport' => 'ws', 'security_layer' => 'tls', 'host' => null,
                'sni' => null, 'path' => '/aq-'.$index, 'port' => 443, 'flow' => null, 'state' => 'active',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $profileIds[] = $profileId;
            DB::table('panel_target_protocol_profiles')->insert([
                'panel_service_target_id' => $targetId, 'panel_protocol_profile_id' => $profileId,
                'customer_selectable' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach (['create_service', 'fetch_status'] as $capability) {
            DB::table('panel_target_capabilities')->insert([
                'panel_service_target_id' => $targetId, 'capability_code' => $capability,
                'verification_status' => 'declared', 'evidence_hash' => null, 'verified_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'aq-tag-'.$suffix, 'name_translation_key' => 'customer_tags.agent_quote',
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['product_id' => $productId, 'server_id' => $serverId, 'target_id' => $targetId, 'tag_id' => $tagId, 'profile_ids' => $profileIds];
    }

    /** @param array{product_id:int,server_id:int,target_id:int,tag_id:int,profile_ids:list<int>} $dependencies */
    private function quoteOfferingDefinition(array $dependencies, int $priceIrr, bool $discountEligible, string $code): PlanOfferingDefinition
    {
        return new PlanOfferingDefinition(
            $code,
            $dependencies['product_id'],
            null,
            $dependencies['server_id'],
            $dependencies['target_id'],
            new PlanOfferingServiceMode('shared', 'اشتراکی', 'Shared'),
            PlanOfferingAudience::Both,
            PlanOfferingServerSelectionMode::Customer,
            PlanOfferingProtocolSelectionMode::Customer,
            PlanOfferingTagMatchMode::All,
            $priceIrr,
            30,
            null,
            3,
            10,
            1,
            3,
            $discountEligible,
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
            [new OfferingOperationPolicy(OfferingOperationCode::Renew, true, true, 0, true, 'create_service')],
            [new OfferingPackageDefinition('aq-extra-10gb', OfferingPackageType::AddData, 'ده گیگابایت', '10 GB', 500_000, null, 10 * 1024 * 1024 * 1024, true, 10)],
        );
    }
}
