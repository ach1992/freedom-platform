<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Agents\Application\AgentPricingProfileVersionReceipt;
use App\Modules\Agents\Application\AgentPricingResolutionRequest;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

trait AgentPricingResolutionTestSupport
{
    private function createProfile(AgentPricingService $service, int $administratorId, string $profileCode, bool $discountCombinationAllowed): AgentPricingProfileVersionReceipt
    {
        return $service->createProfile('agt.profile.create.'.$profileCode, $profileCode, new AgentPricingProfileDefinition(AgentPricingState::Active, $discountCombinationAllowed), $this->context($administratorId, 'profile-'.$profileCode));
    }

    private function request(string $key, int $userId, string $profileCode, int $offeringId): AgentPricingResolutionRequest
    {
        return new AgentPricingResolutionRequest($key, $userId, $profileCode, $offeringId, AgentPricingAction::Purchase);
    }

    /** @return array{id:int,product_id:int,server_id:int} */
    private function offering(int $priceIrr): array
    {
        $now = now('UTC');
        $suffix = Str::lower(Str::random(8));
        $categoryId = (int) DB::table('product_categories')->insertGetId(['parent_id' => null, 'code' => 'agt-cat-'.$suffix, 'name_fa' => 'دسته', 'name_en' => null, 'description_fa' => null, 'description_en' => null, 'state' => 'active', 'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $productId = (int) DB::table('products')->insertGetId(['category_id' => $categoryId, 'code' => 'agt-product-'.$suffix, 'name_fa' => 'محصول', 'name_en' => null, 'description_fa' => null, 'description_en' => null, 'state' => 'active', 'visibility' => 'visible', 'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $serverId = (int) DB::table('sales_servers')->insertGetId(['code' => 'agt-server-'.$suffix, 'name_fa' => 'سرور', 'name_en' => null, 'description_fa' => null, 'description_en' => null, 'state' => 'disabled', 'visibility' => 'hidden', 'sort_order' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId(['code' => 'agt-connection-'.$suffix, 'provider_type' => 'fake', 'name_fa' => 'پنل', 'name_en' => null, 'base_url' => 'https://panel.example.com', 'encrypted_credentials' => 'ciphertext', 'credential_key_version' => 1, 'tls_policy' => 'system_ca', 'custom_ca_disk' => null, 'custom_ca_path' => null, 'certificate_pin_sha256' => null, 'network_policy' => 'public_only', 'state' => 'disabled', 'last_test_status' => null, 'last_panel_version' => null, 'last_capabilities_hash' => null, 'last_tested_at' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId(['panel_connection_id' => $connectionId, 'code' => 'agt-target-'.$suffix, 'kind' => 'inbound', 'name_fa' => 'هدف', 'name_en' => null, 'encrypted_configuration' => 'ciphertext', 'configuration_hash' => hash('sha256', 'agt-target-'.$suffix), 'configuration_key_version' => 1, 'state' => 'disabled', 'capability_status' => 'declared', 'capability_evidence_hash' => null, 'capability_verified_at' => null, 'verified_connection_version' => null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $offeringId = (int) DB::table('plan_offerings')->insertGetId(['code' => 'agt-offering-'.$suffix, 'product_id' => $productId, 'variant_id' => null, 'sales_server_id' => $serverId, 'panel_service_target_id' => $targetId, 'service_mode_code' => 'standard', 'service_mode_label_fa' => 'استاندارد', 'service_mode_label_en' => 'Standard', 'audience' => 'both', 'server_selection_mode' => 'system_selects', 'protocol_selection_mode' => 'fixed', 'tag_match_mode' => 'all', 'base_price_irr' => $priceIrr, 'duration_days' => 30, 'data_allowance_bytes' => null, 'device_limit' => null, 'sort_order' => 0, 'min_purchase_quantity' => 1, 'max_purchase_quantity' => 1, 'discount_eligible' => true, 'auto_renew_allowed' => false, 'custom_plan_allowed' => false, 'trial_allowed' => false, 'state' => 'draft', 'visibility' => 'hidden', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);

        return ['id' => $offeringId, 'product_id' => $productId, 'server_id' => $serverId];
    }

    private function agent(string $profileCode, string $profileStatus = 'active'): int
    {
        $now = now('UTC');
        $userId = $this->user('agent');
        $applicationId = (int) DB::table('agent_applications')->insertGetId(['customer_id' => $userId, 'active_customer_id' => null, 'state' => 'approved', 'claimed_by_administrator_id' => null, 'decided_by_administrator_id' => null, 'decision_reason_code' => null, 'decision_reason' => null, 'application_version' => 1, 'submitted_at' => $now, 'claimed_at' => null, 'decided_at' => $now, 'reapply_allowed_at' => null, 'reapplication_released_at' => null, 'reapplication_released_by_administrator_id' => null, 'reapplication_release_reason_code' => null, 'reapplication_release_reason' => null, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('agent_profiles')->insert(['user_id' => $userId, 'status' => $profileStatus, 'pricing_profile_code' => $profileCode, 'approved_application_id' => $applicationId, 'approved_by_administrator_id' => null, 'approved_at' => $now, 'suspended_at' => $profileStatus === 'suspended' ? $now : null, 'created_at' => $now, 'updated_at' => $now]);

        return $userId;
    }

    private function administrator(bool $owner = false, ?string $roleCode = null): int
    {
        $now = now('UTC');
        $id = (int) DB::table('administrators')->insertGetId(['user_id' => $this->user('customer'), 'status' => 'active', 'is_owner' => $owner, 'permission_version' => 1, 'last_authenticated_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        if ($roleCode !== null) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            DB::table('administrator_role_assignments')->insert(['administrator_id' => $id, 'role_id' => (int) $roleId, 'granted_by_administrator_id' => null, 'granted_at' => $now, 'revoked_at' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        return $id;
    }

    private function user(string $accountType): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId(['public_id' => (string) Str::ulid(), 'account_type' => $accountType, 'account_status' => 'active', 'locale' => 'fa', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function context(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(hash('sha256', 'agent-pricing-request:'.$suffix), substr(hash('sha256', 'agent-pricing-correlation:'.$suffix), 0, 64), 'agent_pricing_test', 'Agent pricing resolution test reason.', $administratorId);
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected DomainException was not thrown.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization denial was not thrown.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function assertInvalidArgument(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected MariaDB query rejection was not thrown.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
