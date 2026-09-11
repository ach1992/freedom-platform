<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\PlanOfferingService;
use App\Modules\Catalog\Domain\OfferingProtocolAssignment;
use App\Modules\Catalog\Domain\PlanOfferingAudience;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\PlanOfferingProtocolSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServerSelectionMode;
use App\Modules\Catalog\Domain\PlanOfferingServiceMode;
use App\Modules\Catalog\Domain\PlanOfferingTagMatchMode;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramChannelMembershipResolutionRequest;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleDefinition;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMembershipAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class TelegramMembershipResolutionTestClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement ONB-003 CHN-001 ACL-001 SEC-001 DAT-003 QUA-001 */
final class TelegramChannelMembershipRuleResolutionTest extends TestCase
{
    use RefreshDatabase;

    private TelegramMembershipResolutionTestClock $clock;

    private int $mutationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(TelegramMembershipAccessFoundationSeeder::class);

        $this->clock = new TelegramMembershipResolutionTestClock(
            new DateTimeImmutable('2026-09-11T04:30:00+00:00'),
        );
        $this->app->instance(Clock::class, $this->clock);
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);
        $this->app->forgetInstance(TelegramChannelMembershipRuleService::class);
        $this->app->forgetInstance(PlanOfferingService::class);
    }

    public function test_no_rule_returns_stable_read_only_plan_and_never_calls_membership_provider(): void
    {
        $userId = $this->customer('normal');
        $lookup = new class implements TelegramMembershipLookup
        {
            public int $calls = 0;

            public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
            {
                $this->calls++;
                throw new RuntimeException('Resolver must not call Telegram membership provider.');
            }
        };
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $auditBefore = DB::table('audit_logs')->count();
        $sessionsBefore = DB::table('telegram_interaction_sessions')->count();
        $resolver = $this->resolver();
        $request = new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry');

        $first = $resolver->resolve($request);
        $second = $resolver->resolve($request);

        self::assertFalse($first->required);
        self::assertNull($first->ruleId);
        self::assertSame([], $first->channels);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first->configurationHash);
        self::assertSame($first->configurationHash, $second->configurationHash);
        self::assertSame(0, $lookup->calls);
        self::assertSame($auditBefore, DB::table('audit_logs')->count());
        self::assertSame($sessionsBefore, DB::table('telegram_interaction_sessions')->count());

        $this->expectInvalidRequest(fn () => new TelegramChannelMembershipResolutionRequest(0, 'bot_entry'));
        $this->expectInvalidRequest(fn () => new TelegramChannelMembershipResolutionRequest($userId, 'unknown'));
        $this->expectInvalidRequest(fn () => new TelegramChannelMembershipResolutionRequest($userId, 'trial'));
        $this->expectInvalidRequest(fn () => new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry', 1));

        DB::table('users')->where('id', $userId)->update(['account_status' => 'blocked', 'updated_at' => now('UTC')]);
        $this->expectDomainFailure(
            fn () => $resolver->resolve($request),
            'Telegram membership resolution requires an active customer or agent.',
        );
    }

    public function test_action_audience_and_priority_select_the_unique_highest_applicable_rule(): void
    {
        $ownerId = $this->administrator(true);
        $channelId = $this->channel('resolution-priority', -1002300000001);
        $customerId = $this->customer('normal');
        $agentId = $this->user('agent');

        $globalRuleId = $this->activeRule(
            $ownerId,
            'global-entry-baseline',
            [$channelId],
            null,
            'both',
            priority: 10,
            failurePolicy: 'fail_open',
        );
        $exactRuleId = $this->activeRule(
            $ownerId,
            'customer-entry-strict',
            [$channelId],
            'bot_entry',
            'customers',
            priority: 20,
            failurePolicy: 'fail_closed',
        );

        $customerPlan = $this->resolver()->resolve(
            new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'),
        );
        $agentPlan = $this->resolver()->resolve(
            new TelegramChannelMembershipResolutionRequest($agentId, 'bot_entry'),
        );

        self::assertTrue($customerPlan->required);
        self::assertSame($exactRuleId, $customerPlan->ruleId);
        self::assertSame('fail_closed', $customerPlan->failurePolicy);
        self::assertSame(20, $customerPlan->priority);
        self::assertSame('customer', $customerPlan->subjectAccountType);

        self::assertTrue($agentPlan->required);
        self::assertSame($globalRuleId, $agentPlan->ruleId);
        self::assertSame('fail_open', $agentPlan->failurePolicy);
        self::assertSame('agent', $agentPlan->subjectAccountType);
    }

    public function test_customer_tier_and_active_tag_selectors_follow_current_subject_facts(): void
    {
        $ownerId = $this->administrator(true);
        $channelId = $this->channel('resolution-selector', -1002300000010);
        $customerId = $this->customer('normal');
        $tagId = $this->assignTag($customerId, 'resolution-eligible');
        $ruleId = $this->activeRule(
            $ownerId,
            'customer-normal-tagged',
            [$channelId],
            'bot_entry',
            'customers',
            tierCode: 'normal',
            customerTagId: $tagId,
            priority: 30,
        );
        $resolver = $this->resolver();

        $eligible = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertTrue($eligible->required);
        self::assertSame($ruleId, $eligible->ruleId);

        DB::table('customer_tag_assignments')
            ->where('user_id', $customerId)
            ->where('tag_id', $tagId)
            ->update(['removed_at' => now('UTC'), 'updated_at' => now('UTC')]);
        $withoutTag = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertFalse($withoutTag->required);

        DB::table('customer_tag_assignments')
            ->where('user_id', $customerId)
            ->where('tag_id', $tagId)
            ->update(['removed_at' => null, 'updated_at' => now('UTC')]);
        DB::table('customer_tiers')->where('code', 'normal')->update(['is_active' => false, 'updated_at' => now('UTC')]);
        $withoutActiveTier = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertFalse($withoutActiveTier->required);
    }

    public function test_effective_window_is_start_inclusive_end_exclusive_and_equal_top_priority_fails_closed(): void
    {
        $ownerId = $this->administrator(true);
        $channelId = $this->channel('resolution-window', -1002300000020);
        $customerId = $this->customer('normal');
        $ruleId = $this->activeRule(
            $ownerId,
            'windowed-entry-rule',
            [$channelId],
            'bot_entry',
            'both',
            priority: 50,
            effectiveFrom: new DateTimeImmutable('2026-09-11T04:30:00+00:00'),
            effectiveUntil: new DateTimeImmutable('2026-09-11T05:00:00+00:00'),
        );
        $resolver = $this->resolver();

        $atStart = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertSame($ruleId, $atStart->ruleId);

        $this->clock->value = new DateTimeImmutable('2026-09-11T05:00:00+00:00');
        $atEnd = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertFalse($atEnd->required);

        $this->clock->value = new DateTimeImmutable('2026-09-11T04:45:00+00:00');
        $this->activeRule(
            $ownerId,
            'equal-priority-entry-rule',
            [$channelId],
            'bot_entry',
            'both',
            priority: 50,
        );
        $this->expectDomainFailure(
            fn () => $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry')),
            'Telegram membership-rule configuration is ambiguous at the highest priority.',
        );
    }

    public function test_ordered_channel_set_preserves_later_disabled_channel_and_hash_detects_drift_without_secrets(): void
    {
        $ownerId = $this->administrator(true);
        $firstChannelId = $this->channel('resolution-first', -1002300000030, 'First channel');
        $secondChannelId = $this->channel('resolution-second', -1002300000031, 'Second channel');
        $customerId = $this->customer('normal');
        $this->activeRule(
            $ownerId,
            'ordered-channel-entry',
            [$secondChannelId, $firstChannelId],
            'bot_entry',
            'both',
            priority: 60,
            matchMode: 'all',
            failurePolicy: 'manual_review',
        );
        $resolver = $this->resolver();

        $before = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));
        self::assertSame([$secondChannelId, $firstChannelId], array_map(
            static fn ($channel): int => $channel->requiredChannelId,
            $before->channels,
        ));
        self::assertSame(['active', 'active'], array_map(
            static fn ($channel): string => $channel->state,
            $before->channels,
        ));
        self::assertSame('all', $before->matchMode);
        self::assertSame('manual_review', $before->failurePolicy);

        $serialized = json_encode($before, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('join_url', $serialized);
        self::assertStringNotContainsString('join_url_hash', $serialized);
        self::assertStringNotContainsString('join_url_ciphertext', $serialized);

        $this->disableChannel($secondChannelId);
        $after = $resolver->resolve(new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'));

        self::assertTrue($after->required);
        self::assertSame([$secondChannelId, $firstChannelId], array_map(
            static fn ($channel): int => $channel->requiredChannelId,
            $after->channels,
        ));
        self::assertSame(['disabled', 'active'], array_map(
            static fn ($channel): string => $channel->state,
            $after->channels,
        ));
        self::assertNotSame($before->configurationHash, $after->configurationHash);
        self::assertSame($after->configurationHash, $resolver->resolve(
            new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'),
        )->configurationHash);
    }

    public function test_purchase_offering_selector_requires_current_active_offering_and_exact_match(): void
    {
        $ownerId = $this->administrator(true);
        $customerId = $this->customer('normal');
        $channelId = $this->channel('resolution-offering', -1002300000040);
        $firstOfferingId = $this->activeOffering($ownerId, 'membership-offering-a');
        $secondOfferingId = $this->activeOffering($ownerId, 'membership-offering-b');
        $ruleId = $this->activeRule(
            $ownerId,
            'purchase-offering-membership',
            [$channelId],
            'purchase',
            'both',
            planOfferingId: $firstOfferingId,
            priority: 70,
        );
        $resolver = $this->resolver();

        $matched = $resolver->resolve(new TelegramChannelMembershipResolutionRequest(
            $customerId,
            'purchase',
            $firstOfferingId,
        ));
        self::assertTrue($matched->required);
        self::assertSame($ruleId, $matched->ruleId);
        self::assertSame($firstOfferingId, $matched->planOfferingId);

        $other = $resolver->resolve(new TelegramChannelMembershipResolutionRequest(
            $customerId,
            'purchase',
            $secondOfferingId,
        ));
        self::assertFalse($other->required);

        $this->expectDomainFailure(
            fn () => $resolver->resolve(new TelegramChannelMembershipResolutionRequest(
                $customerId,
                'purchase',
                9_999_999,
            )),
            'Telegram membership resolution Plan Offering is unavailable.',
        );
    }

    public function test_missing_customer_profile_fails_closed_instead_of_dropping_customer_selectors(): void
    {
        $customerId = $this->user('customer');

        $this->expectDomainFailure(
            fn () => $this->resolver()->resolve(
                new TelegramChannelMembershipResolutionRequest($customerId, 'bot_entry'),
            ),
            'Telegram membership resolution customer profile is unavailable.',
        );
    }

    private function resolver(): TelegramChannelMembershipRuleResolver
    {
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);

        return $this->app->make(TelegramChannelMembershipRuleResolver::class);
    }

    /**
     * @param  list<int>  $channelIds
     */
    private function activeRule(
        int $ownerId,
        string $key,
        array $channelIds,
        ?string $action,
        string $audience,
        ?string $tierCode = null,
        ?int $customerTagId = null,
        ?int $planOfferingId = null,
        string $matchMode = 'all',
        string $failurePolicy = 'fail_closed',
        int $priority = 100,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
    ): int {
        $service = $this->app->make(TelegramChannelMembershipRuleService::class);
        $definition = new TelegramChannelMembershipRuleDefinition(
            $key,
            $action,
            $audience,
            $tierCode,
            $customerTagId,
            $planOfferingId,
            $matchMode,
            $failurePolicy,
            $priority,
            $effectiveFrom,
            $effectiveUntil,
            $channelIds,
        );
        $created = $service->create($definition, $this->telegramContext($ownerId, 'create-'.$key));
        $service->activate($created->targetId, 1, $this->telegramContext($ownerId, 'activate-'.$key));

        return $created->targetId;
    }

    private function channel(string $key, int $chatId, ?string $title = null): int
    {
        $now = now('UTC');
        $id = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => 'public',
            'display_title' => $title ?? $key,
            'join_url_ciphertext' => str_repeat('x', 64),
            'join_url_hash' => hash('sha256', 'https://t.me/'.$key),
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

    private function disableChannel(int $channelId): void
    {
        $version = (int) DB::table('required_channels')->where('id', $channelId)->value('version');
        DB::table('required_channels')->where('id', $channelId)->update([
            'state' => 'disabled',
            'version' => $version + 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'updated_at' => now('UTC'),
        ]);
    }

    private function customer(string $tierCode): int
    {
        $userId = $this->user('customer');
        $tierId = DB::table('customer_tiers')->where('code', $tierCode)->value('id');
        if (! is_int($tierId) && ! is_string($tierId)) {
            throw new RuntimeException('Test customer tier is unavailable.');
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

    private function assignTag(int $userId, string $code): int
    {
        $now = now('UTC');
        $tagId = (int) DB::table('customer_tags')->insertGetId([
            'code' => $code,
            'name_translation_key' => 'telegram.membership.resolution.tag',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('customer_tag_assignments')->insert([
            'user_id' => $userId,
            'tag_id' => $tagId,
            'assigned_by_administrator_id' => null,
            'assigned_at' => $now,
            'removed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $tagId;
    }

    private function user(string $accountType): int
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

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user('customer'),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function activeOffering(int $ownerId, string $code): int
    {
        $now = now('UTC');
        $suffix = Str::lower(Str::random(8));
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'membership-category-'.$suffix,
            'name_fa' => 'Membership category',
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
            'code' => 'membership-product-'.$suffix,
            'name_fa' => 'Membership product',
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
            'code' => 'membership-server-'.$suffix,
            'name_fa' => 'Membership server',
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
            'code' => 'membership-connection-'.$suffix,
            'provider_type' => 'fake',
            'name_fa' => 'Membership panel',
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
            'code' => 'membership-target-'.$suffix,
            'kind' => 'inbound',
            'name_fa' => 'Membership target',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'membership-target-'.$suffix),
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
            'code' => 'membership-profile-'.$suffix,
            'name_fa' => 'Membership profile',
            'name_en' => null,
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/membership',
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

        $service = $this->app->make(PlanOfferingService::class);
        $created = $service->create(
            new PlanOfferingDefinition(
                $code,
                $productId,
                null,
                $serverId,
                $targetId,
                new PlanOfferingServiceMode('shared', 'Membership service', 'Membership service'),
                PlanOfferingAudience::Both,
                PlanOfferingServerSelectionMode::System,
                PlanOfferingProtocolSelectionMode::Fixed,
                PlanOfferingTagMatchMode::All,
                1_000_000,
                30,
                null,
                1,
                0,
                1,
                1,
                false,
                false,
                false,
                false,
                [],
                [],
                [new OfferingProtocolAssignment($profileId, false, true)],
                [],
                [],
                [],
            ),
            $this->catalogContext($ownerId, 'create-'.$code),
        );

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'membership-test-1.0.0',
            'last_capabilities_hash' => hash('sha256', 'membership-capabilities-'.$suffix),
            'last_tested_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('panel_service_targets')->where('id', $targetId)->update([
            'state' => 'active',
            'capability_status' => 'verified',
            'capability_evidence_hash' => hash('sha256', 'membership-evidence-'.$suffix),
            'capability_verified_at' => $now,
            'verified_connection_version' => 1,
            'updated_at' => $now,
        ]);
        DB::table('sales_servers')->where('id', $serverId)->update([
            'state' => 'active',
            'visibility' => 'listed',
            'updated_at' => $now,
        ]);
        $service->activate(
            $created->targetId,
            1,
            $this->catalogContext($ownerId, 'activate-'.$code),
        );

        return $created->targetId;
    }

    private function telegramContext(int $ownerId, string $suffix): TelegramConfigurationChangeContext
    {
        $this->mutationSequence++;
        $identity = substr(hash('sha256', $suffix.'-'.$this->mutationSequence), 0, 24);

        return new TelegramConfigurationChangeContext(
            'telegram-membership-resolution-'.$identity,
            'correlation-'.$identity,
            'telegram_membership_resolution_test',
            'Telegram membership resolution test configuration change.',
            $ownerId,
        );
    }

    private function catalogContext(int $ownerId, string $suffix): CatalogChangeContext
    {
        $identity = substr(hash('sha256', $suffix), 0, 24);

        return new CatalogChangeContext(
            'catalog-membership-resolution-'.$identity,
            'correlation-'.$identity,
            'membership_resolution_test',
            'Catalog fixture for Telegram membership resolution.',
            $ownerId,
        );
    }

    private function expectInvalidRequest(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid Telegram membership resolution request.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function expectDomainFailure(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            self::fail('Expected Telegram membership resolution domain failure.');
        } catch (DomainException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }
}
