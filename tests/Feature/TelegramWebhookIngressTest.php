<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramWebhookIngressTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_secret_is_verified_before_payload_is_accepted(): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => 1])
            ->assertForbidden()
            ->assertExactJson(['ok' => false]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
            ->postJson('/api/telegram/webhook', ['update_id' => 1])
            ->assertForbidden();

        $this->assertDatabaseCount('processed_telegram_updates', 0);
        Queue::assertNothingPushed();
    }

    public function test_content_type_and_json_shape_are_restricted(): void
    {
        $headers = ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET];

        $this->call('POST', '/api/telegram/webhook', [], [], [], $headers + [
            'CONTENT_TYPE' => 'text/plain',
        ], '{"update_id":1}')->assertStatus(415);

        $this->call('POST', '/api/telegram/webhook', [], [], [], $headers + [
            'CONTENT_TYPE' => 'application/json',
        ], '{bad-json')->assertStatus(422);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', ['message' => []])
            ->assertStatus(422);
    }

    public function test_body_size_is_restricted(): void
    {
        config(['telegram.max_body_bytes' => 32]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', [
                'update_id' => 2,
                'message' => ['text' => str_repeat('x', 100)],
            ])->assertStatus(413);

        $this->assertDatabaseCount('processed_telegram_updates', 0);
        Queue::assertNothingPushed();
    }

    public function test_valid_update_is_encrypted_persisted_and_queued_on_critical_queue(): void
    {
        $payload = $this->payload(1001, 9001, 'first_user', '/start referral_abc');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        $row = DB::table('processed_telegram_updates')->first();
        self::assertNotNull($row);
        self::assertSame('123456789', $row->bot_id);
        self::assertSame(1001, (int) $row->update_id);
        self::assertSame('queued', $row->state);
        self::assertNotSame('', (string) $row->payload_ciphertext);
        self::assertStringNotContainsString('first_user', (string) $row->payload_ciphertext);

        $decrypted = $this->app->make(StringEncrypter::class)->decryptString((string) $row->payload_ciphertext);
        $decoded = json_decode($decrypted, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(1001, $decoded['update_id']);

        Queue::assertPushedOn('critical', ProcessTelegramUpdateJob::class, static fn (ProcessTelegramUpdateJob $job): bool => $job->botId === '123456789' && $job->updateId === 1001);
    }

    public function test_exact_duplicate_is_acknowledged_and_collision_is_rejected(): void
    {
        $payload = $this->payload(2001, 9002, 'same_user', 'hello');
        $request = fn (array $body) => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $body);

        $request($payload)->assertOk();
        $request($payload)->assertOk();
        $this->assertDatabaseCount('processed_telegram_updates', 1);
        Queue::assertPushed(ProcessTelegramUpdateJob::class, 2);

        $payload['message']['text'] = 'different';
        $request($payload)->assertStatus(409);
        $this->assertDatabaseCount('processed_telegram_updates', 1);
    }

    /** @return array<string, mixed> */
    private function payload(int $updateId, int $telegramUserId, string $username, string $text): array
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
                    'language_code' => 'fa',
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }
}
