<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleDefinition;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMembershipAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** @requirement ONB-003 CHN-001 ACL-001 ACL-002 SEC-001 DAT-003 QUA-001 */
final class TelegramChannelMembershipRuleConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(TelegramMembershipAccessFoundationSeeder::class);
    }

    public function test_create_update_replay_and_safe_projection_preserve_order_and_policy(): void
    {
        $ownerId = $this->administrator(true);
        $firstChannel = $this->channel('membership-first', -1002200000001, true, 10);
        $secondChannel = $this->channel('membership-second', -1002200000002, true, 20);
        $service = $this->service();
        $definition = $this->definition(
            channelIds: [$secondChannel, $firstChannel],
            action: 'bot_entry',
            audience: 'both',
            matchMode: 'all',
            failurePolicy: 'fail_closed',
            priority: 100,
            effectiveFrom: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            effectiveUntil: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
        );
        $createContext = $this->context($ownerId, 'telegram-membership-rule-create-0001');

        $created = $service->create($definition, $createContext);
        $replayed = $service->create($definition, $createContext);

        self::assertTrue($created->changed);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->targetId, $replayed->targetId);
        self::assertSame('draft', $created->after['state']);
        self::assertSame(1, $created->after['version']);
        self::assertSame(json_encode([$secondChannel, $firstChannel], JSON_THROW_ON_ERROR), $created->after['channel_ids_json']);
        self::assertSame('all', $created->after['match_mode']);
        self::assertSame('fail_closed', $created->after['failure_policy']);
        self::assertSame('2026-09-01 00:00:00.000000', $created->after['effective_from']);
        self::assertSame('2026-10-01 00:00:00.000000', $created->after['effective_until']);

        $storedOrder = DB::table('channel_membership_rule_channels')
            ->where('channel_membership_rule_id', $created->targetId)
            ->orderBy('sort_order')
            ->pluck('required_channel_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        self::assertSame([$secondChannel, $firstChannel], $storedOrder);

        $channelCiphertext = (string) DB::table('required_channels')->where('id', $firstChannel)->value('join_url_ciphertext');
        $audit = DB::table('audit_logs')->where('action', 'telegram.membership_rule.create')->first();
        self::assertNotNull($audit);
        $auditText = json_encode([$audit->before_safe_data, $audit->after_safe_data, $audit->reason], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('join_url', $auditText);
        self::assertStringNotContainsString($channelCiphertext, $auditText);

        $updatedDefinition = $this->definition(
            key: 'primary-entry-membership',
            channelIds: [$firstChannel, $secondChannel],
            action: 'bot_entry',
            audience: 'both',
            matchMode: 'any',
            failurePolicy: 'fail_open',
            priority: 120,
        );
        $updateContext = $this->context($ownerId, 'telegram-membership-rule-update-0001');
        $updated = $service->update($created->targetId, 1, $updatedDefinition, $updateContext);
        $updateReplay = $service->update($created->targetId, 1, $updatedDefinition, $updateContext);

        self::assertTrue($updated->changed);
        self::assertTrue($updateReplay->replayed);
        self::assertSame(2, $updated->after['version']);
        self::assertSame('any', $updated->after['match_mode']);
        self::assertSame('fail_open', $updated->after['failure_policy']);
        self::assertSame(json_encode([$firstChannel, $secondChannel], JSON_THROW_ON_ERROR), $updated->after['channel_ids_json']);

        $safe = $service->findSafe($created->targetId);
        self::assertSame($updated->after['channel_ids_json'], $safe['channel_ids_json']);
        self::assertArrayNotHasKey('join_url', $safe);
        self::assertArrayNotHasKey('join_url_ciphertext', $safe);
        self::assertSame([$safe], $service->listSafe());

        try {
            $service->update(
                $created->targetId,
                1,
                $this->definition(channelIds: [$firstChannel], priority: 999),
                $updateContext,
            );
            self::fail('Conflicting replay payload must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram configuration mutation fingerprint conflict.', $exception->getMessage());
        }
    }

    public function test_activation_requires_nonempty_active_channels_and_later_channel_disable_keeps_rule_shape(): void
    {
        $ownerId = $this->administrator(true);
        $draftChannel = $this->channel('membership-draft', -1002200000010, false);
        $service = $this->service();

        $empty = $service->create(
            $this->definition(key: 'empty-membership-rule', channelIds: []),
            $this->context($ownerId, 'telegram-membership-empty-create01'),
        );
        $this->expectDomainFailure(
            fn () => $service->activate($empty->targetId, 1, $this->context($ownerId, 'telegram-membership-empty-active01')),
            'Telegram membership rule requires at least one channel before activation.',
        );

        $draftRule = $service->create(
            $this->definition(key: 'draft-channel-rule', channelIds: [$draftChannel]),
            $this->context($ownerId, 'telegram-membership-draft-create01'),
        );
        $this->expectDomainFailure(
            fn () => $service->activate($draftRule->targetId, 1, $this->context($ownerId, 'telegram-membership-draft-active01')),
            'Telegram membership rule requires active channels before activation.',
        );

        $this->activateChannelFixture($draftChannel);
        $activated = $service->activate(
            $draftRule->targetId,
            1,
            $this->context($ownerId, 'telegram-membership-draft-active02'),
        );
        self::assertSame('active', $activated->after['state']);
        self::assertSame(2, $activated->after['version']);

        $this->disableChannelFixture($draftChannel);
        $safe = $service->findSafe($draftRule->targetId);
        self::assertSame('active', $safe['state']);
        self::assertSame(json_encode([$draftChannel], JSON_THROW_ON_ERROR), $safe['channel_ids_json']);
        self::assertSame(1, DB::table('channel_membership_rule_channels')->where('channel_membership_rule_id', $draftRule->targetId)->count());

        $disabled = $service->disable(
            $draftRule->targetId,
            2,
            $this->context($ownerId, 'telegram-membership-rule-disable01'),
        );
        self::assertSame('disabled', $disabled->after['state']);
        self::assertSame(3, $disabled->after['version']);
        $this->expectDomainFailure(
            fn () => $service->activate($draftRule->targetId, 3, $this->context($ownerId, 'telegram-membership-rule-reactive01')),
            'Telegram membership rule requires active channels before activation.',
        );
    }

    public function test_selector_reference_and_definition_validation_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $channelId = $this->channel('membership-selector', -1002200000020, true);
        $tagId = $this->customerTag('membership-selector-tag', true);
        $service = $this->service();

        $created = $service->create(
            $this->definition(
                key: 'customer-selector-rule',
                channelIds: [$channelId],
                action: 'bot_entry',
                audience: 'customers',
                tierCode: 'normal',
                customerTagId: $tagId,
            ),
            $this->context($ownerId, 'telegram-membership-selector-create1'),
        );
        self::assertSame('normal', $created->after['tier_code']);
        self::assertSame($tagId, $created->after['customer_tag_id']);

        DB::table('customer_tags')->where('id', $tagId)->update(['is_active' => false, 'updated_at' => now('UTC')]);
        $this->expectDomainFailure(
            fn () => $service->activate($created->targetId, 1, $this->context($ownerId, 'telegram-membership-selector-active1')),
            'Telegram membership-rule customer tag is unavailable for activation.',
        );

        $this->expectDomainFailure(
            fn () => $service->create(
                $this->definition(
                    key: 'missing-tag-membership',
                    channelIds: [$channelId],
                    audience: 'customers',
                    customerTagId: 9_999_999,
                ),
                $this->context($ownerId, 'telegram-membership-selector-missing1'),
            ),
            'Telegram membership-rule customer tag does not exist or is inactive.',
        );
        $this->expectDomainFailure(
            fn () => $service->create(
                $this->definition(
                    key: 'missing-offering-membership',
                    channelIds: [$channelId],
                    action: 'purchase',
                    planOfferingId: 9_999_999,
                ),
                $this->context($ownerId, 'telegram-membership-selector-offer01'),
            ),
            'Telegram membership-rule Plan Offering does not exist or is inactive.',
        );

        $this->expectInvalidDefinition(fn () => $this->definition(
            key: 'agent-tier-invalid',
            channelIds: [$channelId],
            audience: 'agents',
            tierCode: 'normal',
        ));
        $this->expectInvalidDefinition(fn () => $this->definition(
            key: 'offering-action-invalid',
            channelIds: [$channelId],
            action: 'service_view',
            planOfferingId: 123,
        ));
        $this->expectInvalidDefinition(fn () => $this->definition(
            key: 'date-window-invalid',
            channelIds: [$channelId],
            effectiveFrom: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
            effectiveUntil: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        ));
    }

    public function test_membership_rule_mutations_require_the_existing_administrator_permission_boundary(): void
    {
        $administratorId = $this->administrator(false);
        $channelId = $this->channel('membership-authz', -1002200000030, true);
        $service = $this->service();
        $context = $this->context($administratorId, 'telegram-membership-authz-create01');

        try {
            $service->create($this->definition(channelIds: [$channelId]), $context);
            self::fail('Unprivileged administrator must not mutate Telegram membership rules.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('channel_membership_rules')->count());
            self::assertSame(0, DB::table('audit_logs')->where('request_fingerprint', $context->requestFingerprint)->count());
        }
    }

    public function test_rule_configuration_never_calls_telegram_user_membership_lookup(): void
    {
        $lookup = new class implements TelegramMembershipLookup
        {
            public int $calls = 0;

            public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
            {
                $this->calls++;
                throw new RuntimeException('Membership provider must not be called by rule configuration.');
            }
        };
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $ownerId = $this->administrator(true);
        $channelId = $this->channel('membership-no-provider', -1002200000040, true);
        $service = $this->service();
        $created = $service->create(
            $this->definition(channelIds: [$channelId], action: 'trial', failurePolicy: 'fail_closed'),
            $this->context($ownerId, 'telegram-membership-no-provider1'),
        );
        $activated = $service->activate(
            $created->targetId,
            1,
            $this->context($ownerId, 'telegram-membership-no-provider2'),
        );

        self::assertSame('active', $activated->after['state']);
        self::assertSame(0, $lookup->calls);
    }

    public function test_mariadb_guards_active_rule_and_association_integrity(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MariaDB direct constraint verification requires mysql driver.');
        }

        $ownerId = $this->administrator(true);
        $firstChannel = $this->channel('membership-db-first', -1002200000050, true);
        $secondChannel = $this->channel('membership-db-second', -1002200000051, true);
        $service = $this->service();
        $rule = $service->create(
            $this->definition(key: 'db-guard-membership', channelIds: [$firstChannel]),
            $this->context($ownerId, 'telegram-membership-db-create01'),
        );
        $service->activate(
            $rule->targetId,
            1,
            $this->context($ownerId, 'telegram-membership-db-active01'),
        );

        $this->expectQueryFailure(fn () => DB::table('channel_membership_rules')->where('id', $rule->targetId)->update([
            'match_mode' => 'any',
            'version' => 3,
            'updated_at' => now('UTC'),
        ]));
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rule_channels')
            ->where('channel_membership_rule_id', $rule->targetId)
            ->delete());
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rule_channels')->insert([
            'channel_membership_rule_id' => $rule->targetId,
            'required_channel_id' => $secondChannel,
            'sort_order' => 1,
            'created_at' => now('UTC'),
        ]));
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rules')->where('id', $rule->targetId)->delete());
        self::assertSame('active', DB::table('channel_membership_rules')->where('id', $rule->targetId)->value('state'));
        self::assertSame(1, DB::table('channel_membership_rule_channels')->where('channel_membership_rule_id', $rule->targetId)->count());

        $empty = $service->create(
            $this->definition(key: 'db-empty-membership', channelIds: []),
            $this->context($ownerId, 'telegram-membership-db-empty01'),
        );
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rules')->where('id', $empty->targetId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => now('UTC'),
        ]));

        $unordered = $service->create(
            $this->definition(key: 'db-order-membership', channelIds: [$secondChannel]),
            $this->context($ownerId, 'telegram-membership-db-order01'),
        );
        DB::table('channel_membership_rule_channels')
            ->where('channel_membership_rule_id', $unordered->targetId)
            ->update(['sort_order' => 5]);
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rules')->where('id', $unordered->targetId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => now('UTC'),
        ]));

        $inactiveChannel = $this->channel('membership-db-inactive', -1002200000052, false);
        $inactiveRule = $service->create(
            $this->definition(key: 'db-inactive-membership', channelIds: [$inactiveChannel]),
            $this->context($ownerId, 'telegram-membership-db-inactive1'),
        );
        $this->expectQueryFailure(fn () => DB::table('channel_membership_rules')->where('id', $inactiveRule->targetId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => now('UTC'),
        ]));
    }

    private function service(): TelegramChannelMembershipRuleService
    {
        $this->app->forgetInstance(TelegramChannelMembershipRuleService::class);

        return $this->app->make(TelegramChannelMembershipRuleService::class);
    }

    /** @param list<int> $channelIds */
    private function definition(
        string $key = 'primary-entry-membership',
        array $channelIds = [],
        ?string $action = 'bot_entry',
        string $audience = 'both',
        ?string $tierCode = null,
        ?int $customerTagId = null,
        ?int $planOfferingId = null,
        string $matchMode = 'all',
        string $failurePolicy = 'fail_closed',
        int $priority = 100,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
    ): TelegramChannelMembershipRuleDefinition {
        return new TelegramChannelMembershipRuleDefinition(
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
    }

    private function channel(string $key, int $chatId, bool $active, int $sortOrder = 0): int
    {
        $now = now('UTC');
        $id = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => 'public',
            'display_title' => 'Membership '.$key,
            'join_url_ciphertext' => str_repeat('x', 64),
            'join_url_hash' => hash('sha256', 'https://t.me/'.$key),
            'sort_order' => $sortOrder,
            'state' => 'draft',
            'version' => 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($active) {
            $this->activateChannelFixture($id);
        }

        return $id;
    }

    private function activateChannelFixture(int $channelId): void
    {
        $version = (int) DB::table('required_channels')->where('id', $channelId)->value('version');
        DB::table('required_channels')->where('id', $channelId)->update([
            'state' => 'active',
            'version' => $version + 1,
            'verified_bot_id' => 123456,
            'verification_result_code' => 'telegram_membership_administrator',
            'verified_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function disableChannelFixture(int $channelId): void
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

    private function customerTag(string $code, bool $active): int
    {
        $now = now('UTC');

        return (int) DB::table('customer_tags')->insertGetId([
            'code' => $code,
            'name_translation_key' => 'telegram.membership.test.tag',
            'is_active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function context(int $administratorId, string $fingerprint): TelegramConfigurationChangeContext
    {
        return new TelegramConfigurationChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'telegram_membership_configuration',
            'Telegram membership rule test change.',
            $administratorId,
        );
    }

    private function expectDomainFailure(callable $operation, string $expectedMessage): void
    {
        try {
            $operation();
            self::fail('Expected Telegram membership-rule domain failure.');
        } catch (DomainException $exception) {
            self::assertSame($expectedMessage, $exception->getMessage());
        }
    }

    private function expectInvalidDefinition(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid Telegram membership-rule definition.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    private function expectQueryFailure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected MariaDB membership-rule integrity failure.');
        } catch (QueryException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
