<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluationDecision;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipResolutionRequest;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleDefinition;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Shared\Application\Clock;
use Closure;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMembershipAccessFoundationSeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TelegramMembershipEvaluationTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T05:00:00+00:00');
    }
}

final class TelegramMembershipEvaluationTestRuntime implements ProtectedTelegramDeliveryRuntime
{
    public function botId(): string
    {
        return '123456';
    }
}

final class TelegramMembershipEvaluationTestLookup implements TelegramMembershipLookup
{
    /** @var list<array{chat_id:int,user_id:int}> */
    public array $calls = [];

    /** @param Closure(int,int,int):TelegramMembershipLookupResult $callback */
    public function __construct(private Closure $callback) {}

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        $this->calls[] = ['chat_id' => $chatId, 'user_id' => $telegramUserId];

        return ($this->callback)($chatId, $telegramUserId, count($this->calls));
    }
}

/** @requirement ONB-003 CHN-001 SEC-001 SEC-003 QUA-001 */
final class TelegramChannelMembershipEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private int $mutationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(TelegramMembershipAccessFoundationSeeder::class);
        $this->app->instance(Clock::class, new TelegramMembershipEvaluationTestClock);
        $this->app->instance(ProtectedTelegramDeliveryRuntime::class, new TelegramMembershipEvaluationTestRuntime);
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);
        $this->app->forgetInstance(TelegramChannelMembershipRuleService::class);
        $this->app->forgetInstance(TelegramChannelMembershipEvaluator::class);
    }

    public function test_no_rule_returns_not_required_without_identity_or_provider_lookup(): void
    {
        $userId = $this->customer();
        $lookup = $this->lookup(fn (): TelegramMembershipLookupResult => throw new RuntimeException('Provider must not be called.'));
        $evaluator = $this->evaluator($lookup);
        $auditBefore = DB::table('audit_logs')->count();
        $sessionsBefore = DB::table('telegram_interaction_sessions')->count();

        $result = $evaluator->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::NotRequired, $result->decision);
        self::assertFalse($result->plan->required);
        self::assertNull($result->telegramUserId);
        self::assertSame([], $result->channels);
        self::assertSame([], $lookup->calls);
        self::assertSame($auditBefore, DB::table('audit_logs')->count());
        self::assertSame($sessionsBefore, DB::table('telegram_interaction_sessions')->count());
    }

    public function test_all_mode_definitive_not_member_wins_over_unavailable_and_preserves_ordered_safe_evidence(): void
    {
        $ownerId = $this->administrator();
        $first = $this->channel('eval-all-first', -1002400000001);
        $second = $this->channel('eval-all-second', -1002400000002);
        $userId = $this->customerWithTelegram(700000001);
        $this->activeRule($ownerId, 'eval-all', [$first, $second], 'all', 'fail_open');
        $lookup = $this->lookup(static function (int $chatId): TelegramMembershipLookupResult {
            return $chatId === -1002400000001
                ? new TelegramMembershipLookupResult(TelegramMembershipEvidence::NotMember, 'telegram_membership_left')
                : new TelegramMembershipLookupResult(TelegramMembershipEvidence::Unavailable, 'telegram_membership_http_unavailable');
        });

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Unsatisfied, $result->decision);
        self::assertSame(700000001, $result->telegramUserId);
        self::assertSame(
            [
                ['chat_id' => -1002400000001, 'user_id' => 700000001],
                ['chat_id' => -1002400000002, 'user_id' => 700000001],
            ],
            $lookup->calls,
        );
        self::assertSame([$first, $second], array_map(
            static fn ($item): int => $item->requiredChannelId,
            $result->channels,
        ));
        self::assertSame(
            [TelegramMembershipEvidence::NotMember, TelegramMembershipEvidence::Unavailable],
            array_map(static fn ($item): TelegramMembershipEvidence => $item->evidence, $result->channels),
        );
        self::assertSame(
            ['telegram_membership_left', 'telegram_membership_http_unavailable'],
            array_map(static fn ($item): string => $item->resultCode, $result->channels),
        );
    }

    public function test_any_mode_definitive_member_wins_over_unavailable(): void
    {
        $ownerId = $this->administrator();
        $first = $this->channel('eval-any-first', -1002400000011);
        $second = $this->channel('eval-any-second', -1002400000012);
        $userId = $this->customerWithTelegram(700000011);
        $this->activeRule($ownerId, 'eval-any', [$first, $second], 'any', 'fail_closed');
        $lookup = $this->lookup(static function (int $chatId): TelegramMembershipLookupResult {
            return $chatId === -1002400000011
                ? new TelegramMembershipLookupResult(TelegramMembershipEvidence::Unavailable, 'telegram_membership_api_unavailable')
                : new TelegramMembershipLookupResult(TelegramMembershipEvidence::Member, 'telegram_membership_member');
        });

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $result->decision);
    }

    public function test_all_member_and_any_not_member_complete_truth_tables(): void
    {
        $ownerId = $this->administrator();

        $allFirst = $this->channel('eval-all-member-first', -1002400000051);
        $allSecond = $this->channel('eval-all-member-second', -1002400000052);
        $allUserId = $this->customerWithTelegram(700000051);
        $this->activeRule($ownerId, 'eval-all-member-rule', [$allFirst, $allSecond], 'all', 'fail_closed', 'service_view');
        $memberLookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        ));

        $allResult = $this->evaluator($memberLookup)->evaluate(
            new TelegramChannelMembershipResolutionRequest($allUserId, 'service_view'),
        );

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $allResult->decision);
        self::assertCount(2, $memberLookup->calls);

        $anyFirst = $this->channel('eval-any-left-first', -1002400000061);
        $anySecond = $this->channel('eval-any-left-second', -1002400000062);
        $anyUserId = $this->customerWithTelegram(700000061);
        $this->activeRule($ownerId, 'eval-any-left-rule', [$anyFirst, $anySecond], 'any', 'fail_open', 'support_view');
        $notMemberLookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $anyResult = $this->evaluator($notMemberLookup)->evaluate(
            new TelegramChannelMembershipResolutionRequest($anyUserId, 'support_view'),
        );

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Unsatisfied, $anyResult->decision);
        self::assertCount(2, $notMemberLookup->calls);
    }

    public function test_unresolved_unavailable_evidence_applies_each_configured_failure_policy(): void
    {
        $ownerId = $this->administrator();
        $cases = [
            ['bot_entry', 'fail_open', TelegramChannelMembershipEvaluationDecision::Satisfied, -1002400000101, 700000101],
            ['service_view', 'fail_closed', TelegramChannelMembershipEvaluationDecision::Unsatisfied, -1002400000102, 700000102],
            ['support_view', 'manual_review', TelegramChannelMembershipEvaluationDecision::ManualReview, -1002400000103, 700000103],
        ];

        foreach ($cases as [$action, $policy, $expected, $chatId, $telegramUserId]) {
            $channelId = $this->channel('eval-policy-'.$policy, $chatId);
            $userId = $this->customerWithTelegram($telegramUserId);
            $this->activeRule($ownerId, 'eval-policy-rule-'.$policy, [$channelId], 'all', $policy, $action);
            $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Unavailable,
                'telegram_membership_http_unavailable',
            ));

            $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, $action));

            self::assertSame($expected, $result->decision, $policy);
        }
    }

    /**
     * @return array<string, array{
     *     0:string,
     *     1:TelegramMembershipEvidence,
     *     2:string,
     *     3:TelegramChannelMembershipEvaluationDecision
     * }>
     */
    public static function unresolvedMixedEvidencePolicies(): array
    {
        return [
            'all member plus unavailable fail open' => [
                'all',
                TelegramMembershipEvidence::Member,
                'fail_open',
                TelegramChannelMembershipEvaluationDecision::Satisfied,
            ],
            'all member plus unavailable fail closed' => [
                'all',
                TelegramMembershipEvidence::Member,
                'fail_closed',
                TelegramChannelMembershipEvaluationDecision::Unsatisfied,
            ],
            'all member plus unavailable manual review' => [
                'all',
                TelegramMembershipEvidence::Member,
                'manual_review',
                TelegramChannelMembershipEvaluationDecision::ManualReview,
            ],
            'any not member plus unavailable fail open' => [
                'any',
                TelegramMembershipEvidence::NotMember,
                'fail_open',
                TelegramChannelMembershipEvaluationDecision::Satisfied,
            ],
            'any not member plus unavailable fail closed' => [
                'any',
                TelegramMembershipEvidence::NotMember,
                'fail_closed',
                TelegramChannelMembershipEvaluationDecision::Unsatisfied,
            ],
            'any not member plus unavailable manual review' => [
                'any',
                TelegramMembershipEvidence::NotMember,
                'manual_review',
                TelegramChannelMembershipEvaluationDecision::ManualReview,
            ],
        ];
    }

    #[DataProvider('unresolvedMixedEvidencePolicies')]
    public function test_unresolved_mixed_evidence_applies_each_configured_failure_policy(
        string $matchMode,
        TelegramMembershipEvidence $firstEvidence,
        string $failurePolicy,
        TelegramChannelMembershipEvaluationDecision $expectedDecision,
    ): void {
        $ownerId = $this->administrator();
        $first = $this->channel('eval-mixed-first', -1002400000111);
        $second = $this->channel('eval-mixed-second', -1002400000112);
        $userId = $this->customerWithTelegram(700000111);
        $this->activeRule($ownerId, 'eval-mixed-'.$matchMode.'-'.$failurePolicy, [$first, $second], $matchMode, $failurePolicy);
        $lookup = $this->lookup(static function (int $chatId) use ($firstEvidence): TelegramMembershipLookupResult {
            if ($chatId === -1002400000111) {
                return new TelegramMembershipLookupResult(
                    $firstEvidence,
                    $firstEvidence === TelegramMembershipEvidence::Member
                        ? 'telegram_membership_member'
                        : 'telegram_membership_left',
                );
            }

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Unavailable,
                'telegram_membership_http_unavailable',
            );
        });

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame($expectedDecision, $result->decision);
        self::assertSame([$first, $second], array_map(
            static fn ($item): int => $item->requiredChannelId,
            $result->channels,
        ));
        self::assertSame(
            [$firstEvidence, TelegramMembershipEvidence::Unavailable],
            array_map(static fn ($item): TelegramMembershipEvidence => $item->evidence, $result->channels),
        );
        self::assertCount(2, $lookup->calls);
    }

    public function test_disabled_linked_channel_is_unavailable_without_provider_call(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-disabled', -1002400000201);
        $userId = $this->customerWithTelegram(700000201);
        $this->activeRule($ownerId, 'eval-disabled-rule', [$channelId], 'all', 'manual_review');
        $this->disableChannel($channelId);
        $lookup = $this->lookup(fn (): TelegramMembershipLookupResult => throw new RuntimeException('Disabled channel must not be queried.'));

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::ManualReview, $result->decision);
        self::assertSame([], $lookup->calls);
        self::assertSame(TelegramMembershipEvidence::Unavailable, $result->channels[0]->evidence);
        self::assertSame('telegram_membership_channel_inactive', $result->channels[0]->resultCode);
    }

    public function test_configuration_drift_after_provider_io_never_inherits_fail_open(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-drift', -1002400000301);
        $userId = $this->customerWithTelegram(700000301);
        $this->activeRule($ownerId, 'eval-drift-rule', [$channelId], 'all', 'fail_open');
        $lookup = $this->lookup(function () use ($channelId): TelegramMembershipLookupResult {
            $this->disableChannel($channelId);

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Unavailable,
                'telegram_membership_http_unavailable',
            );
        });

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::ConfigurationChanged, $result->decision);
        self::assertNotSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $result->decision);
        self::assertSame(1, count($lookup->calls));
    }

    public function test_identity_revalidation_precedes_configuration_changed_when_both_drift_during_provider_io(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-combined-drift', -1002400000302);
        $userId = $this->customerWithTelegram(700000302);
        $this->activeRule($ownerId, 'eval-combined-drift-rule', [$channelId], 'all', 'fail_open');
        $lookup = $this->lookup(function () use ($channelId, $userId): TelegramMembershipLookupResult {
            DB::table('telegram_accounts')
                ->where('bot_id', 123456)
                ->where('user_id', $userId)
                ->where('is_bot', false)
                ->update(['telegram_user_id' => 700000399, 'updated_at' => now('UTC')]);
            $this->disableChannel($channelId);

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Member,
                'telegram_membership_member',
            );
        });

        try {
            $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));
            self::fail('Expected identity drift to fail closed before a configuration-changed decision can return.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram membership evaluation identity changed during provider lookup.', $exception->getMessage());
        }
        self::assertSame(
            [['chat_id' => -1002400000302, 'user_id' => 700000302]],
            $lookup->calls,
        );
    }

    public function test_required_evaluation_fails_closed_when_telegram_identity_is_missing(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-identity', -1002400000401);
        $userId = $this->customer();
        $this->activeRule($ownerId, 'eval-identity-rule', [$channelId], 'all', 'fail_open');
        $lookup = $this->lookup(fn (): TelegramMembershipLookupResult => throw new RuntimeException('Provider must not be called.'));

        try {
            $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));
            self::fail('Expected missing Telegram identity to fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram membership evaluation requires a synchronized Telegram identity.', $exception->getMessage());
        }
        self::assertSame([], $lookup->calls);
    }

    public function test_required_evaluation_fails_closed_when_telegram_identity_changes_during_provider_lookup(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-identity-drift', -1002400000402);
        $userId = $this->customerWithTelegram(700000402);
        $this->activeRule($ownerId, 'eval-identity-drift-rule', [$channelId], 'all', 'fail_open');
        $lookup = $this->lookup(function () use ($userId): TelegramMembershipLookupResult {
            DB::table('telegram_accounts')
                ->where('bot_id', 123456)
                ->where('user_id', $userId)
                ->where('is_bot', false)
                ->update(['telegram_user_id' => 700000499, 'updated_at' => now('UTC')]);

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::Member,
                'telegram_membership_member',
            );
        });

        try {
            $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));
            self::fail('Expected changed Telegram identity to fail closed.');
        } catch (DomainException $exception) {
            self::assertSame('Telegram membership evaluation identity changed during provider lookup.', $exception->getMessage());
        }
        self::assertSame(
            [['chat_id' => -1002400000402, 'user_id' => 700000402]],
            $lookup->calls,
        );
    }

    public function test_required_evaluation_executes_only_read_only_sql_and_no_redis_commands(): void
    {
        $ownerId = $this->administrator();
        $channelId = $this->channel('eval-read-only', -1002400000403);
        $userId = $this->customerWithTelegram(700000403);
        $this->activeRule($ownerId, 'eval-read-only-rule', [$channelId], 'all', 'fail_closed');
        $lookup = $this->lookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        ));

        /** @var list<string> $sqlStatements */
        $sqlStatements = [];
        DB::listen(static function (QueryExecuted $query) use (&$sqlStatements): void {
            $sqlStatements[] = $query->sql;
        });

        /** @var list<string> $redisCommands */
        $redisCommands = [];
        foreach (array_keys(Redis::connections()) as $connectionName) {
            Redis::purge($connectionName);
        }
        Redis::enableEvents();
        $redis = Redis::connection('cache');
        $redis->listen(static function (CommandExecuted $command) use (&$redisCommands): void {
            $redisCommands[] = $command->connectionName.':'.strtolower($command->command);
        });
        $redis->command('ping');
        self::assertSame(['cache:ping'], $redisCommands, 'Authenticated Redis command capture must be active before evaluation.');
        $redisCommands = [];

        $result = $this->evaluator($lookup)->evaluate(new TelegramChannelMembershipResolutionRequest($userId, 'bot_entry'));

        self::assertSame(TelegramChannelMembershipEvaluationDecision::Satisfied, $result->decision);
        self::assertNotEmpty($sqlStatements, 'The evaluator must exercise its database read path for this proof.');
        foreach ($sqlStatements as $sql) {
            self::assertMatchesRegularExpression(
                '/^\s*select\b/i',
                $sql,
                'Evaluator executed non-read-only SQL: '.$sql,
            );
        }
        self::assertSame([], $redisCommands, 'Evaluator must not execute Redis/cache commands.');
    }

    private function evaluator(TelegramMembershipLookup $lookup): TelegramChannelMembershipEvaluator
    {
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        $this->app->forgetInstance(TelegramChannelMembershipEvaluator::class);
        $this->app->forgetInstance(TelegramChannelMembershipRuleResolver::class);

        return $this->app->make(TelegramChannelMembershipEvaluator::class);
    }

    /** @param Closure(int,int,int):TelegramMembershipLookupResult $callback */
    private function lookup(Closure $callback): TelegramMembershipEvaluationTestLookup
    {
        return new TelegramMembershipEvaluationTestLookup($callback);
    }

    /** @param list<int> $channelIds */
    private function activeRule(
        int $ownerId,
        string $key,
        array $channelIds,
        string $matchMode,
        string $failurePolicy,
        string $action = 'bot_entry',
    ): int {
        $service = $this->app->make(TelegramChannelMembershipRuleService::class);
        $definition = new TelegramChannelMembershipRuleDefinition(
            $key,
            $action,
            'customers',
            null,
            null,
            null,
            $matchMode,
            $failurePolicy,
            100,
            null,
            null,
            $channelIds,
        );
        $created = $service->create($definition, $this->context($ownerId, 'create-'.$key));
        $service->activate($created->targetId, 1, $this->context($ownerId, 'activate-'.$key));

        return $created->targetId;
    }

    private function channel(string $key, int $chatId): int
    {
        $now = now('UTC');
        $id = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => $chatId,
            'chat_type' => 'channel',
            'visibility' => 'public',
            'display_title' => $key,
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

    private function customerWithTelegram(int $telegramUserId): int
    {
        $userId = $this->customer();
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

        return $userId;
    }

    private function customer(): int
    {
        $now = now('UTC');
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
        $tierId = DB::table('customer_tiers')->where('code', 'normal')->value('id');
        if (! is_int($tierId) && ! is_string($tierId)) {
            throw new RuntimeException('Normal customer tier is unavailable.');
        }
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

    private function administrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->customer(),
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $ownerId, string $suffix): TelegramConfigurationChangeContext
    {
        $this->mutationSequence++;
        $identity = substr(hash('sha256', $suffix.'-'.$this->mutationSequence), 0, 24);

        return new TelegramConfigurationChangeContext(
            'telegram-membership-evaluation-'.$identity,
            'correlation-'.$identity,
            'telegram_membership_evaluation_test',
            'Telegram membership evaluation test fixture.',
            $ownerId,
        );
    }
}
