<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement BUY-001 BUY-003 CAT-002 CAT-003 CAT-008 DAT-002 DAT-003 SEC-002 QUA-001 */
final class TelegramCustomerPurchaseCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
    }

    public function test_projection_reuses_customer_eligibility_operational_capacity_and_creates_no_purchase_effect(): void
    {
        $scenario = $this->scenario();
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $before = $this->businessEffectCounts();

        $page = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);

        self::assertSame(1, $page->totalItems);
        self::assertCount(1, $page->items);
        $offering = $page->items[0];
        self::assertSame('purchase-standard', $offering->offeringCode);
        self::assertSame('دسته خرید', $offering->categoryNameFa);
        self::assertSame('Purchase category', $offering->categoryNameEn);
        self::assertSame('سرویس استاندارد', $offering->productNameFa);
        self::assertSame('Standard service', $offering->productNameEn);
        self::assertSame('استاندارد', $offering->serviceModeLabelFa);
        self::assertSame(900_000, $offering->basePriceIrr);
        self::assertSame(30, $offering->durationDays);
        self::assertSame(50 * 1024 * 1024 * 1024, $offering->dataAllowanceBytes);
        self::assertSame(2, $offering->deviceLimit);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $offering->selectionToken);

        $resolved = $catalog->offeringForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $offering->selectionToken,
        );
        self::assertSame($offering->selectionToken, $resolved->selectionToken);
        self::assertSame($before, $this->businessEffectCounts());

        DB::table('panel_target_capacities')->where('id', $scenario['capacity_id'])->update([
            'held_units' => 1,
            'version' => 2,
            'updated_at' => now('UTC'),
        ]);
        $full = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
        self::assertSame(0, $full->totalItems);
        self::assertSame([], $full->items);
        self::assertSame($before, $this->businessEffectCounts());
    }

    public function test_quote_contract_creates_one_canonical_zero_discount_quote_and_replays_without_other_purchase_effects(): void
    {
        $scenario = $this->scenario();
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $offering = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6)->items[0];
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $before = $this->businessEffectCounts();
        $callbackPublicId = (string) Str::ulid();
        $acceptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $preview = $quotes->quoteForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $offering->selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );

        self::assertSame('purchase-standard', $preview->offering->offeringCode);
        self::assertSame(900_000, $preview->basePriceIrr);
        self::assertSame(900_000, $preview->effectivePriceIrr);
        self::assertSame(0, $preview->discountIrr);
        self::assertSame(900_000, $preview->finalPriceIrr);
        self::assertSame('IRR', $preview->currency);
        self::assertFalse($preview->replayed);
        self::assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $preview->quotePublicId);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $preview->configurationSnapshotHash);
        self::assertGreaterThan($preview->validFrom, $preview->expiresAt);
        self::assertSame(900, $preview->expiresAt->getTimestamp() - $acceptedAt->getTimestamp());
        self::assertGreaterThanOrEqual(899, $preview->expiresAt->getTimestamp() - $preview->validFrom->getTimestamp());
        self::assertLessThanOrEqual(900, $preview->expiresAt->getTimestamp() - $preview->validFrom->getTimestamp());

        $afterCreate = $this->businessEffectCounts();
        $expected = $before;
        $expected['quotes']++;
        self::assertSame($expected, $afterCreate);

        $replay = $quotes->quoteForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $offering->selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($preview->quotePublicId, $replay->quotePublicId);
        self::assertSame($preview->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertSame($expected, $this->businessEffectCounts());

        DB::table('customer_tag_assignments')
            ->where('user_id', $scenario['user_id'])
            ->where('tag_id', $scenario['eligible_tag_id'])
            ->update(['removed_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $staleCallbackPublicId = (string) Str::ulid();
        try {
            $quotes->quoteForSelf(
                $scenario['user_id'],
                $scenario['user_id'],
                $offering->selectionToken,
                $acceptedAt,
                'telegram-purchase-quote:'.$staleCallbackPublicId,
                'tg-purchase-quote:'.$staleCallbackPublicId,
            );
            self::fail('Expected stale purchase Quote selection to fail after eligibility revocation.');
        } catch (AuthorizationException) {
            self::assertSame($expected, $this->businessEffectCounts());
        }
    }

    public function test_projection_reauthorizes_current_actor_tier_tag_and_account_state(): void
    {
        $scenario = $this->scenario();
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $page = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
        $selection = $page->items[0]->selectionToken;

        DB::table('customer_tag_assignments')
            ->where('user_id', $scenario['user_id'])
            ->where('tag_id', $scenario['eligible_tag_id'])
            ->update(['removed_at' => now('UTC'), 'updated_at' => now('UTC')]);
        self::assertSame(0, $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6)->totalItems);

        try {
            $catalog->offeringForSelf($scenario['user_id'], $scenario['user_id'], $selection);
            self::fail('Expected stale purchase selection to fail after tag revocation.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Telegram purchase offering is unavailable for this actor.', $exception->getMessage());
        }

        DB::table('users')->where('id', $scenario['user_id'])->update([
            'account_status' => 'blocked',
            'updated_at' => now('UTC'),
        ]);
        $this->expectException(AuthorizationException::class);
        $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
    }

    public function test_cross_actor_and_non_customer_access_fail_closed(): void
    {
        $scenario = $this->scenario();
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);

        try {
            $catalog->pageForSelf($scenario['user_id'] + 1, $scenario['user_id'], 1, 6);
            self::fail('Expected cross-actor purchase catalog denial.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Telegram purchase catalog self access denied.', $exception->getMessage());
        }

        DB::table('users')->where('id', $scenario['user_id'])->update([
            'account_type' => 'agent',
            'updated_at' => now('UTC'),
        ]);
        $this->expectException(AuthorizationException::class);
        $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
    }

    /** @return array{user_id:int,eligible_tag_id:int,capacity_id:int} */
    private function scenario(): array
    {
        $now = now('UTC');
        $administratorUserId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $administratorUserId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

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
        $eligibleTagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'purchase-eligible',
            'name_translation_key' => 'customer_tags.purchase_eligible',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherTagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => 'purchase-other',
            'name_translation_key' => 'customer_tags.purchase_other',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $eligibleTagId,
            'assigned_by_administrator_id' => $administratorId,
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'purchase-category',
            'name_fa' => 'دسته خرید',
            'name_en' => 'Purchase category',
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
            'code' => 'purchase-product',
            'name_fa' => 'سرویس استاندارد',
            'name_en' => 'Standard service',
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
            'code' => 'purchase-server',
            'name_fa' => 'سرور خرید',
            'name_en' => 'Purchase server',
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'visibility' => 'listed',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'purchase-panel',
            'provider_type' => 'fake',
            'name_fa' => 'پنل خرید',
            'name_en' => 'Purchase panel',
            'base_url' => 'https://purchase-panel.example.test',
            'encrypted_credentials' => 'test-ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'test-v1',
            'last_capabilities_hash' => hash('sha256', 'purchase-panel-capabilities'),
            'last_tested_at' => $now,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => 'purchase-target',
            'kind' => 'inbound',
            'name_fa' => 'هدف خرید',
            'name_en' => 'Purchase target',
            'encrypted_configuration' => 'test-target-ciphertext',
            'configuration_hash' => hash('sha256', 'purchase-target-config'),
            'configuration_key_version' => 1,
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => hash('sha256', 'purchase-target-capabilities'),
            'capability_verified_at' => $now,
            'verified_connection_version' => 1,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $profileId = (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => 'purchase-profile',
            'name_fa' => 'پروفایل خرید',
            'name_en' => 'Purchase profile',
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/purchase',
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
            'customer_selectable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $capacityId = (int) DB::table('panel_target_capacities')->insertGetId([
            'panel_service_target_id' => $targetId,
            'hard_limit' => 1,
            'held_units' => 0,
            'committed_units' => 0,
            'state' => 'enabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eligibleOfferingId = $this->offering(
            'purchase-standard',
            $productId,
            $serverId,
            $targetId,
            $profileId,
            $eligibleTagId,
            'normal',
            $now,
        );
        $this->route($eligibleOfferingId, $serverId, $targetId, $now);
        DB::table('plan_offerings')->where('id', $eligibleOfferingId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'version' => 2,
            'updated_at' => $now,
        ]);

        DB::table('plan_offering_histories')->insert([
            'plan_offering_id' => $eligibleOfferingId,
            'version' => 2,
            'action' => 'activated',
            'from_state' => 'draft',
            'to_state' => 'active',
            'from_visibility' => 'hidden',
            'to_visibility' => 'visible',
            'from_configuration_hash' => hash('sha256', 'purchase-standard-config-v1'),
            'to_configuration_hash' => hash('sha256', 'purchase-standard-config-v2'),
            'before_safe_data' => json_encode(['state' => 'draft', 'visibility' => 'hidden'], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode(['state' => 'active', 'visibility' => 'visible'], JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $administratorId,
            'reason_code' => 'test_fixture_activation',
            'reason' => 'Canonical purchase Quote fixture snapshot.',
            'correlation_id' => 'telegram-purchase-quote-fixture',
            'created_at' => $now,
        ]);

        $ineligibleOfferingId = $this->offering(
            'purchase-tag-denied',
            $productId,
            $serverId,
            $targetId,
            $profileId,
            $otherTagId,
            'normal',
            $now,
        );
        $this->route($ineligibleOfferingId, $serverId, $targetId, $now);
        DB::table('plan_offerings')->where('id', $ineligibleOfferingId)->update([
            'state' => 'active',
            'visibility' => 'visible',
            'version' => 2,
            'updated_at' => $now,
        ]);

        return [
            'user_id' => $userId,
            'eligible_tag_id' => $eligibleTagId,
            'capacity_id' => $capacityId,
        ];
    }

    private function offering(
        string $code,
        int $productId,
        int $serverId,
        int $targetId,
        int $profileId,
        int $tagId,
        string $tierCode,
        mixed $now,
    ): int {
        $offeringId = (int) DB::table('plan_offerings')->insertGetId([
            'code' => $code,
            'product_id' => $productId,
            'variant_id' => null,
            'sales_server_id' => $serverId,
            'panel_service_target_id' => $targetId,
            'service_mode_code' => 'standard',
            'service_mode_label_fa' => 'استاندارد',
            'service_mode_label_en' => 'Standard',
            'audience' => 'customers',
            'server_selection_mode' => 'system_selects',
            'protocol_selection_mode' => 'fixed',
            'tag_match_mode' => 'all',
            'base_price_irr' => 900_000,
            'duration_days' => 30,
            'data_allowance_bytes' => 50 * 1024 * 1024 * 1024,
            'device_limit' => 2,
            'sort_order' => 0,
            'min_purchase_quantity' => 1,
            'max_purchase_quantity' => 1,
            'discount_eligible' => true,
            'auto_renew_allowed' => false,
            'custom_plan_allowed' => false,
            'trial_allowed' => false,
            'state' => 'draft',
            'visibility' => 'hidden',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_tiers')->insert([
            'plan_offering_id' => $offeringId,
            'tier_code' => $tierCode,
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

        return $offeringId;
    }

    private function route(int $offeringId, int $serverId, int $targetId, mixed $now): void
    {
        $policyId = (int) DB::table('plan_offering_route_policies')->insertGetId([
            'plan_offering_id' => $offeringId,
            'configuration_hash' => hash('sha256', 'purchase-route-'.$offeringId),
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plan_offering_routes')->insert([
            'plan_offering_route_policy_id' => $policyId,
            'sales_server_id' => $serverId,
            'panel_service_target_id' => $targetId,
            'route_type' => 'primary',
            'priority' => 0,
            'customer_selectable' => false,
            'disclosure_fa' => null,
            'disclosure_en' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string,int> */
    private function businessEffectCounts(): array
    {
        return [
            'quotes' => DB::table('quotes')->count(),
            'route_selections' => DB::table('plan_offering_route_selections')->count(),
            'capacity_reservations' => DB::table('panel_capacity_reservations')->count(),
            'payment_intents' => DB::table('payment_intents')->count(),
            'orders' => DB::table('orders')->count(),
            'services' => DB::table('service_subscriptions')->count(),
        ];
    }
}
