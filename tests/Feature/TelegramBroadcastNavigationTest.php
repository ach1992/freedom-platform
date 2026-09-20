<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** @requirement COM-002 COM-003 ADM-001 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 SEC-003 LOC-001 OPS-003 QUA-001 QUA-004 */
final class TelegramBroadcastNavigationTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram broadcast navigation verification requires MariaDB/MySQL.');
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
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_sales_content_admin_can_build_text_campaign_to_review_without_session_content_leak(): void
    {
        $telegramUserId = 920001;
        $username = 'broadcast_admin';
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(9200, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', 9200);

        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->salesContentAdministrator((int) $account->user_id);

        $this->accept($this->payload(9201, $telegramUserId, $username, 'fa', '/menu'));
        $processor->process('123456789', 9201);

        $admin = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(9202, $telegramUserId, $username, 'fa', $admin));
        $processor->process('123456789', 9202);
        self::assertSame('admin_control', $this->sessionState((int) $account->id));

        $broadcast = $this->callbackToken('navigation.admin.broadcast', (int) $account->id);
        $this->accept($this->callbackPayload(9203, $telegramUserId, $username, 'fa', $broadcast));
        $processor->process('123456789', 9203);
        self::assertSame('admin_broadcast_home', $this->sessionState((int) $account->id));
        self::assertStringContainsString('مدیریت ارسال همگانی', $this->latestConfidentialPresentation());

        $new = $this->callbackToken('navigation.admin.broadcast.new', (int) $account->id);
        $this->accept($this->callbackPayload(9204, $telegramUserId, $username, 'fa', $new));
        $processor->process('123456789', 9204);
        self::assertSame('admin_broadcast_content_mode', $this->sessionState((int) $account->id));

        $textMode = $this->callbackToken('navigation.admin.broadcast.content.text', (int) $account->id);
        $this->accept($this->callbackPayload(9205, $telegramUserId, $username, 'fa', $textMode));
        $processor->process('123456789', 9205);
        self::assertSame('admin_broadcast_text_input', $this->sessionState((int) $account->id));

        $campaignText = 'پیام محرمانه کمپین تست که نباید داخل session ذخیره شود';
        $this->accept($this->payload(9206, $telegramUserId, $username, 'fa', $campaignText));
        $processor->process('123456789', 9206);
        self::assertSame('admin_broadcast_audience', $this->sessionState((int) $account->id));
        self::assertSame(1, DB::table('broadcast_campaigns')->count());
        self::assertSame($campaignText, DB::table('broadcast_message_versions')->value('text'));

        $sessionPayload = (string) DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', (int) $account->id)
            ->value('payload');
        self::assertStringNotContainsString($campaignText, $sessionPayload);
        self::assertMatchesRegularExpression(
            '/\A\{"campaign":"[0-9A-HJKMNP-TV-Z]{26}"\}\z/',
            $sessionPayload,
        );

        $audienceAll = $this->callbackToken('navigation.admin.broadcast.audience.all', (int) $account->id);
        $this->accept($this->callbackPayload(9207, $telegramUserId, $username, 'fa', $audienceAll));
        $processor->process('123456789', 9207);

        self::assertSame('admin_broadcast_review', $this->sessionState((int) $account->id));
        self::assertSame(1, (int) DB::table('broadcast_audiences')->value('estimated_recipient_count'));
        self::assertStringContainsString('پیش‌نمایش آماده است.', $this->latestConfidentialPresentation());
        self::assertStringContainsString($campaignText, $this->latestConfidentialPresentation());
        self::assertSame(0, DB::table('broadcast_recipients')->count());
        self::assertSame(0, DB::table('broadcast_campaign_tests')->count());
    }

    public function test_broadcast_entry_disappears_immediately_after_role_revocation(): void
    {
        $telegramUserId = 920101;
        $username = 'broadcast_revoke';
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(9210, $telegramUserId, $username, 'en', '/start'));
        $processor->process('123456789', 9210);
        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($account);
        $assignmentId = $this->salesContentAdministrator((int) $account->user_id);

        $this->accept($this->payload(9211, $telegramUserId, $username, 'en', '/menu'));
        $processor->process('123456789', 9211);
        $admin = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(9212, $telegramUserId, $username, 'en', $admin));
        $processor->process('123456789', 9212);

        $broadcast = $this->callbackToken('navigation.admin.broadcast', (int) $account->id);
        DB::table('administrator_role_assignments')
            ->where('id', $assignmentId)
            ->update([
                'revoked_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

        $this->accept($this->callbackPayload(9213, $telegramUserId, $username, 'en', $broadcast));
        $processor->process('123456789', 9213);

        self::assertSame('home', $this->sessionState((int) $account->id));
        self::assertSame(0, DB::table('broadcast_campaigns')->count());
        self::assertSame(
            0,
            DB::table('telegram_interaction_callbacks')
                ->where('telegram_account_id', (int) $account->id)
                ->where('action', 'navigation.admin.broadcast.new')
                ->count(),
        );
    }

    private function salesContentAdministrator(int $userId): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = DB::table('roles')
            ->where('code', 'sales_content')
            ->where('is_active', true)
            ->value('id');
        self::assertIsNumeric($roleId);

        return (int) DB::table('administrator_role_assignments')->insertGetId([
            'administrator_id' => $administratorId,
            'role_id' => (int) $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
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
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function callbackPayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        string $token,
    ): array {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'callback-'.$updateId,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => $username,
                    'language_code' => $languageCode,
                ],
                'message' => [
                    'message_id' => $updateId,
                    'date' => 1_700_000_000,
                    'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                ],
                'data' => $token,
            ],
        ];
    }

    private function callbackToken(string $action, int $telegramAccountId): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback);

        return $this->app->make(StringEncrypter::class)
            ->decryptString((string) $callback->token_ciphertext);
    }

    private function sessionState(int $telegramAccountId): string
    {
        $state = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('state');
        self::assertIsString($state);

        return $state;
    }

    private function latestConfidentialPresentation(): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')
            ->orderByDesc('id')
            ->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }
}
