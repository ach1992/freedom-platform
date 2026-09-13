<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\ProductVisibility;
use App\Modules\Customers\Application\CustomerChangeContext;
use App\Modules\Customers\Application\CustomerTagService;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluationDecision;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleDefinition;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseMembershipChanged;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseMembershipPreflight;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class TelegramRepeatableReadMembershipLookup implements TelegramMembershipLookup
{
    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        throw new RuntimeException('Repeatable-read stale NotRequired verification must not call the membership provider.');
    }
}

/** @requirement ONB-003 CHN-001 BUY-001 BUY-002 AGT-003 AGT-005 DAT-002 DAT-003 SEC-002 QUA-001 */
final class TelegramCustomerPurchaseMembershipRepeatableReadTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    private int $mutationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_repeatable_read_snapshot_rejects_rule_created_after_stale_no_rule_preflight(): void
    {
        $this->requireMariaDb();
        $userId = $this->membershipUser();
        [$offeringId, $selectionToken] = $this->purchaseOffering($userId, 'membership-repeatable-rule-race');
        $this->telegramAccount($userId, 720000001);
        $this->bindNoProviderLookup();

        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $membership->decision);

        [$originalConnection, $primaryName, $mutatorName, $primary] = $this->openRepeatableReadSnapshot();
        try {
            self::assertNotNull($primary->table('users')->where('id', $userId)->first(['id']));
            self::assertSame(0, $primary->table('channel_membership_rules')
                ->where('state', 'active')
                ->where('plan_offering_id', $offeringId)
                ->count());

            config(['database.default' => $mutatorName]);
            $this->activeRule(
                $offeringId,
                'customers',
                'fail_closed',
                'repeatable-rule-race-created-after-snapshot',
            );

            config(['database.default' => $primaryName]);
            $this->assertQuoteRejected($quotes, $userId, $selectionToken, $membership);
        } finally {
            $this->closeRepeatableReadSnapshot($originalConnection, $primaryName, $mutatorName, $primary);
        }

        self::assertSame(1, DB::table('channel_membership_rules')
            ->where('state', 'active')
            ->where('plan_offering_id', $offeringId)
            ->count());
        $this->assertNoPurchaseEffects();
    }

    public function test_repeatable_read_snapshot_rejects_tag_selector_that_becomes_applicable_before_quote_authorization(): void
    {
        $this->requireMariaDb();
        $userId = $this->membershipUser();
        [$offeringId, $selectionToken] = $this->purchaseOffering($userId, 'membership-repeatable-tag-race');
        $this->telegramAccount($userId, 720000002);
        $tagCode = 'membership_repeatable_tag';
        $tagId = $this->customerTag($tagCode);
        $this->activeRule(
            $offeringId,
            'customers',
            'fail_closed',
            'repeatable-tag-selector',
            $tagId,
        );
        $this->bindNoProviderLookup();

        $quotes = $this->app->make(TelegramCustomerPurchaseQuote::class);
        $membership = $quotes->membershipForSelf($userId, $userId, $selectionToken, 'fa');
        self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $membership->decision);

        [$originalConnection, $primaryName, $mutatorName, $primary] = $this->openRepeatableReadSnapshot();
        try {
            self::assertNotNull($primary->table('users')->where('id', $userId)->first(['id']));
            self::assertSame(0, $primary->table('customer_tag_assignments')
                ->where('user_id', $userId)
                ->where('tag_id', $tagId)
                ->whereNull('removed_at')
                ->count());

            config(['database.default' => $mutatorName]);
            $this->app->make(CustomerTagService::class)->assign(
                $userId,
                $tagCode,
                new CustomerChangeContext(
                    'purchase-membership-tag-race-assign-0001',
                    'purchase-tag-race-correlation-0001',
                    'membership_selector_race',
                    'Make the configured membership selector applicable after the stale Quote snapshot.',
                    $this->benefitOwner(),
                ),
            );

            config(['database.default' => $primaryName]);
            $this->assertQuoteRejected($quotes, $userId, $selectionToken, $membership);
        } finally {
            $this->closeRepeatableReadSnapshot($originalConnection, $primaryName, $mutatorName, $primary);
        }

        self::assertSame(1, DB::table('customer_tag_assignments')
            ->where('user_id', $userId)
            ->where('tag_id', $tagId)
            ->whereNull('removed_at')
            ->count());
        $this->assertNoPurchaseEffects();
    }

    private function requireMariaDb(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            self::markTestSkipped('MariaDB/MySQL is required for REPEATABLE READ membership race verification.');
        }
    }

    /** @return array{int,string} */
    private function purchaseOffering(int $userId, string $suffix): array
    {
        $offering = $this->activeBenefitOffering($suffix);
        $row = DB::table('plan_offerings')
            ->where('id', $offering['id'])
            ->first(['code', 'version']);
        self::assertNotNull($row);

        $this->app->make(PlanOfferingService::class)->setVisibility(
            $offering['id'],
            (int) $row->version,
            ProductVisibility::Visible,
            new CatalogChangeContext(
                'purchase-membership-rr-visible-'.substr(hash('sha256', $suffix), 0, 20),
                'purchase-membership-rr-correlation-'.substr(hash('sha256', $suffix), 0, 16),
                'telegram_purchase_membership_test',
                'Expose the verified Offering for repeatable-read membership verification.',
                $this->benefitOwner(),
            ),
        );

        $selectionToken = substr(hash('sha256', 'telegram-purchase-offering-v1:'.$userId.':'.(string) $row->code), 0, 40);
        $resolved = $this->app->make(TelegramCustomerPurchaseCatalog::class)->offeringForSelf(
            $userId,
            $userId,
            $selectionToken,
        );
        self::assertSame((string) $row->code, $resolved->offeringCode);

        return [$offering['id'], $selectionToken];
    }

    private function membershipUser(): int
    {
        $userId = $this->benefitUser('customer');
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

    private function bindNoProviderLookup(): void
    {
        $this->app->instance(TelegramMembershipLookup::class, new TelegramRepeatableReadMembershipLookup);
        $this->app->forgetInstance(TelegramChannelMembershipEvaluator::class);
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);
        $this->app->forgetInstance(TelegramCustomerPurchaseQuote::class);
    }

    private function activeRule(
        int $offeringId,
        string $audience,
        string $failurePolicy,
        string $suffix,
        ?int $customerTagId = null,
    ): int {
        $channelId = $this->channel($suffix, -1002600000000 - $this->mutationSequence - 1);
        $service = $this->app->make(TelegramChannelMembershipRuleService::class);
        $definition = new TelegramChannelMembershipRuleDefinition(
            'purchase-'.substr(hash('sha256', $suffix), 0, 20),
            'purchase',
            $audience,
            null,
            $customerTagId,
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

    private function customerTag(string $code): int
    {
        $now = now('UTC');

        return (int) DB::table('customer_tags')->insertGetId([
            'code' => $code,
            'name_translation_key' => 'customer_tags.'.$code,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function membershipContext(string $suffix): TelegramConfigurationChangeContext
    {
        $this->mutationSequence++;
        $identity = substr(hash('sha256', $suffix.'-'.$this->mutationSequence), 0, 20);

        return new TelegramConfigurationChangeContext(
            'purchase-membership-rr-'.$identity,
            'purchase-membership-rr-correlation-'.$identity,
            'telegram_purchase_membership_test',
            'Repeatable-read purchase membership test configuration.',
            $this->benefitOwner(),
        );
    }

    /** @return array{string,string,string,Connection} */
    private function openRepeatableReadSnapshot(): array
    {
        $originalConnection = (string) config('database.default');
        $connectionConfig = config('database.connections.'.$originalConnection);
        self::assertIsArray($connectionConfig);

        $primaryName = 'telegram_membership_repeatable_primary';
        $mutatorName = 'telegram_membership_repeatable_mutator';
        config([
            'database.connections.'.$primaryName => $connectionConfig,
            'database.connections.'.$mutatorName => $connectionConfig,
        ]);
        DB::purge($primaryName);
        DB::purge($mutatorName);

        $primary = DB::connection($primaryName);
        $primary->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $primary->beginTransaction();

        return [$originalConnection, $primaryName, $mutatorName, $primary];
    }

    private function closeRepeatableReadSnapshot(
        string $originalConnection,
        string $primaryName,
        string $mutatorName,
        Connection $primary,
    ): void {
        while ($primary->transactionLevel() > 0) {
            $primary->rollBack();
        }
        config(['database.default' => $originalConnection]);
        DB::purge($primaryName);
        DB::purge($mutatorName);
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
            self::fail('Expected stale repeatable-read purchase membership authority to reject Quote creation.');
        } catch (TelegramCustomerPurchaseMembershipChanged $exception) {
            self::assertStringContainsString('Telegram purchase membership', $exception->getMessage());
        }
    }

    private function assertNoPurchaseEffects(): void
    {
        self::assertSame(0, DB::table('quotes')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
        self::assertSame(0, DB::table('orders')->count());
        self::assertSame(0, DB::table('provisioning_operations')->count());
    }
}
