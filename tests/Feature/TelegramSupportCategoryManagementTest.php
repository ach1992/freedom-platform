<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 SEC-002 CNT-001 CHN-001 QUA-001 QUA-004 QUA-008 */
final class TelegramSupportCategoryManagementTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support category management verification requires MariaDB/MySQL.');
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

    public function test_operator_category_management_is_restart_safe_reauthorized_and_cross_actor_protected(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $operatorTelegramId = 9880;
        $attackerTelegramId = 9881;
        $faOperatorTelegramId = 9882;

        $this->accept($this->payload(8800, $operatorTelegramId, 'category_operator', 'en', '/start'));
        $processor->process('123456789', 8800);
        $operator = $this->account($operatorTelegramId);
        $operatorAdministratorId = $this->grantSupportRole($operator['user_id']);
        $this->enterSupportQueue($processor, $operatorTelegramId, 'category_operator', 'en', $operator['account_id'], 8801, 8802);
        self::assertStringContainsString('/support-categories', $this->latestConfidentialPresentation($operatorTelegramId));

        $this->accept($this->payload(8803, $operatorTelegramId, 'category_operator', 'en', '/support-categories'));
        $processor->process('123456789', 8803);
        $categoryList = $this->latestConfidentialPresentation($operatorTelegramId);
        self::assertStringContainsString('Support categories', $categoryList);
        self::assertStringContainsString('technical_service', $categoryList);
        self::assertStringContainsString('/support-category', $categoryList);

        $versionBeforeName = $this->supportSession($operator['account_id'])['version'];
        $this->accept($this->payload(
            8804,
            $operatorTelegramId,
            'category_operator',
            'en',
            '/support-category technical_service name-en Managed technical support',
        ));
        $processor->process('123456789', 8804);
        self::assertSame(
            'Managed technical support',
            (string) DB::table('support_ticket_categories')->where('code', 'technical_service')->value('name_en'),
        );
        self::assertSame($versionBeforeName + 2, $this->supportSession($operator['account_id'])['version']);
        self::assertStringContainsString('Managed technical support', $this->latestConfidentialPresentation($operatorTelegramId));

        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count();
        $versionAfterName = $this->supportSession($operator['account_id'])['version'];
        $processor->process('123456789', 8804);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count());
        self::assertSame($versionAfterName, $this->supportSession($operator['account_id'])['version']);

        $this->accept($this->payload(
            8805,
            $operatorTelegramId,
            'category_operator',
            'en',
            '/support-category technical_service route technical',
        ));
        $processor->process('123456789', 8805);
        self::assertSame(
            'technical',
            DB::table('support_ticket_categories')->where('code', 'technical_service')->value('route_role_code'),
        );

        $operatorBack = $this->callbackToken('navigation.back', $operator['account_id']);
        $this->accept($this->payload(8806, $attackerTelegramId, 'category_attacker', 'en', '/start'));
        $processor->process('123456789', 8806);
        $this->accept($this->callbackPayload(8807, $attackerTelegramId, 'category_attacker', 'en', $operatorBack));
        $processor->process('123456789', 8807);
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'token_hash' => hash('sha256', $operatorBack),
            'state' => 'pending',
            'accepted_update_id' => null,
        ]);
        self::assertSame('technical', DB::table('support_ticket_categories')->where('code', 'technical_service')->value('route_role_code'));

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $operatorAdministratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'changed_by_administrator_id' => null,
            'reason_code' => null,
            'reason' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $versionBeforeRevokedMutation = $this->supportSession($operator['account_id'])['version'];
        $this->accept($this->payload(
            8808,
            $operatorTelegramId,
            'category_operator',
            'en',
            '/support-category technical_service active off',
        ));
        try {
            $processor->process('123456789', 8808);
            self::fail('Revoked Support authority must fail category mutation at execution time.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertTrue((bool) DB::table('support_ticket_categories')->where('code', 'technical_service')->value('is_active'));
        self::assertSame($versionBeforeRevokedMutation, $this->supportSession($operator['account_id'])['version']);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8808,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);

        $this->accept($this->payload(8809, $faOperatorTelegramId, 'category_fa_operator', 'fa', '/start'));
        $processor->process('123456789', 8809);
        $faOperator = $this->account($faOperatorTelegramId);
        $this->grantSupportRole($faOperator['user_id']);
        $this->enterSupportQueue($processor, $faOperatorTelegramId, 'category_fa_operator', 'fa', $faOperator['account_id'], 8810, 8811);
        $this->accept($this->payload(8812, $faOperatorTelegramId, 'category_fa_operator', 'fa', '/support-categories'));
        $processor->process('123456789', 8812);
        self::assertStringContainsString('دسته‌های پشتیبانی', $this->latestConfidentialPresentation($faOperatorTelegramId));
    }

    private function enterSupportQueue(
        TelegramUpdateProcessor $processor,
        int $telegramUserId,
        string $username,
        string $locale,
        int $accountId,
        int $homeUpdateId,
        int $queueUpdateId,
    ): void {
        $supportHome = $this->callbackToken('navigation.support', $accountId);
        $this->accept($this->callbackPayload($homeUpdateId, $telegramUserId, $username, $locale, $supportHome));
        $processor->process('123456789', $homeUpdateId);
        $queue = $this->callbackToken('navigation.support.queue', $accountId);
        $this->accept($this->callbackPayload($queueUpdateId, $telegramUserId, $username, $locale, $queue));
        $processor->process('123456789', $queueUpdateId);
        self::assertSame('support_queue', $this->supportSession($accountId)['state']);
    }

    /** @return array{account_id:int,user_id:int} */
    private function account(int $telegramUserId): array
    {
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);

        return ['account_id' => (int) $account->id, 'user_id' => (int) $account->user_id];
    }

    /** @return array{state:string,version:int} */
    private function supportSession(int $accountId): array
    {
        $session = DB::table('telegram_interaction_sessions')->where('telegram_account_id', $accountId)->first(['state', 'version']);
        self::assertNotNull($session);

        return ['state' => (string) $session->state, 'version' => (int) $session->version];
    }

    private function grantSupportRole(int $userId): int
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
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => (int) DB::table('roles')->where('code', 'support')->value('id'),
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $administratorId;
    }

    private function callbackToken(string $action, int $accountId, string $expectedActionPayload = '{}'): string
    {
        $callback = DB::table('telegram_interaction_callbacks')
            ->where('telegram_account_id', $accountId)
            ->where('action', $action)
            ->where('action_payload', $expectedActionPayload)
            ->orderByDesc('id')
            ->first(['token_ciphertext']);
        self::assertNotNull($callback, 'Expected Telegram callback was not issued: '.$action.' '.$expectedActionPayload);

        return $this->app->make(StringEncrypter::class)->decryptString((string) $callback->token_ciphertext);
    }

    private function latestConfidentialPresentation(int $telegramUserId): string
    {
        $operationPublicId = DB::table('telegram_delivery_operations')
            ->where('recipient_chat_id', $telegramUserId)
            ->orderByDesc('id')
            ->value('public_id');
        self::assertIsString($operationPublicId);
        $ciphertext = DB::table(TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1::TABLE)
            ->where('delivery_operation_public_id', $operationPublicId)
            ->value('presentation_ciphertext');
        self::assertIsString($ciphertext);

        return $this->app->make(StringEncrypter::class)->decryptString($ciphertext);
    }

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function payload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $text): array
    {
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
    private function callbackPayload(int $updateId, int $telegramUserId, string $username, string $languageCode, string $token): array
    {
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
}
