<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** @requirement SUP-002 SEC-002 QUA-001 QUA-004 QUA-008 */
final class TelegramSupportRatingTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support rating verification requires MariaDB/MySQL.');
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

    public function test_closed_ticket_rating_is_restart_safe_cross_actor_protected_and_conflict_rejecting(): void
    {
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $customerTelegramId = 9870;
        $attackerTelegramId = 9871;

        $this->accept($this->payload(8700, $customerTelegramId, 'rating_customer', 'en', '/start'));
        $processor->process('123456789', 8700);
        $customer = $this->account($customerTelegramId);
        $tickets = $this->app->make(SupportTicketService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Rate completed support',
            'Rating must remain customer-owned and replay safe.',
            'telegram-rating:create',
        ));
        $tickets->closeForCustomer($ticket->id, $customer['user_id'], 'Completed for rating');

        $supportHome = $this->callbackToken('navigation.support', $customer['account_id']);
        $this->accept($this->callbackPayload(8701, $customerTelegramId, 'rating_customer', 'en', $supportHome));
        $processor->process('123456789', 8701);
        self::assertStringContainsString('/support-rate <1-5>', $this->latestConfidentialPresentation($customerTelegramId));

        $ticketToken = $this->callbackToken(
            'navigation.support.ticket',
            $customer['account_id'],
            json_encode(['ticket_id' => $ticket->id], JSON_THROW_ON_ERROR),
        );

        $this->accept($this->payload(8702, $attackerTelegramId, 'rating_attacker', 'en', '/start'));
        $processor->process('123456789', 8702);
        $this->accept($this->callbackPayload(8703, $attackerTelegramId, 'rating_attacker', 'en', $ticketToken));
        $processor->process('123456789', 8703);
        self::assertSame(0, DB::table('support_ticket_ratings')->count());
        $this->assertDatabaseHas('telegram_interaction_callbacks', [
            'token_hash' => hash('sha256', $ticketToken),
            'state' => 'pending',
            'accepted_update_id' => null,
        ]);

        $this->accept($this->callbackPayload(8704, $customerTelegramId, 'rating_customer', 'en', $ticketToken));
        $processor->process('123456789', 8704);
        self::assertSame('support_ticket', $this->supportSession($customer['account_id'])['state']);
        $versionBeforeRating = $this->supportSession($customer['account_id'])['version'];

        $this->accept($this->payload(8705, $customerTelegramId, 'rating_customer', 'en', '/support-rate 5'));
        $processor->process('123456789', 8705);
        self::assertSame(5, (int) DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->value('score'));
        self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());
        self::assertSame($versionBeforeRating + 2, $this->supportSession($customer['account_id'])['version']);
        self::assertStringContainsString('5 out of 5', $this->latestConfidentialPresentation($customerTelegramId));

        $deliveryCount = DB::table('telegram_delivery_operations')->where('recipient_chat_id', $customerTelegramId)->count();
        $sessionVersion = $this->supportSession($customer['account_id'])['version'];
        $processor->process('123456789', 8705);
        self::assertSame($deliveryCount, DB::table('telegram_delivery_operations')->where('recipient_chat_id', $customerTelegramId)->count());
        self::assertSame($sessionVersion, $this->supportSession($customer['account_id'])['version']);
        self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());

        $this->accept($this->payload(8706, $customerTelegramId, 'rating_customer', 'en', '/support-rate 4'));
        try {
            $processor->process('123456789', 8706);
            self::fail('A conflicting second rating must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Telegram update processing failed.', $exception->getMessage());
        }
        self::assertSame(5, (int) DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->value('score'));
        self::assertSame($sessionVersion, $this->supportSession($customer['account_id'])['version']);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 8706,
            'state' => 'failed',
            'last_error_class' => DomainException::class,
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
