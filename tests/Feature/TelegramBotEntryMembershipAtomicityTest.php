<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Closure;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class TelegramBotEntryMembershipAtomicityLookup implements TelegramMembershipLookup
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

/** @requirement ONB-003 DAT-003 QUA-004 SEC-003 */
final class TelegramBotEntryMembershipAtomicityTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram bot-entry membership atomicity requires MariaDB/MySQL.');
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
                DB::unprepared('DROP TRIGGER IF EXISTS telegram_bot_entry_membership_fail_binding');
            }
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_blocked_effect_rolls_back_with_failed_binding_before_retry_outcome_can_drift(): void
    {
        $this->activeRule('atomic-blocked-entry', 'fail_closed', 'https://t.me/+AtomicBlocked294');
        $lookup = $this->useLookup(static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::NotMember,
            'telegram_membership_left',
        ));
        $this->accept($this->payload(8601, 98601, 'entry_atomicity', 'fa', '/start'));

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_bot_entry_membership_fail_binding
BEFORE INSERT ON telegram_interaction_update_bindings
FOR EACH ROW
BEGIN
    IF NEW.bot_id = '123456789' AND NEW.update_id = 8601 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-entry-binding-failure';
    END IF;
END
SQL);
        try {
            try {
                $this->processor()->process('123456789', 8601);
                self::fail('The simulated binding failure must keep the update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_bot_entry_membership_fail_binding');
        }

        self::assertSame(1, count($lookup->calls));
        self::assertSame([0], $lookup->transactionLevels, 'Membership provider I/O must remain outside database transactions.');
        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->count(), 'Blocked delivery must not survive without its durable update binding.');
        self::assertSame(0, DB::table('telegram_interaction_update_bindings')->where('update_id', 8601)->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 8601, 'state' => 'failed', 'attempt_count' => 1]);

        $lookup->callback = static fn (): TelegramMembershipLookupResult => new TelegramMembershipLookupResult(
            TelegramMembershipEvidence::Member,
            'telegram_membership_member',
        );
        $this->processor()->process('123456789', 8601);

        self::assertSame(2, count($lookup->calls));
        self::assertSame([0, 0], $lookup->transactionLevels);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 8601)->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        $presentation = (string) DB::table('telegram_delivery_operations')->value('presentation_text');
        self::assertStringNotContainsString('PROTECTED_TELEGRAM_REFERENCE', $presentation);
        self::assertNotSame(trans('telegram_membership.entry_unavailable', locale: 'fa'), $presentation);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 8601, 'state' => 'processed', 'attempt_count' => 2]);
    }

    private function activeRule(string $key, string $failurePolicy, string $joinUrl): void
    {
        $now = now('UTC');
        $ciphertext = $this->app->make(StringEncrypter::class)->encryptString($joinUrl);
        $channelId = (int) DB::table('required_channels')->insertGetId([
            'channel_key' => $key,
            'telegram_chat_id' => -1002940000861,
            'chat_type' => 'channel',
            'visibility' => 'private',
            'display_title' => 'Atomic membership channel',
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

    private function useLookup(Closure $callback): TelegramBotEntryMembershipAtomicityLookup
    {
        $lookup = new TelegramBotEntryMembershipAtomicityLookup($callback);
        $this->app->instance(TelegramMembershipLookup::class, $lookup);
        foreach ([
            TelegramChannelMembershipEvaluator::class,
            TelegramNavigationEntryGateway::class,
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
