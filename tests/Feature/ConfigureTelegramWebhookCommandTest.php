<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\TelegramWebhookInfo;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ConfigureTelegramWebhookCommandTest extends TestCase
{
    public function test_command_configures_webhook_and_outputs_only_safe_metadata(): void
    {
        $token = '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE';
        $secret = 'telegram_webhook_secret_1234567890_safe';
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => $token,
            'telegram.webhook_secret' => $secret,
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
        ]);

        $fake = new class implements TelegramBotApi
        {
            public bool $configured = false;

            public function configureWebhook(string $url, string $secretToken, bool $dropPendingUpdates): TelegramWebhookInfo
            {
                $this->configured = $url === 'https://bot.example.test/api/telegram/webhook'
                    && $secretToken === 'telegram_webhook_secret_1234567890_safe'
                    && ! $dropPendingUpdates;

                return new TelegramWebhookInfo(true, true, 0, false);
            }

            public function webhookInfo(): TelegramWebhookInfo
            {
                return new TelegramWebhookInfo(true, true, 0, false);
            }
        };
        $this->app->instance(TelegramBotApi::class, $fake);

        $exitCode = Artisan::call('telegram:webhook:configure', ['--json' => true]);
        $output = Artisan::output();

        self::assertSame(0, $exitCode);
        self::assertTrue($fake->configured);
        self::assertStringContainsString('"targets_expected_url":true', $output);
        self::assertStringNotContainsString($token, $output);
        self::assertStringNotContainsString($secret, $output);
    }
}
