<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** @requirement ONB-002 ONB-003 ARCH-003 ARCH-004 DAT-003 SEC-003 OPS-003 QUA-004 */
final class TelegramNavigationEntryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram navigation entry verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
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
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_start_creates_one_shared_navigation_session_and_one_persian_delivery_on_exact_replay(): void
    {
        $this->accept($this->payload(6101, 9601, 'navigation_fa', 'fa', '/start referral_1'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $processor->process('123456789', 6101);
        $processor->process('123456789', 6101);

        $account = DB::table('telegram_accounts')->where('telegram_user_id', 9601)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->assertDatabaseHas('users', ['id' => (int) $account->user_id, 'locale' => 'fa']);
        $this->assertDatabaseHas('telegram_interaction_sessions', [
            'telegram_account_id' => (int) $account->id,
            'flow' => TelegramNavigationEntryGateway::FLOW,
            'state' => TelegramNavigationEntryGateway::STATE,
            'status' => 'active',
            'version' => 1,
        ]);
        $sessionId = (int) DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->value('id');
        $this->assertDatabaseHas('telegram_interaction_update_bindings', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'telegram_interaction_session_id' => $sessionId,
            'session_version' => 1,
            'kind' => 'message',
        ]);

        $operation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($operation);
        self::assertSame('send', (string) $operation->action);
        self::assertSame(9601, (int) $operation->recipient_chat_id);
        self::assertSame(
            trans('telegram.navigation.home', locale: 'fa'),
            (string) $operation->presentation_text,
        );
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)
            ->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 6101,
            'state' => 'processed',
            'attempt_count' => 1,
        ]);
    }

    public function test_menu_uses_english_copy_for_an_english_synchronized_user(): void
    {
        $this->accept($this->payload(6102, 9602, 'navigation_en', 'en', '/menu@FreedomBot'));

        $this->app->make(TelegramUpdateProcessor::class)->process('123456789', 6102);

        $this->assertDatabaseHas('users', ['locale' => 'en']);
        $operation = DB::table('telegram_delivery_operations')->first();
        self::assertNotNull($operation);
        self::assertSame(
            trans('telegram.navigation.home', locale: 'en'),
            (string) $operation->presentation_text,
        );
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
    }

    public function test_arbitrary_text_and_non_private_start_do_not_implicitly_create_navigation_authority(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(6103, 9603, 'navigation_ignored', 'fa', 'hello'));
        $processor->process('123456789', 6103);

        $this->accept($this->payload(6104, 9604, 'navigation_group', 'fa', '/start', 'group', -1009604));
        $processor->process('123456789', 6104);

        self::assertSame(0, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(0, DB::table('telegram_delivery_operations')->count());
        self::assertSame(0, DB::table('outbox_messages')
            ->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)
            ->count());
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6103, 'state' => 'processed']);
        $this->assertDatabaseHas('processed_telegram_updates', ['update_id' => 6104, 'state' => 'processed']);
    }

    public function test_post_dispatch_failure_replays_the_same_session_and_delivery_operation_without_duplication(): void
    {
        $this->accept($this->payload(6105, 9605, 'navigation_retry', 'fa', '/start'));
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER telegram_navigation_test_fail_processed_6105
BEFORE UPDATE ON processed_telegram_updates
FOR EACH ROW
BEGIN
    IF OLD.bot_id = '123456789' AND OLD.update_id = 6105 AND NEW.state = 'processed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'simulated-post-navigation-dispatch-failure';
    END IF;
END
SQL);
        try {
            try {
                $processor->process('123456789', 6105);
                self::fail('The simulated post-dispatch failure must keep the navigation update retryable.');
            } catch (RuntimeException $exception) {
                self::assertSame('Telegram update processing failed.', $exception->getMessage());
            }
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS telegram_navigation_test_fail_processed_6105');
        }

        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6105,
            'state' => 'failed',
            'attempt_count' => 1,
        ]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());

        $firstSessionId = (int) DB::table('telegram_interaction_sessions')->value('id');
        $firstOperationId = (string) DB::table('telegram_delivery_operations')->value('public_id');

        $processor->process('123456789', 6105);

        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 6105,
            'state' => 'processed',
            'attempt_count' => 2,
        ]);
        self::assertSame(1, DB::table('telegram_interaction_sessions')->count());
        self::assertSame($firstSessionId, (int) DB::table('telegram_interaction_sessions')->value('id'));
        self::assertSame(1, DB::table('telegram_interaction_update_bindings')->where('update_id', 6105)->count());
        self::assertSame(1, DB::table('telegram_delivery_operations')->count());
        self::assertSame($firstOperationId, (string) DB::table('telegram_delivery_operations')->value('public_id'));
        self::assertSame(1, DB::table('outbox_messages')
            ->where('event_type', TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE)
            ->count());
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
