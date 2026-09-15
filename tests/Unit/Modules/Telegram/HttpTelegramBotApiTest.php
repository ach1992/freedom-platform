<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Infrastructure\HttpTelegramBotApi;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class HttpTelegramBotApiTest extends TestCase
{
    public function test_client_configures_secret_webhook_and_normalizes_safe_status(): void
    {
        $configuration = $this->configuration();
        Http::fake([
            'https://api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => $configuration->webhookUrl,
                    'pending_update_count' => 0,
                ],
            ]),
        ]);

        $info = (new HttpTelegramBotApi($this->app->make(Factory::class), $configuration))
            ->configureWebhook($configuration->webhookUrl, $configuration->webhookSecret, false);

        self::assertTrue($info->configured);
        self::assertTrue($info->targetsExpectedUrl);
        self::assertFalse($info->lastErrorPresent);
        Http::assertSentCount(2);
    }

    public function test_client_throws_generic_error_without_exposing_token(): void
    {
        $configuration = $this->configuration();
        Http::fake(['*' => Http::response(['ok' => false], 500)]);

        try {
            (new HttpTelegramBotApi($this->app->make(Factory::class), $configuration))->webhookInfo();
            self::fail('Expected Telegram API failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram Bot API request failed.', $exception->getMessage());
            self::assertStringNotContainsString($configuration->botToken, $exception->getMessage());
            self::assertStringNotContainsString($configuration->webhookSecret, $exception->getMessage());
        }
    }

    private function configuration(): TelegramRuntimeConfiguration
    {
        return TelegramRuntimeConfiguration::fromArray([
            'bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'webhook_secret' => 'telegram_webhook_secret_1234567890_safe',
            'webhook_path' => 'api/telegram/webhook',
            'max_body_bytes' => 1_048_576,
            'queue' => 'critical',
            'processing_lease_seconds' => 120,
            'api_base_url' => 'https://api.telegram.org',
            'api_timeout_seconds' => 15,
        ], 'https://bot.example.test');
    }
}
