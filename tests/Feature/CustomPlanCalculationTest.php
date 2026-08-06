<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\CustomPlanCalculator;
use App\Modules\Catalog\Application\CustomPlanContext;
use App\Modules\Catalog\Application\CustomPlanPolicyService;
use App\Modules\Catalog\Application\CustomPlanRequest;
use App\Modules\Catalog\Domain\CustomPlanActorType;
use App\Modules\Catalog\Domain\CustomPlanPolicyDefinition;
use App\Modules\Catalog\Domain\CustomPlanPricing;
use App\Modules\Catalog\Domain\CustomPlanTagMatchMode;
use App\Modules\Catalog\Domain\CustomPlanUsernameMode;
use App\Modules\Catalog\Domain\CustomPlanValidationStage;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-005 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 */
final class CustomPlanCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
    }

    public function test_policy_calculation_and_two_stage_revalidation_are_replay_safe(): void
    {
        $scenario = $this->scenario();
        $policyService = $this->app->make(CustomPlanPolicyService::class);
        $definition = $this->definition($scenario['tag_id']);
        $policyContext = $this->catalogContext($scenario['owner_id'], 'custom-plan-policy-create-0001');
        $created = $policyService->create($scenario['offering_id'], $definition, $policyContext);
        $replay = $policyService->create($scenario['offering_id'], $definition, $policyContext);
        self::assertTrue($created->changed);
        self::assertTrue($replay->replayed);
        self::assertSame(1, DB::table('custom_plan_policies')->count());
        self::assertSame(1, DB::table('custom_plan_policy_histories')->count());

        $this->activateOffering($scenario['offering_id']);
        $calculator = $this->app->make(CustomPlanCalculator::class);
        $request = new CustomPlanRequest(
            $scenario['offering_id'],
            $scenario['user_id'],
            CustomPlanActorType::Customer,
            20,
            60,
            'Customer_01',
        );
        $calculationContext = new CustomPlanContext(
            'custom-plan-calculate-command-0001',
            'custom-plan-correlation-0001',
            'telegram',
            'initial_calculation',
        );
        $calculation = $calculator->calculate($request, $calculationContext);
        $calculationReplay = $calculator->calculate($request, $calculationContext);
        self::assertSame('customer_01', $calculation->normalizedUsername);
        self::assertSame(1_000, $calculation->basePriceIrr);
        self::assertSame(2_000, $calculation->dataPriceIrr);
        self::assertSame(600, $calculation->dayPriceIrr);
        self::assertSame(3_600, $calculation->subtotalIrr);
        self::assertSame(0, $calculation->minimumAdjustmentIrr);
        self::assertSame(3_600, $calculation->finalPriceIrr);
        self::assertTrue($calculationReplay->replayed);
        self::assertSame($calculation->calculationId, $calculationReplay->calculationId);

        try {
            $calculator->calculate(
                new CustomPlanRequest(
                    $scenario['offering_id'],
                    $scenario['user_id'],
                    CustomPlanActorType::Customer,
                    30,
                    60,
                    'Customer_01',
                ),
                $calculationContext,
            );
            self::fail('Expected calculation command conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Custom-plan command key conflict.', $exception->getMessage());
        }

        $prePaymentContext = new CustomPlanContext(
            'custom-plan-pre-payment-command-01',
            'custom-plan-correlation-0002',
            'quote',
            'pre_payment_revalidation',
        );
        $prePayment = $calculator->revalidate(
            $calculation->calculationId,
            CustomPlanValidationStage::PrePayment,
            $prePaymentContext,
        );
        self::assertTrue($calculator->revalidate(
            $calculation->calculationId,
            CustomPlanValidationStage::PrePayment,
            $prePaymentContext,
        )->replayed);
        self::assertSame('pre_payment', $prePayment->stage);

        $now = now('UTC');
        DB::table('service_username_registry')->insert([
            'normalized_username' => $calculation->normalizedUsername,
            'active_normalized_username' => $calculation->normalizedUsername,
            'state' => 'active',
            'custom_plan_calculation_id' => $calculation->calculationId,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $preProvisioning = $calculator->revalidate(
            $calculation->calculationId,
            CustomPlanValidationStage::PreProvisioning,
            new CustomPlanContext(
                'custom-plan-pre-provision-command-1',
                'custom-plan-correlation-0003',
                'provisioning',
                'pre_provisioning_revalidation',
            ),
        );
        self::assertSame('pre_provisioning', $preProvisioning->stage);
        self::assertSame(2, DB::table('custom_plan_calculation_validations')->count());

        try {
            DB::table('custom_plan_calculations')
                ->where('id', $calculation->calculationId)
                ->update(['final_price_irr' => 1]);
            self::fail('Expected immutable custom-plan calculation rejection.');
        } catch (QueryException) {
            self::assertSame(3_600, (int) DB::table('custom_plan_calculations')
                ->where('id', $calculation->calculationId)
                ->value('final_price_irr'));
        }
    }

    public function test_range_and_username_availability_fail_closed(): void
    {
        $scenario = $this->scenario();
        $this->app->make(CustomPlanPolicyService::class)->create(
            $scenario['offering_id'],
            $this->definition($scenario['tag_id']),
            $this->catalogContext($scenario['owner_id'], 'custom-plan-policy-create-0002'),
        );
        $this->activateOffering($scenario['offering_id']);
        $calculator = $this->app->make(CustomPlanCalculator::class);

        try {
            $calculator->calculate(
                new CustomPlanRequest(
                    $scenario['offering_id'],
                    $scenario['user_id'],
                    CustomPlanActorType::Customer,
                    25,
                    60,
                    'available_01',
                ),
                new CustomPlanContext(
                    'custom-plan-range-command-000001',
                    'custom-plan-correlation-0004',
                    'telegram',
                    'range_validation',
                ),
            );
            self::fail('Expected range-and-step rejection.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('range and step', $exception->getMessage());
        }

        $now = now('UTC');
        DB::table('service_username_registry')->insert([
            'normalized_username' => 'allocated_01',
            'active_normalized_username' => 'allocated_01',
            'state' => 'active',
            'custom_plan_calculation_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Service username is unavailable.');
        $calculator->calculate(
            new CustomPlanRequest(
                $scenario['offering_id'],
                $scenario['user_id'],
                CustomPlanActorType::Customer,
                20,
                60,
                'allocated_01',
            ),
            new CustomPlanContext(
                'custom-plan-username-command-0001',
                'custom-plan-correlation-0005',
                'telegram',
                'username_validation',
            ),
        );
    }

    public function test_direct_invalid_formula_is_rejected(): void
    {
        $scenario = $this->scenario();
        $policy = $this->app->make(CustomPlanPolicyService::class)->create(
            $scenario['offering_id'],
            $this->definition($scenario['tag_id']),
            $this->catalogContext($scenario['owner_id'], 'custom-plan-policy-create-0003'),
        );
        $this->activateOffering($scenario['offering_id']);
        $now = now('UTC');

        $this->expectException(QueryException::class);
        DB::table('custom_plan_calculations')->insert([
            'command_key' => 'custom-plan-direct-invalid-command-1',
            'payload_hash' => hash('sha256', 'payload'),
            'plan_offering_id' => $scenario['offering_id'],
            'custom_plan_policy_id' => $policy->targetId,
            'custom_plan_policy_version' => 1,
            'policy_configuration_hash' => (string) DB::table('custom_plan_policies')->where('id', $policy->targetId)->value('configuration_hash'),
            'user_id' => $scenario['user_id'],
            'actor_type' => 'customer',
            'tier_code_snapshot' => 'normal',
            'eligibility_snapshot_hash' => hash('sha256', 'eligibility'),
            'data_gb' => 20,
            'days' => 60,
            'username_mode' => 'customer_selected',
            'normalized_username' => 'direct_01',
            'base_price_irr' => 1_000,
            'price_per_gb_irr' => 100,
            'price_per_day_irr' => 10,
            'data_price_irr' => 1,
            'day_price_irr' => 600,
            'subtotal_irr' => 1_601,
            'minimum_order_amount_irr' => 2_000,
            'minimum_adjustment_irr' => 399,
            'final_price_irr' => 2_000,
            'discount_eligible' => true,
            'correlation_id' => 'custom-plan-direct-correlation',
            'source_code' => 'test',
            'reason_code' => 'invalid_formula',
            'created_at' => $now,
        ]);
    }

    public function test_unauthorized_administrator_cannot_create_policy(): void
    {
        $scenario = $this->scenario();
        $this->expectException(AuthorizationException::class);
        $this->app->make(CustomPlanPolicyService::class)->create(
            $scenario['offering_id'],
            $this->definition($scenario['tag_id']),
            $this->catalogContext($scenario['administrator_id'], 'custom-plan-policy-denied-0001'),
        );
    }

    /** @return array<string, int> */
    private function scenario(): array
    {
        $now = now('UTC');
        $ownerId = $this->administrator(true);
        $administratorId = $this->administrator(false);
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tierId = (int) DB::table('customer_tiers')->where('code', 'normal')->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'verified',
            'identity_verification_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'custom-plan-tag-'.Str::lower(Str::random(6)),
            'name_translation_key' => 'customer_tags.custom_plan',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_by_administrator_id' => $ownerId,
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'custom-plan-category-'.Str::lower(Str::random(6)),
            'name_fa' => 'Category',
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
            'code' => 'custom-plan-product-'.Str::lower(Str::random(6)),
            'name_fa' => 'Product',
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
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'custom-plan-connection-'.Str::lower(Str::random(6)),
            'provider_type' => 'fake',
            'name_fa' => 'Panel',
            'name_en' => null,
            'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'test',
            'last_capabilities_hash' => hash('sha256', 'capabilities'),
            'last_tested_at' => $now,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => 'custom-plan-target-'.Str::lower(Str::random(6)),
            'kind' => 'inbound',
            'name_fa' => 'Target',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'target'),
            'configuration_key_version' => 1,
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => hash('sha256', 'verified'),
            'capability_verified_at' => $now,
            'verified_connection_version' => 1,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_target_capabilities')->insert([
            'panel_service_target_id' => $targetId,
            'capability_code' => 'create_service',
            'verification_status' => 'verified',
            'evidence_hash' => hash('sha256', 'capability'),
            'verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'custom-plan-profile-'.Str::lower(Str::random(6)),
            'name_fa' => 'Profile',
            'name_en' => null,
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/custom',
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
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'custom-plan-server-'.Str::lower(Str::random(6)),
            'name_fa' => 'Server',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'visibility' => 'listed',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $offeringId = (int) DB::table('plan_offerings')->insertGetId([
            'code' => 'custom-plan-offering-'.Str::lower(Str::random(6)),
            'product_id' => $productId,
            'variant_id' => null,
            'sales_server_id' => $serverId,
            'panel_service_target_id' => $targetId,
            'service_mode_code' => 'shared',
            'service_mode_label_fa' => 'Shared',
            'service_mode_label_en' => null,
            'audience' => 'both',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'fixed',
            'tag_match_mode' => 'all',
            'base_price_irr' => 1_000,
            'duration_days' => 30,
            'data_allowance_bytes' => null,
            'device_limit' => 2,
            'sort_order' => 0,
            'min_purchase_quantity' => 1,
            'max_purchase_quantity' => 1,
            'discount_eligible' => true,
            'auto_renew_allowed' => false,
            'custom_plan_allowed' => true,
            'trial_allowed' => false,
            'state' => 'draft',
            'visibility' => 'hidden',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_tiers')->insert([
            'plan_offering_id' => $offeringId,
            'tier_code' => 'normal',
            'created_at' => $now,
        ]);
        DB::table('plan_offering_tags')->insert([
            'plan_offering_id' => $offeringId,
            'customer_tag_id' => $tagId,
            'created_at' => $now,
        ]);
        DB::table('plan_offering_protocol_profiles')->insert([
            'plan_offering_id' => $offeringId,
            'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => false,
            'is_default' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_required_capabilities')->insert([
            'plan_offering_id' => $offeringId,
            'capability_code' => 'create_service',
            'created_at' => $now,
        ]);

        return [
            'owner_id' => $ownerId,
            'administrator_id' => $administratorId,
            'user_id' => $userId,
            'tag_id' => $tagId,
            'offering_id' => $offeringId,
        ];
    }

    private function activateOffering(int $offeringId): void
    {
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => now('UTC'),
        ]);
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'visibility' => 'visible',
            'version' => 3,
            'updated_at' => now('UTC'),
        ]);
    }

    private function definition(int $tagId): CustomPlanPolicyDefinition
    {
        return new CustomPlanPolicyDefinition(
            true,
            10,
            100,
            10,
            30,
            90,
            30,
            new CustomPlanPricing(1_000, 100, 10, 2_000),
            new CustomPlanPricing(500, 80, 8, 1_500),
            true,
            CustomPlanTagMatchMode::All,
            CustomPlanUsernameMode::CustomerSelected,
            5,
            32,
            ['normal'],
            [$tagId],
            ['_', '-'],
            ['admin', 'support'],
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

    private function catalogContext(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'custom-plan-policy-correlation',
            'custom_plan_policy_change',
            'Custom-plan policy test change.',
            $administratorId,
        );
    }
}
