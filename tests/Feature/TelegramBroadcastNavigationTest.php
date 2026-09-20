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

    public function test_copy_source_kind_is_validated_from_update_and_safe_buttons_preserve_copy_metadata(): void
    {
        $telegramUserId = 920201;
        $username = 'broadcast_copy';
        $processor = $this->app->make(TelegramUpdateProcessor::class);

        $this->accept($this->payload(9220, $telegramUserId, $username, 'fa', '/start'));
        $processor->process('123456789', 9220);
        $account = DB::table('telegram_accounts')
            ->where('telegram_user_id', $telegramUserId)
            ->first(['id', 'user_id']);
        self::assertNotNull($account);
        $this->salesContentAdministrator((int) $account->user_id);

        $this->accept($this->payload(9221, $telegramUserId, $username, 'fa', '/menu'));
        $processor->process('123456789', 9221);
        $admin = $this->callbackToken('navigation.admin', (int) $account->id);
        $this->accept($this->callbackPayload(9222, $telegramUserId, $username, 'fa', $admin));
        $processor->process('123456789', 9222);

        $broadcast = $this->callbackToken('navigation.admin.broadcast', (int) $account->id);
        $this->accept($this->callbackPayload(9223, $telegramUserId, $username, 'fa', $broadcast));
        $processor->process('123456789', 9223);
        $new = $this->callbackToken('navigation.admin.broadcast.new', (int) $account->id);
        $this->accept($this->callbackPayload(9224, $telegramUserId, $username, 'fa', $new));
        $processor->process('123456789', 9224);

        $copy = $this->callbackToken('navigation.admin.broadcast.content.copy', (int) $account->id);
        $this->accept($this->callbackPayload(9225, $telegramUserId, $username, 'fa', $copy));
        $processor->process('123456789', 9225);
        self::assertSame('admin_broadcast_source_kind', $this->sessionState((int) $account->id));

        $photoKind = $this->callbackToken(
            'navigation.admin.broadcast.source_kind',
            (int) $account->id,
            ['source_kind' => 'photo'],
        );
        $this->accept($this->callbackPayload(9226, $telegramUserId, $username, 'fa', $photoKind));
        $processor->process('123456789', 9226);
        self::assertSame('admin_broadcast_source_wait', $this->sessionState((int) $account->id));

        $this->accept($this->sourcePayload(9227, $telegramUserId, $username, 'fa', [
            'sticker' => [
                'file_id' => 'sticker-file',
                'file_unique_id' => 'sticker-unique',
                'type' => 'regular',
                'width' => 64,
                'height' => 64,
                'is_animated' => false,
                'is_video' => false,
            ],
        ]));
        $processor->process('123456789', 9227);
        self::assertSame('admin_broadcast_source_wait', $this->sessionState((int) $account->id));
        self::assertSame(0, DB::table('broadcast_campaigns')->count());

        $this->accept($this->payload(9228, $telegramUserId, $username, 'fa', 'this is text, not a photo'));
        $processor->process('123456789', 9228);
        self::assertSame('admin_broadcast_source_wait', $this->sessionState((int) $account->id));
        self::assertSame(0, DB::table('broadcast_campaigns')->count());

        $this->accept($this->sourcePayload(9229, $telegramUserId, $username, 'fa', [
            'photo' => [[
                'file_id' => 'photo-file',
                'file_unique_id' => 'photo-unique',
                'width' => 640,
                'height' => 480,
                'file_size' => 12345,
            ]],
        ]));
        $processor->process('123456789', 9229);
        self::assertSame('admin_broadcast_audience', $this->sessionState((int) $account->id));

        $campaign = DB::table('broadcast_campaigns')->first(['id', 'public_id']);
        self::assertNotNull($campaign);
        $first = DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', (int) $campaign->id)
            ->where('version', 1)
            ->first(['mode', 'source_kind', 'source_chat_id', 'source_message_id']);
        self::assertNotNull($first);
        self::assertSame('copy', $first->mode);
        self::assertSame('photo', $first->source_kind);
        self::assertSame($telegramUserId, (int) $first->source_chat_id);
        self::assertSame(9229, (int) $first->source_message_id);

        $audienceAll = $this->callbackToken('navigation.admin.broadcast.audience.all', (int) $account->id);
        $this->accept($this->callbackPayload(9230, $telegramUserId, $username, 'fa', $audienceAll));
        $processor->process('123456789', 9230);
        self::assertSame('admin_broadcast_review', $this->sessionState((int) $account->id));

        $buttons = $this->callbackToken('navigation.admin.broadcast.buttons', (int) $account->id);
        $this->accept($this->callbackPayload(9231, $telegramUserId, $username, 'fa', $buttons));
        $processor->process('123456789', 9231);
        self::assertSame('admin_broadcast_buttons_input', $this->sessionState((int) $account->id));

        $this->accept($this->payload(
            9232,
            $telegramUserId,
            $username,
            'fa',
            'support_contact | پشتیبانی | https://t.me/example_support',
        ));
        $processor->process('123456789', 9232);
        self::assertSame('admin_broadcast_review', $this->sessionState((int) $account->id));

        $replacement = DB::table('broadcast_message_versions')
            ->where('broadcast_campaign_id', (int) $campaign->id)
            ->where('version', 2)
            ->first(['mode', 'source_kind', 'source_chat_id', 'source_message_id', 'inline_keyboard_snapshot']);
        self::assertNotNull($replacement);
        self::assertSame('copy', $replacement->mode);
        self::assertSame('photo', $replacement->source_kind);
        self::assertSame($telegramUserId, (int) $replacement->source_chat_id);
        self::assertSame(9229, (int) $replacement->source_message_id);
        self::assertIsString($replacement->inline_keyboard_snapshot);
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

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function sourcePayload(
        int $updateId,
        int $telegramUserId,
        string $username,
        string $languageCode,
        array $content,
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
                ...$content,
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

    /** @param array<string,mixed>|null $actionPayload */
    private function callbackToken(string $action, int $telegramAccountId, ?array $actionPayload = null): string
    {
        $sessionId = DB::table('telegram_interaction_sessions')
            ->where('telegram_account_id', $telegramAccountId)
            ->value('id');
        self::assertIsNumeric($sessionId);
        $query = DB::table('telegram_interaction_callbacks')
            ->where('telegram_interaction_session_id', (int) $sessionId)
            ->where('action', $action);
        if ($actionPayload !== null) {
            $query->where('action_payload', json_encode($actionPayload, JSON_THROW_ON_ERROR));
        }
        $callback = $query
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
