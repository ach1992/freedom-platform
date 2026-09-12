<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramBotEntryMembershipGateHandler;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionRejected;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Closure;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramBotEntryMembershipRetryLookup implements TelegramMembershipLookup
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

/** @requirement ONB-003 DAT-003 SEC-001 SEC-003 QUA-001 QUA-004 */
final class TelegramBotEntryMembershipRetryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram bot-entry membership retry requires MariaDB/MySQL.');
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
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_retry_callback_member_atomically_hands_gate_to_navigation_and_renders_home(): void
    {
        $this->activeRule('retry-member', 'fail_closed', 'https://t.me/+RetryMember296');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(9101, 99101, 'retry_member', 'en', '/start'));
        $this->processor()->process('123456789', 9101);
        $token = $this->pendingCallbackToken();

        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());

        $lookup->callback = static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        );
        $this->dispatchCallback(9102, 99101, $token);

        self::assertSame(2, count($lookup->calls));
        self::assertSame([0, 0], $lookup->transactionLevels);
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));
        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));
        self::assertSame(3, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('state', 'completed')->count());
    }

    public function test_unsatisfied_retry_refreshes_gate_once_and_callback_replay_cannot_duplicate_effects(): void
    {
        $this->activeRule('retry-unsatisfied', 'fail_closed', 'https://t.me/+RetryUnsatisfied296');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(9201, 99201, 'retry_unsatisfied', 'fa', '/start'));
        $this->processor()->process('123456789', 9201);
        $token = $this->pendingCallbackToken();

        $this->dispatchCallback(9202, 99201, $token);

        $gate = DB::table('telegram_interaction_sessions')
            ->where('active_telegram_account_id', '!=', null)
            ->where('flow', TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW)
            ->first(['version']);
        self::assertNotNull($gate);
        self::assertSame(2, (int) $gate->version);
        self::assertSame(2, count($lookup->calls));
        self::assertSame([0, 0], $lookup->transactionLevels);
        self::assertSame(4, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('state', 'completed')->count());
        self::assertSame(1, DB::table('telegram_interaction_callbacks')->where('state', 'pending')->count());
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));

        $this->dispatchCallback(9202, 99201, $token);

        self::assertSame(2, count($lookup->calls), 'Completed callback replay must not re-evaluate membership.');
        self::assertSame(4, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));
    }

    public function test_repeated_private_menu_while_gated_reuses_retry_path_and_cannot_bypass_membership(): void
    {
        $this->activeRule('retry-message', 'fail_closed', 'https://t.me/+RetryMessage296');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(9301, 99301, 'retry_message', 'fa', '/start'));
        $this->processor()->process('123456789', 9301);
        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));

        $lookup->callback = static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        );
        $this->accept($this->payload(9302, 99301, 'retry_message', 'fa', '/menu'));
        $this->processor()->process('123456789', 9302);

        self::assertSame(2, count($lookup->calls));
        self::assertSame([0, 0], $lookup->transactionLevels);
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));
        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));
        self::assertSame(3, DB::table('telegram_delivery_operations')->count());
    }

    public function test_forged_retry_actor_is_rejected_before_membership_evaluation(): void
    {
        $this->activeRule('retry-forged', 'fail_closed', 'https://t.me/+RetryForged296');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(9401, 99401, 'retry_forged', 'fa', '/start'));
        $this->processor()->process('123456789', 9401);
        $token = $this->pendingCallbackToken();

        try {
            $this->app->make(TelegramInteractionCallbackService::class)->accept(
                '123456789',
                99499,
                $token,
                9402,
            );
            self::fail('Cross-actor membership Retry callback must be rejected.');
        } catch (TelegramInteractionRejected) {
            // Expected: callback actor binding is authoritative before the gate handler runs.
        }

        self::assertSame(1, count($lookup->calls));
        self::assertSame([0], $lookup->transactionLevels);
        self::assertSame(1, $this->activeFlowCount(TelegramNavigationEntryGateway::MEMBERSHIP_GATE_FLOW));
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));
        self::assertSame(2, DB::table('telegram_delivery_operations')->count());
    }

    public function test_cancel_while_gated_uses_existing_session_authority_without_membership_re_evaluation(): void
    {
        $this->activeRule('retry-cancel', 'fail_closed', 'https://t.me/+RetryCancel296');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));

        $this->accept($this->payload(9501, 99501, 'retry_cancel', 'fa', '/start'));
        $this->processor()->process('123456789', 9501);
        $this->accept($this->payload(9502, 99501, 'retry_cancel', 'fa', '/cancel'));
        $this->processor()->process('123456789', 9502);

        self::assertSame(1, count($lookup->calls));
        self::assertSame([0], $lookup->transactionLevels);
        self::assertSame(0, DB::table('telegram_interaction_sessions')->whereNotNull('active_telegram_account_id')->count());
        self::assertSame(0, $this->activeFlowCount(TelegramNavigationEntryGateway::FLOW));
    }

    private function activeFlowCount(string $flow): int
    {
        return (int) DB::table('telegram_interaction_sessions')
            ->where('flow', $flow)
            ->whereNotNull('active_telegram_account_id')
            ->count();
    }

    private function pendingCallbackToken(): string
    {
        $ciphertext = DB::table('telegram_interaction_callbacks')
            ->where('state', 'pending')
            ->orderByDesc('id')
            ->value('token_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }

    private function dispatchCallback(int $updateId, int $telegramUserId, string $token): void
    {
        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['user_id']);
        self::assertNotNull($account);

        $this->app->make(TelegramInteractionDispatcher::class)->dispatch(
            '123456789',
            $updateId,
            (int) $account->user_id,
            ['callback_query' => ['data' => $token]],
        );
    }

    private function activeRule(string $key, string $failurePolicy, string $joinUrl): void
    {
        $now = now('UTC');
        $ciphertext = $this->app->make(StringEncrypter::class)->encryptString($joinUrl);
        $channelId = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => -1002960000001,
            'chat_type' => 'channel',
            'visibility' => 'private',
            'display_title' => 'Membership Retry channel',
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
            'rule_key' => $key,
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
    }

    private function useLookup(Closure $callback): TelegramBotEntryMembershipRetryLookup
    {
        $lookup = new TelegramBotEntryMembershipRetryLookup($callback);
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
                    'id' => $telegramUserId,
                    'type' => 'private',
                ],
                'text' => $text,
            ],
        ];
    }
}
