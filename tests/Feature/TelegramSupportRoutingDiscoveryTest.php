<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 SEC-002 QUA-001 QUA-004 */
final class TelegramSupportRoutingDiscoveryTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support routing verification requires MariaDB/MySQL.');
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

    public function test_operator_exact_search_and_assignment_transfer_are_restart_safe_and_reauthorized(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9860;
        $operatorTelegramId = 9861;
        $targetTelegramId = 9862;
        $unprivilegedTelegramId = 9863;

        $this->accept($this->payload(8600, $customerTelegramId, 'routing_customer', 'en', '/start'));
        $processor->process('123456789', 8600);
        $customer = $this->account($customerTelegramId);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Routing discovery ticket',
            'Search and assignment must remain permission gated.',
            'telegram-routing:create',
        ));

        $this->accept($this->payload(8601, $operatorTelegramId, 'routing_operator', 'en', '/start'));
        $processor->process('123456789', 8601);
        $operator = $this->account($operatorTelegramId);
        $operatorAdministratorId = $this->grantSupportRole($operator['user_id']);

        $supportHome = $this->callbackToken('navigation.support', $operator['account_id']);
        $this->accept($this->callbackPayload(8602, $operatorTelegramId, 'routing_operator', 'en', $supportHome));
        $processor->process('123456789', 8602);
        $queue = $this->callbackToken('navigation.support.queue', $operator['account_id']);
        $this->accept($this->callbackPayload(8603, $operatorTelegramId, 'routing_operator', 'en', $queue));
        $processor->process('123456789', 8603);
        self::assertSame('support_queue', $this->supportSession($operator['account_id'])['state']);
        self::assertStringContainsString('/support-search', $this->latestConfidentialPresentation($operatorTelegramId));

        $this->accept($this->payload(
            8604,
            $operatorTelegramId,
            'routing_operator',
            'en',
            '/support-search tracking '.strtolower($ticket->trackingNumber),
        ));
        $processor->process('123456789', 8604);
        $searchPresentation = $this->latestConfidentialPresentation($operatorTelegramId);
        self::assertStringContainsString('Exact search results', $searchPresentation);
        self::assertStringContainsString($ticket->trackingNumber, $searchPresentation);
        self::assertStringContainsString('Routing discovery ticket', $searchPresentation);

        $searchTicket = $this->callbackToken(
            'navigation.support.queue.ticket',
            $operator['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );
        $this->accept($this->callbackPayload(8605, $operatorTelegramId, 'routing_operator', 'en', $searchTicket));
        $processor->process('123456789', 8605);
        self::assertSame('support_queue_ticket', $this->supportSession($operator['account_id'])['state']);
        self::assertStringContainsString('/support-assign', $this->latestConfidentialPresentation($operatorTelegramId));

        $this->accept($this->payload(8606, $targetTelegramId, 'routing_target', 'en', '/start'));
        $processor->process('123456789', 8606);
        $target = $this->account($targetTelegramId);
        $this->grantSupportRole($target['user_id']);

        $versionBeforeAssignment = $this->supportSession($operator['account_id'])['version'];
        $this->accept($this->payload(
            8607,
            $operatorTelegramId,
            'routing_operator',
            'en',
            '/support-assign '.$target['user_id'],
        ));
        $processor->process('123456789', 8607);
        self::assertSame($target['user_id'], (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        self::assertSame($versionBeforeAssignment + 2, $this->supportSession($operator['account_id'])['version']);
        self::assertStringContainsString('#'.$target['user_id'], $this->latestConfidentialPresentation($operatorTelegramId));

        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count();
        $sessionVersion = $this->supportSession($operator['account_id'])['version'];
        $processor->process('123456789', 8607);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count());
        self::assertSame($sessionVersion, $this->supportSession($operator['account_id'])['version']);
        self::assertSame($target['user_id'], (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));

        $this->accept($this->payload(8608, $unprivilegedTelegramId, 'routing_unprivileged', 'en', '/start'));
        $processor->process('123456789', 8608);
        $unprivileged = $this->account($unprivilegedTelegramId);
        $versionBeforeRejectedTarget = $this->supportSession($operator['account_id'])['version'];
        $this->accept($this->payload(
            8609,
            $operatorTelegramId,
            'routing_operator',
            'en',
            '/support-assign '.$unprivileged['user_id'],
        ));
        try {
            $processor->process('123456789', 8609);
            self::fail('Assignment to a user without active Support authority must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame($target['user_id'], (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        self::assertSame($versionBeforeRejectedTarget, $this->supportSession($operator['account_id'])['version']);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8609,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);

        $back = $this->callbackToken('navigation.back', $operator['account_id']);
        $this->accept($this->callbackPayload(8610, $operatorTelegramId, 'routing_operator', 'en', $back));
        $processor->process('123456789', 8610);
        self::assertSame('support_queue', $this->supportSession($operator['account_id'])['state']);

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $operatorAdministratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $deliveryCountBeforeRevokedSearch = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count();
        $this->accept($this->payload(
            8611,
            $operatorTelegramId,
            'routing_operator',
            'en',
            '/support-search tracking '.$ticket->trackingNumber,
        ));
        try {
            $processor->process('123456789', 8611);
            self::fail('Revoked Support authority must fail search at execution time.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame($deliveryCountBeforeRevokedSearch, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $operatorTelegramId)->count());
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8611,
            'state' => 'failed',
            'last_error_class' => AuthorizationException::class,
        ]);
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
