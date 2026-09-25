<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramActionMembershipChanged;
use App\Modules\Telegram\Application\TelegramActionMembershipService;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluationDecision;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleDefinition;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseMembershipChanged;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseMembershipPreflight;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class TelegramPurchaseMembershipLookup implements TelegramMembershipLookup
{
    /** @var list<int> */
    public array $transactionLevels = [];

    /** @param Closure(int,int,int):TelegramMembershipLookupResult $callback */
    public function __construct(public Closure $callback) {}

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        $this->transactionLevels[] = DB::connection()->transactionLevel();

        return ($this->callback)($chatId, $telegramUserId, count($this->transactionLevels));
    }
}

final readonly class TelegramPurchaseMembershipRuntime implements ProtectedTelegramDeliveryRuntime
{
    public function botId(): string
    {
        return '123456';
    }
}

final readonly class TelegramPurchaseMembershipCatalog implements TelegramCustomerPurchaseCatalog
{
    public function __construct(
        private string $selectionToken,
        private string $offeringCode,
        private int $basePriceIrr,
        private int $durationDays,
        private string $accountType,
    ) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramCustomerPurchaseCatalogPage
    {
        throw new RuntimeException('Purchase membership verification does not request a catalog page.');
    }

    public function offeringForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramCustomerPurchaseOffering
    {
        if ($actorUserId !== $subjectUserId || ! hash_equals($this->selectionToken, $selectionToken)) {
            throw new RuntimeException('Unexpected purchase membership catalog request.');
        }

        return new TelegramCustomerPurchaseOffering(
            $this->selectionToken,
            $this->offeringCode,
            'دسته خرید',
            'Purchase category',
            'پلن خرید',
            'Purchase plan',
            null,
            null,
            'استاندارد',
            'Standard',
            $this->basePriceIrr,
            $this->durationDays,
            null,
            2,
            $this->accountType,
        );
    }
}

/** @requirement ONB-003 CHN-001 BUY-001 BUY-002 AGT-003 AGT-005 DAT-002 DAT-003 SEC-002 QUA-001 */
final class TelegramCustomerPurchaseMembershipTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    private int $mutationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->app->instance(ProtectedTelegramDeliveryRuntime::class, new TelegramPurchaseMembershipRuntime);
    }

    public function test_satisfied_purchase_membership_runs_provider_outside_transaction_and_allows_exact_quote(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-satisfied');
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000001);
        $this->activeRule($offeringId, 'customers', 'fail_closed', 'purchase-member');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        ));
        $this->bindCatalogAndLookup($catalog, $lookup);

        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $membership->decision);
        self::assertSame([0], $lookup->transactionLevels);
        self::assertTrue($membership->allowsQuote());
        self::assertNull($membership->joinReference);

        $acceptedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $callbackPublicId = (string) Str::ulid();
        $preview = $quotes->quoteForSelf(
            $userId,
            $userId,
            $selectionToken,
            $acceptedAt,
            'telegram-purchase-quote:'.$callbackPublicId,
            'tg-purchase-quote:'.$callbackPublicId,
            $membership,
        );

        self::assertSame(1, DB::table('quotes')->where('public_id', $preview->quotePublicId)->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame([0], $lookup->transactionLevels, 'Quote revalidation must not repeat provider I/O.');
    }

    public function test_unsatisfied_purchase_membership_has_protected_join_reference_and_cannot_create_quote(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-denied');
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000002);
        $this->activeRule($offeringId, 'customers', 'fail_closed', 'purchase-denied');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));
        $this->bindCatalogAndLookup($catalog, $lookup);
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);

        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'en');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::Unsatisfied, $membership->decision);
        self::assertFalse($membership->allowsQuote());
        self::assertNotNull($membership->joinReference);
        self::assertTrue($membership->joinReference->isMembershipJoinPrompt());
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $membership);

        self::assertSame(0, DB::table('quotes')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
    }

    public function test_no_rule_preflight_cannot_authorize_quote_after_purchase_rule_becomes_applicable(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-no-rule-drift');
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000003);
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => throw new RuntimeException('Provider must not be called without a rule.'));
        $this->bindCatalogAndLookup($catalog, $lookup);
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);

        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $membership->decision);
        self::assertSame([], $lookup->transactionLevels);

        $this->activeRule($offeringId, 'customers', 'fail_closed', 'purchase-added-after-preflight');
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $membership);
        self::assertSame(0, DB::table('quotes')->count());
    }

    public function test_fail_open_is_allowed_only_through_canonical_satisfied_decision_and_agent_rule_uses_agent_identity(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-agent-fail-open', 'agent');
        $userId = $this->membershipUser('agent');
        $this->telegramAccount($userId, 710000004);
        $this->activeRule($offeringId, 'agents', 'fail_open', 'purchase-agent-fail-open');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Unavailable,
            'telegram_membership_http_unavailable',
        ));
        $this->bindCatalogAndLookup($catalog, $lookup);

        $membership = $this->app->make(TelegramCustomerPurchaseQuote::class)
            ->membershipForSelf($userId, $userId, $selectionToken, 'fa');

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $membership->decision);
        self::assertSame('agent', $membership->accountType);
        self::assertTrue($membership->allowsQuote());
        self::assertSame([0], $lookup->transactionLevels);
    }

    public function test_manual_review_and_configuration_changed_decisions_cannot_authorize_quote(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-manual-review');
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000005);
        $this->activeRule($offeringId, 'customers', 'manual_review', 'purchase-manual-review');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Unavailable,
            'telegram_membership_http_unavailable',
        ));
        $this->bindCatalogAndLookup($catalog, $lookup);
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);

        $manual = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::ManualReview, $manual->decision);
        self::assertFalse($manual->allowsQuote());
        self::assertNull($manual->joinReference);
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $manual);
        self::assertSame(0, DB::table('quotes')->count());

        [$driftOfferingId, $driftCatalog, $driftSelectionToken] = $this->purchaseOffering('membership-config-changed');
        $driftUserId = $this->membershipUser('customer');
        $this->telegramAccount($driftUserId, 710000006);
        $ruleId = $this->activeRule($driftOfferingId, 'customers', 'fail_closed', 'purchase-config-changed');
        $driftLookup = $this->lookup(static function () use ($ruleId): TelegramMembershipLookupResult {
            DB::table('channel_membership_rules')->where('id', $ruleId)->update([
                'state' => 'disabled',
                'version' => 3,
                'updated_at' => now('UTC'),
            ]);

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Member,
                'telegram_membership_member',
            );
        });
        $this->bindCatalogAndLookup($driftCatalog, $driftLookup);
        $driftQuotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $changed = $driftQuotes->membershipForSelf($driftUserId, $driftUserId, $driftSelectionToken, 'en');

        self::assertSame(TelegramChannelMembershipEvaluationDecision::ConfigurationChanged, $changed->decision);
        self::assertFalse($changed->allowsQuote());
        self::assertNull($changed->joinReference);
        $this->assertQuoteRejected($driftQuotes, $driftUserId, $driftSelectionToken, $changed);
        self::assertSame(0, DB::table('quotes')->count());
    }

    public function test_no_rule_preflight_allows_customer_and_agent_without_provider_lookup(): void
    {
        foreach ([
            ['customer', 710000007, 'membership-no-rule-customer'],
            ['agent', 710000008, 'membership-no-rule-agent'],
        ] as [$accountType, $telegramUserId, $suffix]) {
            [, $catalog, $selectionToken] = $this->purchaseOffering($suffix, $accountType);
            $userId = $this->membershipUser($accountType);
            $this->telegramAccount($userId, $telegramUserId);
            $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => throw new RuntimeException('No-rule purchase must not call membership provider.'));
            $this->bindCatalogAndLookup($catalog, $lookup);

            $membership = $this->app->make(TelegramCustomerPurchaseQuote::class)
                ->membershipForSelf($userId, $userId, $selectionToken, 'fa');

            self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $membership->decision);
            self::assertSame($accountType, $membership->accountType);
            self::assertTrue($membership->allowsQuote());
            self::assertSame([], $lookup->transactionLevels);
        }
    }

    public function test_offering_subject_and_membership_configuration_drift_are_rejected_before_quote_creation(): void
    {
        [$offeringId, $catalog, $selectionToken] = $this->purchaseOffering('membership-drift-fences');
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000009);
        $ruleId = $this->activeRule($offeringId, 'customers', 'fail_closed', 'purchase-drift-fences');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        ));
        $this->bindCatalogAndLookup($catalog, $lookup);
        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertTrue($membership->allowsQuote());

        $this->app->make(TelegramChannelMembershipRuleService::class)->disable(
            $ruleId,
            2,
            $this->membershipContext('disable-purchase-drift-fences'),
        );
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $membership);
        self::assertSame(0, DB::table('quotes')->count());

        $fresh = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $fresh->decision);
        self::assertTrue($fresh->allowsQuote());
        $offeringVersion = (int) DB::table('plan_offerings')->where('id', $offeringId)->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offeringId,
            $offeringVersion,
            ProductVisibility::Hidden,
            new CatalogChangeContext(
                'purchase-membership-drift-hide',
                'purchase-membership-drift-hide-correlation',
                'telegram_purchase_membership_test',
                'Advance Offering version after membership preflight.',
                $this->benefitOwner(),
            ),
        );
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $fresh);
        self::assertSame(0, DB::table('quotes')->count());

        $currentVersion = (int) DB::table('plan_offerings')->where('id', $offeringId)->value('version');
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offeringId,
            $currentVersion,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'purchase-membership-drift-show',
                'purchase-membership-drift-show-correlation',
                'telegram_purchase_membership_test',
                'Restore Offering visibility for subject drift verification.',
                $this->benefitOwner(),
            ),
        );
        $subjectFresh = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        DB::table('users')->where('id', $userId)->update([
            'account_type' => 'agent',
            'updated_at' => now('UTC'),
        ]);
        $this->assertQuoteRejected($quotes, $userId, $selectionToken, $subjectFresh);
        self::assertSame(0, DB::table('quotes')->count());
    }

    public function test_generic_action_membership_fences_gift_code_and_service_view_without_provider_io_in_transactions(): void
    {
        $userId = $this->membershipUser('customer');
        $this->telegramAccount($userId, 710000010);
        $giftRuleId = $this->activeActionRule('gift_code_use', 'customers', 'fail_closed', 'gift-code-action');
        $giftLookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        ));
        $this->bindMembershipLookup($giftLookup);
        $membership = $this->app->make(TelegramActionMembershipService::class);

        $gift = $membership->forSelf($userId, $userId, 'gift_code_use', 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $gift->decision);
        self::assertTrue($gift->allowsAction());
        self::assertNull($gift->joinReference);
        self::assertSame([0], $giftLookup->transactionLevels);

        DB::transaction(function ($connection) use ($membership, $userId, $gift): void {
            $membership->assertCurrentForUpdate($connection, $userId, $userId, 'gift_code_use', $gift);
        });
        self::assertSame([0], $giftLookup->transactionLevels, 'Transaction-time fence must not repeat Telegram provider I/O.');

        $this->app->make(TelegramChannelMembershipRuleService::class)->disable(
            $giftRuleId,
            2,
            $this->membershipContext('disable-gift-code-action'),
        );
        try {
            DB::transaction(function ($connection) use ($membership, $userId, $gift): void {
                $membership->assertCurrentForUpdate($connection, $userId, $userId, 'gift_code_use', $gift);
            });
            self::fail('Configuration drift after provider verification must reject gift-code use.');
        } catch (TelegramActionMembershipChanged $exception) {
            self::assertStringContainsString('membership authority changed', $exception->getMessage());
        }

        $serviceRuleId = $this->activeActionRule('service_view', 'customers', 'fail_closed', 'service-view-action');
        self::assertGreaterThan(0, $serviceRuleId);
        $serviceLookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));
        $this->bindMembershipLookup($serviceLookup);
        $membership = $this->app->make(TelegramActionMembershipService::class);

        $serviceView = $membership->forSelf($userId, $userId, 'service_view', 'en');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::Unsatisfied, $serviceView->decision);
        self::assertFalse($serviceView->allowsAction());
        self::assertNotNull($serviceView->joinReference);
        self::assertTrue($serviceView->joinReference->isMembershipJoinPrompt());
        self::assertSame([0], $serviceLookup->transactionLevels);

        try {
            DB::transaction(function ($connection) use ($membership, $userId): void {
                $membership->assertCurrentForUpdate($connection, $userId, $userId, 'service_view', null);
            });
            self::fail('A current required service-view rule must reject execution without a provider preflight.');
        } catch (TelegramActionMembershipChanged $exception) {
            self::assertSame('Telegram action membership preflight is required.', $exception->getMessage());
        }
    }

    /** @return array{int,TelegramPurchaseMembershipCatalog,string} */
    private function purchaseOffering(string $suffix, string $accountType = 'customer'): array
    {
        $offering = $this->activeBenefitOffering($suffix);
        $row = DB::table('plan_offerings')->where('id', $offering['id'])->first(['code', 'base_price_irr', 'duration_days', 'version']);
        self::assertNotNull($row);
        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            (int) $row->version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'purchase-membership-visible-'.substr(hash('sha256', $suffix), 0, 20),
                'purchase-membership-visible-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'telegram_purchase_membership_test',
                'Expose the verified Offering for purchase membership verification.',
                $this->benefitOwner(),
            ),
        );
        $selectionToken = substr(hash('sha256', 'selection-'.$suffix), 0, 40);

        return [
            $offering['id'],
            new TelegramPurchaseMembershipCatalog(
                $selectionToken,
                (string) $row->code,
                (int) $row->base_price_irr,
                (int) $row->duration_days,
                $accountType,
            ),
            $selectionToken,
        ];
    }

    private function bindCatalogAndLookup(TelegramCustomerPurchaseCatalog $catalog, TelegramPurchaseMembershipLookup $lookup): void
    {
        $this->app->instance(TelegramCustomerPurchaseCatalog::class, $catalog);
        $this->bindMembershipLookup($lookup);
        $this->app->forgetInstance(TelegramCustomerPurchaseQuote::class);
    }

    private function bindMembershipLookup(TelegramPurchaseMembershipLookup $lookup): void
    {
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $this->app->forgetInstance(TelegramChannelMembershipEvaluator::class);
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);
    }

    private function lookup(Closure $callback): TelegramPurchaseMembershipLookup
    {
        return new TelegramPurchaseMembershipLookup($callback);
    }

    private function membershipUser(string $accountType): int
    {
        $userId = $this->benefitUser($accountType);
        if ($accountType !== 'customer') {
            return $userId;
        }

        $tierId = DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! is_int($tierId) && ! is_string($tierId)) {
            throw new RuntimeException('Normal customer tier is unavailable.');
        }
        $now = now('UTC');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => (int) $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function telegramAccount(int $userId, int $telegramUserId): void
    {
        $now = now('UTC');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => null,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function activeRule(int $offeringId, string $audience, string $failurePolicy, string $suffix): int
    {
        return $this->activeActionRule('purchase', $audience, $failurePolicy, $suffix, $offeringId);
    }

    private function activeActionRule(
        string $action,
        string $audience,
        string $failurePolicy,
        string $suffix,
        ?int $offeringId = null,
    ): int {
        $channelId = $this->channel($suffix, -1002500000000 - $this->mutationSequence - 1);
        $service = $this->app->make(TelegramChannelMembershipRuleService::class);
        $definition = new TelegramChannelMembershipRuleDefinition(
            $action.'-'.substr(hash('sha256', $suffix), 0, 20),
            $action,
            $audience,
            null,
            null,
            $offeringId,
            'all',
            $failurePolicy,
            100,
            null,
            null,
            [$channelId],
        );
        $created = $service->create($definition, $this->membershipContext('create-'.$suffix));
        $service->activate($created->targetId, 1, $this->membershipContext('activate-'.$suffix));

        return $created->targetId;
    }

    private function channel(string $suffix, int $chatId): int
    {
        $now = now('UTC');
        $id = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => 'purchase-'.substr(hash('sha256', $suffix), 0, 20),
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => 'public',
            'display_title' => 'Purchase membership '.$suffix,
            'join_url_ciphertext' => str_repeat('x', 64),
            'join_url_hash' => hash('sha256', 'https://t.me/'.$suffix),
            'sort_order' => 0,
            'state' => 'draft',
            'version' => 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('required_channels')->where('id', $id)->update([
            'state' => 'active',
            'version' => 2,
            'verified_bot_id' => 123456,
            'verification_result_code' => 'telegram_membership_administrator',
            'verified_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function membershipContext(string $suffix): TelegramConfigurationChangeContext
    {
        $this->mutationSequence++;
        $identity = substr(hash('sha256', $suffix.'-'.$this->mutationSequence), 0, 20);

        return new TelegramConfigurationChangeContext(
            'purchase-membership-'.$identity,
            'purchase-membership-correlation-'.$identity,
            'telegram_purchase_membership_test',
            'Purchase membership test configuration.',
            $this->benefitOwner(),
        );
    }

    private function assertQuoteRejected(
        TelegramCustomerPurchaseQuote $quotes,
        int $userId,
        string $selectionToken,
        TelegramCustomerPurchaseMembershipPreflight $membership,
    ): void {
        try {
            $callbackPublicId = (string) Str::ulid();
            $quotes->quoteForSelf(
                $userId,
                $userId,
                $selectionToken,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                'telegram-purchase-quote:'.$callbackPublicId,
                'tg-purchase-quote:'.$callbackPublicId,
                $membership,
            );
            self::fail('Expected purchase membership authority to reject Quote creation.');
        } catch (TelegramCustomerPurchaseMembershipChanged $exception) {
            self::assertStringContainsString('Telegram purchase membership', $exception->getMessage());
        }
    }
}
