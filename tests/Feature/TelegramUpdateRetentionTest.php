<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class TelegramUpdateRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => 'telegram_webhook_secret_1234567890_safe',
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'telegram-ingress',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);
    }

    public function test_explicit_failed_retention_terminalizes_only_due_payloads_and_requeue_excludes_them(): void
    {
        $oldHash = hash('sha256', 'old-failed-update');
        $newHash = hash('sha256', 'new-failed-update');
        $this->insertFailed(7201, $oldHash, now('UTC')->subHours(2)->format('Y-m-d H:i:s.u'));
        $this->insertFailed(7202, $newHash, now('UTC')->subMinutes(5)->format('Y-m-d H:i:s.u'));

        $exit = Artisan::call('telegram:updates:retention', [
            '--failed-older-than' => 3600,
            '--limit' => 10,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('"terminalized":1', Artisan::output());
        $terminal = DB::table('processed_telegram_updates')->where('update_id', 7201)->first();
        self::assertNotNull($terminal);
        self::assertSame('failed_terminal', (string) $terminal->state);
        self::assertSame($oldHash, (string) $terminal->payload_hash);
        self::assertNull($terminal->payload_ciphertext);
        self::assertNull($terminal->payload_size);
        $recoverable = DB::table('processed_telegram_updates')->where('update_id', 7202)->first();
        self::assertNotNull($recoverable);
        self::assertSame('failed', (string) $recoverable->state);
        self::assertSame($newHash, (string) $recoverable->payload_hash);
        self::assertNotNull($recoverable->payload_ciphertext);

        Artisan::call('telegram:updates:requeue', [
            '--older-than' => 0,
            '--include-failed' => true,
            '--limit' => 10,
            '--json' => true,
        ]);

        Queue::assertPushed(
            ProcessTelegramUpdateJob::class,
            static fn (ProcessTelegramUpdateJob $job): bool => $job->updateId === 7202,
        );
        Queue::assertNotPushed(
            ProcessTelegramUpdateJob::class,
            static fn (ProcessTelegramUpdateJob $job): bool => $job->updateId === 7201,
        );
    }

    public function test_retention_requires_an_explicit_policy_age(): void
    {
        $this->insertFailed(
            7210,
            hash('sha256', 'policy-required'),
            now('UTC')->subDay()->format('Y-m-d H:i:s.u'),
        );

        self::assertSame(2, Artisan::call('telegram:updates:retention', ['--json' => true]));
        $this->assertDatabaseHas('processed_telegram_updates', [
            'update_id' => 7210,
            'state' => 'failed',
        ]);
        self::assertNotNull(DB::table('processed_telegram_updates')->where('update_id', 7210)->value('payload_ciphertext'));
    }

    private function insertFailed(int $updateId, string $payloadHash, string $failedAt): void
    {
        DB::table('processed_telegram_updates')->insert([
            'bot_id' => '123456789',
            'update_id' => $updateId,
            'payload_hash' => $payloadHash,
            'payload_ciphertext' => 'encrypted-'.$updateId,
            'payload_size' => 100,
            'state' => 'failed',
            'correlation_id' => 'retention-test-'.$updateId,
            'received_at' => $failedAt,
            'failed_at' => $failedAt,
            'attempt_count' => 1,
            'last_error_class' => 'RuntimeException',
            'last_error_code' => substr(hash('sha256', 'failure-'.$updateId), 0, 32),
            'created_at' => $failedAt,
            'updated_at' => $failedAt,
        ]);
    }
}
