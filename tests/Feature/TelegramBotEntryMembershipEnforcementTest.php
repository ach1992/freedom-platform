<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramBotEntryMembershipGateHandler;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipResolutionRequest;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Closure;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramBotEntryMembershipTestLookup implements TelegramMembershipLookup
{
    /** @var list<array{chat_id:int,user_id:int}> */
    public array $calls = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function __construct(public Closure $callback) {}

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        $this->calls[] = ['chat_id' => $chatId, 'user_id' => $telegramUserId];
        $this->transactionLevels[] = DB::connection()->transactionLevel();

        return ($this->callback)($chatId, $telegramUserId, count($this->calls));
    }
}

/** @requirement ONB-003 CHN-001 SEC-001 SEC-003 DAT-003 QUA-001 QUA-004 */
final class TelegramBotEntryMembershipEnforcementTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram bot-entry membership enforcement requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed();
        Queue::fake();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => self::SECRET,
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection()->getDriverName() === 'mysql') {
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_bot_entry_membership_fail_processed');
            }
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_no_rule_satisfied_and_fail_open_allow_start_or_menu_before_normal_navigation(): void
    {
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => throw new RuntimeException('No-rule entry must not call Telegram membership provider.'));
        $this->accept($this->payload(8101, 98101, 'entry_no_rule', 'fa', '/start'));
        $this->processor()->process('123456789', 8101);

        self::assertSame([], $lookup->calls);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());

        $this->activeRule('allow-entry', 'fail_open', 'https://t.me/+AllowEntrySecret');
        $lookup = $this->useLookup(static function (int $chatId, int $telegramUserId): TelegramMembershipLookupResult {
            return $telegramUserId === 98102
                ? new TelegramMembershipLookupResult(TelegramMembershipEvidence::Member, 'telegram_membership_member')
                : new TelegramMembershipLookupResult(TelegramMembershipEvidence::Unavailable, 'telegram_membership_http_unavailable');
        });

        $this->accept($this->payload(8102, 98102, 'entry_satisfied', 'en', '/menu'));
        $this->processor()->process('123456789', 8102);
        $this->accept($this->payload(8103, 98103, 'entry_fail_open', 'fa', '/start'));
        $this->processor()->process('123456789', 8103);

        self::assertSame(
            [
                ['chat_id' => -1002940000001, 'user_id' => 98102],
                ['chat_id' => -1002940000001, 'user_id' => 98103],
            ],
            $lookup->calls,
        );
        self::assertSame([0, 0], $lookup->transactionLevels, 'Membership provider I/O must run outside database transactions/locks.');
        self::assertSame(3, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(3, DB::table('telegram_delivery_operations')->count());
    }

    public function test_unsatisfied_entry_creates_gate_and_retry_without_join_secret_leakage(): void
    {
        $privateUrl = 'https://t.me/+BotEntryPrivateSecret294';
        [, $ciphertext] = $this->activeRule('blocked-entry', 'fail_closed', $privateUrl);
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(8201, 98201, 'entry_blocked', 'fa', '/start'));
        $processor = $this->processor();
        $processor->process('123456789', 8201);
        $processor->process('123456789', 8201);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 98201)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $plan = $this->app->make(TelegramChannelMembershipRuleResolver::class)->resolve(
            new TelegramChannelMembershipResolutionRequest((int) $account->user_id, 'bot_entry', null),
        );
        $expectedReference = TelegramProtectedPresentationReference::membershipJoinPrompt(
            'bot_entry',
            null,
            $plan->configurationHash,
            'fa',
        )->durableText();

        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::FLOW)->count());
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 8201)->count());

        $operation = DB::table('telegram_delivery_operations')->where('presentation_text', $expectedReference)->first();
        self::assertNotNull($operation);
        self::assertSame(98201, (int) $operation->recipient_chat_id);
        self::assertSame(hash('sha256', 'telegram-entry-membership-join:123456789:8201'), (string) $operation->request_key_hash);
        self::assertSame(1, count($lookup->calls));
        self::assertSame([0], $lookup->transactionLevels);

        $durableSurfaces = implode("\n", array_merge(
            DB::table('telegram_delivery_operations')->pluck('presentation_text')->map(static fn (mixed $value): string => (string) $value)->all(),
            DB::table('telegram_delivery_operations')->pluck('request_fingerprint')->map(static fn (mixed $value): string => (string) $value)->all(),
            DB::table('outbox_messages')->pluck('payload')->map(static fn (mixed $value): string => (string) $value)->all(),
            DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->pluck('keyboard_snapshot')->map(static fn (mixed $value): string => (string) $value)->all(),
        ));
        self::assertStringNotContainsString($privateUrl, $durableSurfaces);
        self::assertStringNotContainsString($ciphertext, $durableSurfaces);
        self::assertSame(1, DB::table(TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE)->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('state', 'pending')->count());
    }

    public function test_fail_closed_manual_review_and_configuration_change_all_fail_closed_without_stale_join_authority(): void
    {
        [$failClosedRule] = $this->activeRule('fail-closed-entry', 'fail_closed', 'https://t.me/+FailClosed294');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Unavailable,
            'telegram_membership_http_unavailable',
        ));
        $this->accept($this->payload(8301, 98301, 'entry_fail_closed', 'fa', '/start'));
        $this->processor()->process('123456789', 8301);

        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::FLOW)->count());
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());
        self::assertTrue(DB::table('telegram_delivery_operations')->where('presentation_text', 'like', '[PROTECTED_TELEGRAM_REFERENCE:v2:membership_join_prompt:bot_entry:-:%')->exists());

        $this->disableRule($failClosedRule);
        [$manualReviewRule] = $this->activeRule('manual-review-entry', 'manual_review', 'https://t.me/+ManualReview294');
        $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Unavailable,
            'telegram_membership_http_unavailable',
        ));
        $this->accept($this->payload(8302, 98302, 'entry_manual_review', 'en', '/menu'));
        $this->processor()->process('123456789', 8302);

        self::assertSame(2, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        $manual = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 98302)->first();
        self::assertNotNull($manual);
        self::assertSame(trans('telegram_membership.entry_unavailable', locale: 'en'), (string) $manual->presentation_text);
        self::assertStringNotContainsString('PROTECTED_TELEGRAM_REFERENCE', (string) $manual->presentation_text);

        $this->disableRule($manualReviewRule);
        [$configurationRule] = $this->activeRule('configuration-change-entry', 'fail_closed', 'https://t.me/+ConfigurationChanged294');
        $lookup = $this->useLookup(static function () use ($configurationRule): TelegramMembershipLookupResult {
            DB::table('channel_membership_rules')->where('id', $configurationRule)->update([
                'state' => 'disabled',
                'version' => 3,
                'updated_at' => now('UTC'),
            ]);

            return new TelegramMembershipLookupResult(
                TelegramMembershipEvidence::NotMember,
                'telegram_membership_left',
            );
        });
        $this->accept($this->payload(8303, 98303, 'entry_config_changed', 'fa', '/start'));
        $this->processor()->process('123456789', 8303);

        self::assertSame([0], $lookup->transactionLevels);
        self::assertSame(3, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        $changed = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 98303)->first();
        self::assertNotNull($changed);
        self::assertSame(trans('telegram_membership.entry_unavailable', locale: 'fa'), (string) $changed->presentation_text);
        self::assertStringNotContainsString('PROTECTED_TELEGRAM_REFERENCE', (string) $changed->presentation_text);

        $this->activeRule('ambiguous-entry-a', 'fail_closed', 'https://t.me/+AmbiguousA294');
        $this->activeRule('ambiguous-entry-b', 'fail_closed', 'https://t.me/+AmbiguousB294');
        $ambiguousLookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => throw new RuntimeException('Ambiguous configuration must fail before provider lookup.'));
        $this->accept($this->payload(8304, 98304, 'entry_ambiguous', 'fa', '/menu'));
        $this->processor()->process('123456789', 8304);

        self::assertSame([], $ambiguousLookup->calls);
        self::assertSame(4, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::FLOW)->count());
        $ambiguous = DB::table('telegram_delivery_operations')->where('recipient_chat_id', 98304)->first();
        self::assertNotNull($ambiguous);
        self::assertSame(trans('telegram_membership.entry_unavailable', locale: 'fa'), (string) $ambiguous->presentation_text);
        self::assertStringNotContainsString('PROTECTED_TELEGRAM_REFERENCE', (string) $ambiguous->presentation_text);
    }

    public function test_forged_cross_actor_and_non_private_entry_never_reach_membership_provider_or_create_delivery_or_session(): void
    {
        $this->activeRule('actor-binding-entry', 'fail_closed', 'https://t.me/+ActorBinding294');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => throw new RuntimeException('Invalid actor input must not reach membership provider.'));

        $this->accept($this->payload(8401, 98401, 'entry_group', 'fa', '/start', 'group', -10098401));
        $this->processor()->process('123456789', 8401);

        $this->accept($this->payload(8402, 98402, 'entry_actor', 'fa', 'hello'));
        $this->processor()->process('123456789', 8402);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', 98402)->first(['id', 'user_id', 'telegram_user_id']);
        self::assertNotNull($account);
        $forged = $this->payload(8403, 98499, 'entry_forged', 'fa', '/menu');
        $forged['message']['chat']['id'] = 98402;
        $started = $this->app->make(TelegramNavigationEntryGateway::class)->startIfEligible(
            '123456789',
            8403,
            (int) $account->user_id,
            (int) $account->id,
            (int) $account->telegram_user_id,
            $forged['message'],
            '/menu',
        );

        self::assertFalse($started);
        self::assertSame([], $lookup->calls);
        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
    }

    public function test_failed_post_dispatch_retry_reuses_blocked_binding_without_re_evaluating_membership(): void
    {
        $this->activeRule('replay-entry', 'fail_closed', 'https://t.me/+Replay294');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));
        $this->accept($this->payload(8501, 98501, 'entry_replay', 'fa', '/start'));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_bot_entry_membership_fail_processed
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 8501 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-membership-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $this->processor()->process('123456789', 8501);
                self::fail('The simulated post-dispatch failure must keep the update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_bot_entry_membership_fail_processed');
        }

        self::assertSame(1, count($lookup->calls));
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::FLOW)->count());
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 8501)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 8501, 'state' => 'failed', 'attempt_count' => 1]);

        $lookup->callback = static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        );
        $this->processor()->process('123456789', 8501);

        self::assertSame(1, count($lookup->calls), 'A durable blocked update binding must prevent membership re-evaluation on replay.');
        self::assertSame(1, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)->count());
        self::assertSame(0, DB::table('telegram_interaction_sessions')->where('flow', TelegramNavigationEntryGateway::FLOW)->count());
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 8501, 'state' => 'processed', 'attempt_count' => 2]);
    }

    /** @return array{int,string} */
    private function activeRule(string $key, string $failurePolicy, string $joinUrl): array
    {
        $this->fixtureSequence++;
        $now = now('UTC');
        $ciphertext = $this->app->make(StringEncrypter::class)->encryptString($joinUrl);
        $channelId = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key.'-'.$this->fixtureSequence,
            'telegram_chat_id' => -1002940000000 - $this->fixtureSequence,
            'chat_type' => 'channel',
            'visibility' => 'private',
            'display_title' => 'Required channel '.$this->fixtureSequence,
            'join_url_ciphertext' => $ciphertext,
            'join_url_hash' => hash('sha256', $joinUrl),
            'sort_order' => 0,
            'state' => 'draft',
            'version' => 1,
            'verified_bot_id' => null,
            'verification_result_code' => null,
            'verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('required_channels')->where('id', $channelId)->update([
            'state' => 'active',
            'version' => 2,
            'verified_bot_id' => 123456789,
            'verification_result_code' => 'telegram_membership_administrator',
            'verified_at' => $now,
            'updated_at' => $now,
        ]);

        $ruleId = (int) DB::table('channel_membership_rules')->insertGetId([
            'rule_key' => $key.'-'.$this->fixtureSequence,
            'action' => 'bot_entry',
            'audience' => 'customers',
            'tier_code' => null,
            'customer_tag_id' => null,
            'plan_offering_id' => null,
            'match_mode' => 'all',
            'failure_policy' => $failurePolicy,
            'priority' => 100,
            'effective_from' => null,
            'effective_until' => null,
            'state' => 'draft',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('channel_membership_rule_channels')->insert([
            'channel_membership_rule_id' => $ruleId,
            'required_channel_id' => $channelId,
            'sort_order' => 0,
            'created_at' => $now,
        ]);
        DB::table('channel_membership_rules')->where('id', $ruleId)->update([
            'state' => 'active',
            'version' => 2,
            'updated_at' => $now,
        ]);

        return [$ruleId, $ciphertext];
    }

    private function disableRule(int $ruleId): void
    {
        $version = (int) DB::table('channel_membership_rules')->where('id', $ruleId)->value('version');
        DB::table('channel_membership_rules')->where('id', $ruleId)->update([
            'state' => 'disabled',
            'version' => $version + 1,
            'updated_at' => now('UTC'),
        ]);
    }

    private function useLookup(Closure $callback): TelegramBotEntryMembershipTestLookup
    {
        $lookup = new TelegramBotEntryMembershipTestLookup($callback);
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        foreach ([
            TelegramChannelMembershipEvaluator::class,
            TelegramNavigationEntryGateway::class,
            TelegramBotEntryMembershipGateHandler::class,
            TelegramInteractionHandlerRegistry::class,
            TelegramInteractionDispatcher::class,
            TelegramUpdateProcessor::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        return $lookup;
    }

    private function processor(): TelegramUpdateProcessor
    {
        return $this->app->make(TelegramUpdateProcessor::class);
    }

    /** @param array<string, mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function payload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $text,
        string $chatType = 'private',
        ?int $chatId = null,
    ): array {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_700_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'chat' => [
                    'id' => $chatId ?? $telegramUserId,
                    'type' => $chatType,
                ],
                'text' => $text,
            ],
        ];
    }
}
