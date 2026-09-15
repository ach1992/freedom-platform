<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TelegramRuntimeConfigurationTest extends TestCase
{
    public function test_configuration_derives_bot_id_and_https_webhook_url(): void
    {
        $configuration = TelegramRuntimeConfiguration::fromArray([
            'bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'webhook_secret' => 'telegram_webhook_secret_1234567890_safe',
            'webhook_path' => '/api/telegram/webhook/',
            'max_body_bytes' => 1_048_576,
            'queue' => 'critical',
            'processing_lease_seconds' => 120,
            'api_base_url' => 'https://api.telegram.org',
            'api_timeout_seconds' => 15,
        ], 'https://bot.example.test/');

        self::assertSame('123456789', $configuration->botId);
        self::assertSame('https://bot.example.test/api/telegram/webhook', $configuration->webhookUrl);
    }

    public function test_insecure_url_and_short_secret_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TelegramRuntimeConfiguration::fromArray([
            'bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'webhook_secret' => 'short',
            'webhook_path' => 'api/telegram/webhook',
            'max_body_bytes' => 1_048_576,
            'queue' => 'critical',
            'processing_lease_seconds' => 120,
            'api_base_url' => 'https://api.telegram.org',
            'api_timeout_seconds' => 15,
        ], 'http://bot.example.test');
    }
}
