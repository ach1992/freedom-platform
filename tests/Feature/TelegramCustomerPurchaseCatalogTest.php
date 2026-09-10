<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleDefinition;
use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleEffect;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseDiscountQuote;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseOrder;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchasePaymentMethods;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
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
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
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

    public function test_quote_contract_refreshes_display_terms_after_canonical_quote_lock(): void
    {
        $scenario = $this->scenario();
        $realCatalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $current = $realCatalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6)->items[0];
        $stale = new TelegramCustomerPurchaseOffering(
            $current->selectionToken,
            $current->offeringCode,
            $current->categoryNameFa,
            $current->categoryNameEn,
            'سرویس قدیمی',
            'Stale service',
            $current->variantNameFa,
            $current->variantNameEn,
            $current->serviceModeLabelFa,
            $current->serviceModeLabelEn,
            $current->basePriceIrr,
            15,
            $current->dataAllowanceBytes,
            $current->deviceLimit,
        );
        $sequencedCatalog = new class($realCatalog, $stale) implements TelegramCustomerPurchaseCatalog
        {
            public int $offeringCalls = 0;

            public function __construct(
                private readonly TelegramCustomerPurchaseCatalog $delegate,
                private readonly TelegramCustomerPurchaseOffering $stale,
            ) {}

            public function pageForSelf(
                int $actorUserId,
                int $subjectUserId,
                int $page,
                int $pageSize,
            ): TelegramCustomerPurchaseCatalogPage {
                return $this->delegate->pageForSelf($actorUserId, $subjectUserId, $page, $pageSize);
            }

            public function offeringForSelf(
                int $actorUserId,
                int $subjectUserId,
                string $selectionToken,
            ): TelegramCustomerPurchaseOffering {
                $this->offeringCalls++;
                if ($this->offeringCalls === 1) {
                    if (! hash_equals($this->stale->selectionToken, $selectionToken)) {
                        throw new \RuntimeException('Unexpected stale Telegram purchase selection token.');
                    }

                    return $this->stale;
                }

                return $this->delegate->offeringForSelf($actorUserId, $subjectUserId, $selectionToken);
            }
        };
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $sequencedCatalog);
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $callbackPublicId = (string) Str::ulid();
        $acceptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $preview = $quotes->quoteForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $current->selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );

        self::assertSame(2, $sequencedCatalog->offeringCalls);
        self::assertSame('Standard service', $preview->offering->productNameEn);
        self::assertSame(30, $preview->offering->durationDays);
        self::assertNotSame('Stale service', $preview->offering->productNameEn);
        self::assertSame(1, DB::table('quotes')->count());
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

    public function test_cross_actor_and_unsupported_account_access_fail_closed(): void
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
            'account_type' => 'administrator',
            'updated_at' => now('UTC'),
        ]);
        $this->expectException(AuthorizationException::class);
        $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
    }

    public function test_agent_catalog_quote_pricing_replay_and_discount_denial_use_canonical_authorities(): void
    {
        $scenario = $this->scenario();
        $catalog = $this->app->make(TelegramCustomerPurchaseCatalog::class);
        $customerOffering = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6)->items[0];
        self::assertSame(
            substr(hash('sha256', 'telegram-purchase-offering-v1:'.$scenario['user_id'].':purchase-standard'), 0, 40),
            $customerOffering->selectionToken,
        );
        self::assertSame('customer', $customerOffering->accountType);

        $targetId = (int) DB::table('plan_offerings')
            ->where('id', $scenario['eligible_offering_id'])
            ->value('panel_service_target_id');
        $profileId = (int) DB::table('plan_offering_protocol_profiles')
            ->where('plan_offering_id', $scenario['eligible_offering_id'])
            ->value('panel_protocol_profile_id');
        $audienceFixtureNow = now('UTC');
        $customerOnlyOfferingId = $this->offering(
            'purchase-customer-only',
            $scenario['product_id'],
            $scenario['server_id'],
            $targetId,
            $profileId,
            $scenario['eligible_tag_id'],
            'normal',
            $audienceFixtureNow,
            'customers',
        );
        $agentOnlyOfferingId = $this->offering(
            'purchase-agent-only',
            $scenario['product_id'],
            $scenario['server_id'],
            $targetId,
            $profileId,
            $scenario['eligible_tag_id'],
            'normal',
            $audienceFixtureNow,
            'agents',
        );
        foreach ([$customerOnlyOfferingId, $agentOnlyOfferingId] as $offeringId) {
            $this->route($offeringId, $scenario['server_id'], $targetId, $audienceFixtureNow);
            DB::table('plan_offerings')->where('id', $offeringId)->update([
                'state' => 'active',
                'visibility' => 'visible',
                'version' => 2,
                'updated_at' => $audienceFixtureNow,
            ]);
        }
        $customerCodes = array_map(
            static fn (TelegramCustomerPurchaseOffering $item): string => $item->offeringCode,
            $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6)->items,
        );
        sort($customerCodes);
        self::assertSame(['purchase-customer-only', 'purchase-standard'], $customerCodes);

        $pricingProfileCode = 'telegram-agent-purchase-test';
        $pricing = $this->app->make(AgentPricingService::class);
        $context = static fn (string $suffix): AccessChangeContext => new AccessChangeContext(
            hash('sha256', 'telegram-agent-pricing-'.$suffix),
            substr(hash('sha256', 'telegram-agent-pricing-correlation-'.$suffix), 0, 64),
            'telegram_agent_purchase_test',
            'Telegram Agent purchase pricing integration fixture.',
            $scenario['administrator_id'],
        );
        $pricing->createProfile(
            'telegram.agent.profile.create.0001',
            $pricingProfileCode,
            new AgentPricingProfileDefinition(AgentPricingState::Active, false),
            $context('profile'),
        );
        $pricing->createRule(
            'telegram.agent.rule.create.000001',
            $pricingProfileCode,
            'telegram-purchase-specific',
            new AgentPricingRuleDefinition(
                AgentPricingState::Active,
                750_000,
                AgentPricingAction::Purchase,
                $scenario['eligible_offering_id'],
                $scenario['server_id'],
                $scenario['product_id'],
            ),
            $context('rule'),
        );

        $now = now('UTC');
        $applicationId = (int) DB::table('agent_applications')->insertGetId([
            'customer_id' => $scenario['user_id'],
            'active_customer_id' => null,
            'state' => 'approved',
            'claimed_by_administrator_id' => null,
            'decided_by_administrator_id' => $scenario['administrator_id'],
            'decision_reason_code' => 'approved',
            'decision_reason' => 'Approved Agent purchase integration fixture.',
            'application_version' => 1,
            'submitted_at' => $now,
            'claimed_at' => $now,
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
            'user_id' => $scenario['user_id'],
            'status' => 'active',
            'pricing_profile_code' => $pricingProfileCode,
            'approved_application_id' => $applicationId,
            'approved_by_administrator_id' => $scenario['administrator_id'],
            'approved_at' => $now,
            'suspended_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('users')->where('id', $scenario['user_id'])->update([
            'account_type' => 'agent',
            'updated_at' => $now,
        ]);
        $agentPage = $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
        self::assertSame(2, $agentPage->totalItems);
        $agentCodes = array_map(
            static fn (TelegramCustomerPurchaseOffering $item): string => $item->offeringCode,
            $agentPage->items,
        );
        sort($agentCodes);
        self::assertSame(['purchase-agent-only', 'purchase-standard'], $agentCodes);
        $standardAgentOfferings = array_values(array_filter(
            $agentPage->items,
            static fn (TelegramCustomerPurchaseOffering $item): bool => $item->offeringCode === 'purchase-standard',
        ));
        self::assertCount(1, $standardAgentOfferings);
        $agentOffering = $standardAgentOfferings[0];
        self::assertSame('agent', $agentOffering->accountType);
        self::assertNotSame($customerOffering->selectionToken, $agentOffering->selectionToken);
        self::assertSame(
            substr(hash('sha256', 'telegram-purchase-offering-v2:agent:'.$scenario['user_id'].':purchase-standard'), 0, 40),
            $agentOffering->selectionToken,
        );

        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $acceptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $callbackPublicId = (string) Str::ulid();
        $preview = $quotes->quoteForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $agentOffering->selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );
        self::assertSame('agent', $preview->accountType);
        self::assertSame(900_000, $preview->basePriceIrr);
        self::assertSame(750_000, $preview->effectivePriceIrr);
        self::assertSame(750_000, $preview->finalPriceIrr);
        self::assertSame(0, $preview->discountIrr);
        $stored = DB::table('quotes')->where('public_id', $preview->quotePublicId)->first([
            'account_type_snapshot', 'override_source', 'override_price_irr', 'agent_pricing_resolution_id',
        ]);
        self::assertNotNull($stored);
        self::assertSame('agent', (string) $stored->account_type_snapshot);
        self::assertSame('agent', (string) $stored->override_source);
        self::assertSame(750_000, (int) $stored->override_price_irr);
        self::assertGreaterThan(0, (int) $stored->agent_pricing_resolution_id);
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        $replay = $quotes->quoteForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $agentOffering->selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($preview->quotePublicId, $replay->quotePublicId);
        self::assertSame($preview->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertSame(1, DB::table('quotes')->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->count());

        $paymentEligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyPaymentMethod($paymentEligibility, $scenario['administrator_id'], 'wallet', 10);
        $this->configureHealthyPaymentMethod($paymentEligibility, $scenario['administrator_id'], 'agent_blocked', 20);
        $paymentEligibility->configureRule(
            'telegram.agent.payment.rule.000001',
            $scenario['administrator_id'],
            new PaymentEligibilityRuleDefinition(
                'agent_blocked',
                'active_agent_deny',
                true,
                PaymentEligibilityRuleEffect::Deny,
                100,
                requiredAgentStatus: 'active',
            ),
            'Agent payment policy regression.',
            hash('sha256', 'telegram-agent-payment-rule'),
        );
        $paymentMethods = $this->app->make(TelegramCustomerPurchasePaymentMethods::class);

        $alternatePricingProfileCode = 'telegram-agent-purchase-other';
        $pricing->createProfile(
            'telegram.agent.profile.create.0002',
            $alternatePricingProfileCode,
            new AgentPricingProfileDefinition(AgentPricingState::Active, false),
            $context('profile-other'),
        );
        DB::table('agent_profiles')->where('user_id', $scenario['user_id'])->update([
            'pricing_profile_code' => $alternatePricingProfileCode,
            'updated_at' => now('UTC'),
        ]);
        $effectsBeforePricingContextDrift = $this->businessEffectCounts();
        try {
            $quotes->previewForSelf(
                $scenario['user_id'],
                $scenario['user_id'],
                $agentOffering->selectionToken,
                $preview->quotePublicId,
                $preview->configurationSnapshotHash,
            );
            self::fail('Agent pricing-profile drift must invalidate Telegram Quote preview.');
        } catch (AuthorizationException) {
            // Expected: stale Agent pricing context must not remain presentable.
        }
        try {
            $paymentMethods->discoverForSelf(
                $scenario['user_id'],
                $scenario['user_id'],
                $preview->quotePublicId,
                $preview->configurationSnapshotHash,
                'telegram-purchase-payment-methods:'.(string) Str::ulid(),
            );
            self::fail('Agent pricing-profile drift must fail closed before PAY-001 persistence.');
        } catch (AuthorizationException) {
            // Expected: the current Agent pricing context no longer matches the Quote snapshot.
        }
        self::assertSame($effectsBeforePricingContextDrift, $this->businessEffectCounts());
        self::assertSame(0, DB::table('payment_method_eligibility_decisions')->count());
        DB::table('agent_profiles')->where('user_id', $scenario['user_id'])->update([
            'pricing_profile_code' => $pricingProfileCode,
            'updated_at' => now('UTC'),
        ]);

        $paymentDecision = $paymentMethods->discoverForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $preview->quotePublicId,
            $preview->configurationSnapshotHash,
            'telegram-purchase-payment-methods:'.(string) Str::ulid(),
        );
        self::assertSame(['wallet'], $paymentDecision->methodCodes);
        $paymentDecisionId = (int) DB::table('payment_method_eligibility_decisions')
            ->where('public_id', $paymentDecision->decisionPublicId)
            ->value('id');
        self::assertGreaterThan(0, $paymentDecisionId);
        self::assertSame('rule_denied', DB::table('payment_method_eligibility_decision_methods')
            ->where('payment_method_eligibility_decision_id', $paymentDecisionId)
            ->where('method_code', 'agent_blocked')
            ->value('reason_code'));

        $orders = $this->app->make(TelegramCustomerPurchaseOrder::class);
        $order = $orders->openForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $preview->quotePublicId,
            $preview->configurationSnapshotHash,
            'telegram-order:'.(string) Str::ulid(),
        );
        self::assertSame($preview->quotePublicId, $order->sourceQuotePublicId);
        self::assertSame($preview->configurationSnapshotHash, $order->sourceQuoteConfigurationHash);
        self::assertSame(750_000, $order->amountIrr);
        self::assertSame('IRR', $order->currency);
        $orderId = (int) DB::table('orders')->where('public_id', $order->orderPublicId)->value('id');
        self::assertGreaterThan(0, $orderId);
        self::assertSame('awaiting_payment', DB::table('orders')->where('id', $orderId)->value('state'));
        self::assertSame('agent', DB::table('order_items')->where('order_id', $orderId)->value('account_type_snapshot'));
        self::assertSame('agent', DB::table('order_items')->where('order_id', $orderId)->value('override_source'));
        self::assertSame(750_000, (int) DB::table('order_items')->where('order_id', $orderId)->value('final_price_irr'));

        $selection = $paymentMethods->selectForSelf(
            $scenario['user_id'],
            $scenario['user_id'],
            $preview->quotePublicId,
            $preview->configurationSnapshotHash,
            $paymentDecision->decisionPublicId,
            $paymentDecision->configurationSnapshotHash,
            'wallet',
        );
        self::assertSame('wallet', $selection->methodCode);
        self::assertSame($paymentDecision->decisionPublicId, $selection->decisionPublicId);
        self::assertSame($preview->quotePublicId, $selection->sourceQuotePublicId);
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        $discounts = $this->app->make(TelegramCustomerPurchaseDiscountQuote::class);
        $beforeDiscountEffects = $this->businessEffectCounts();
        try {
            $discounts->requoteForSelf(
                $scenario['user_id'],
                $scenario['user_id'],
                $agentOffering->selectionToken,
                $preview->quotePublicId,
                $preview->configurationSnapshotHash,
                'IGNORED-AGENT-CODE',
                $acceptedAt,
                hash('sha256', 'telegram-agent-discount-denied'),
            );
            self::fail('Expected Agent Benefit Code continuation to fail closed.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Benefit codes are unavailable for Agent purchase Quotes.', $exception->getMessage());
        }
        self::assertSame($beforeDiscountEffects, $this->businessEffectCounts());

        DB::table('agent_profiles')->where('user_id', $scenario['user_id'])->update([
            'status' => 'suspended',
            'suspended_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        try {
            $paymentMethods->selectForSelf(
                $scenario['user_id'],
                $scenario['user_id'],
                $preview->quotePublicId,
                $preview->configurationSnapshotHash,
                $paymentDecision->decisionPublicId,
                $paymentDecision->configurationSnapshotHash,
                'wallet',
            );
            self::fail('Expected PAY-001 replay to deny a suspended Agent before financial effects.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Payment eligibility decision access denied.', $exception->getMessage());
        }
        self::assertSame(1, DB::table('payment_method_eligibility_decisions')->count());
        self::assertSame(1, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('purchase_settlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());

        $beforeDenied = $this->businessEffectCounts();
        try {
            $catalog->pageForSelf($scenario['user_id'], $scenario['user_id'], 1, 6);
            self::fail('Expected suspended Agent Catalog access to fail closed.');
        } catch (\DomainException $exception) {
            self::assertSame('Route selection requires an active agent profile.', $exception->getMessage());
        }
        self::assertSame($beforeDenied, $this->businessEffectCounts());
    }

    private function configureHealthyPaymentMethod(
        PaymentMethodEligibilityService $service,
        int $administratorId,
        string $methodCode,
        int $priority,
    ): void {
        $service->configureMethod(
            'telegram.agent.payment.method.'.$methodCode,
            $administratorId,
            $methodCode,
            true,
            false,
            $priority,
            'Telegram Agent payment regression method.',
            hash('sha256', 'telegram-agent-payment-method-'.$methodCode),
        );
        $service->recordHealth(
            'telegram.agent.payment.health.'.$methodCode,
            $administratorId,
            $methodCode,
            true,
            new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC')),
            'Healthy Telegram Agent payment regression method.',
            hash('sha256', 'telegram-agent-payment-health-'.$methodCode),
        );
    }

    /** @return array{user_id:int,administrator_id:int,eligible_tag_id:int,capacity_id:int,eligible_offering_id:int,product_id:int,server_id:int} */
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
            'both',
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
            'administrator_id' => $administratorId,
            'eligible_tag_id' => $eligibleTagId,
            'capacity_id' => $capacityId,
            'eligible_offering_id' => $eligibleOfferingId,
            'product_id' => $productId,
            'server_id' => $serverId,
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
        string $audience = 'customers',
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
            'audience' => $audience,
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
