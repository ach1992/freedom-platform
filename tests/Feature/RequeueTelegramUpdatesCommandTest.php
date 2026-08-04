<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RequeueTelegramUpdatesCommandTest extends TestCase
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

    public function test_stranded_update_is_requeued_on_the_configured_ingress_queue(): void
    {
        $this->insertUpdate(7001, 'queued');

        $exitCode = Artisan::call('telegram:updates:requeue', [
            '--older-than' => 0,
            '--limit' => 10,
            '--json' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"requeued":1', Artisan::output());
        self::assertStringContainsString('"queue":"telegram-ingress"', Artisan::output());
        Queue::assertPushedOn('telegram-ingress', ProcessTelegramUpdateJob::class);
        $this->assertDatabaseHas('processed_telegram_updates', [
            'bot_id' => '123456789',
            'update_id' => 7001,
            'state' => 'queued',
        ]);
        self::assertNotNull(DB::table('processed_telegram_updates')->where('update_id', 7001)->value('queued_at'));
    }

    public function test_failed_update_requires_explicit_inclusion(): void
    {
        $this->insertUpdate(7002, 'failed');

        Artisan::call('telegram:updates:requeue', ['--older-than' => 0, '--json' => true]);
        Queue::assertNothingPushed();

        Artisan::call('telegram:updates:requeue', [
            '--older-than' => 0,
            '--include-failed' => true,
            '--json' => true,
        ]);
        Queue::assertPushedOn('telegram-ingress', ProcessTelegramUpdateJob::class);
    }

    private function insertUpdate(int $updateId, string $state): void
    {
        $now = now('UTC')->subMinute()->format('Y-m-d H:i:s.u');
        DB::table('processed_telegram_updates')->insert([
            'bot_id' => '123456789',
            'update_id' => $updateId,
            'payload_hash' => hash('sha256', (string) $updateId),
            'state' => $state,
            'attempt_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
