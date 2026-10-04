<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Infrastructure\HttpTelegramBotApi;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class HttpTelegramBotApiTest extends TestCase
{
    public function test_client_configures_secret_webhook_and_normalizes_safe_status(): void
    {
        $configuration = $this->configuration();
        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 123456789, 'is_bot' => true],
            ]),
            'https://api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'https://api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => $configuration->webhookUrl,
                    'pending_update_count' => 0,
                ],
            ]),
        ]);

        $client = new HttpTelegramBotApi($this->app->make(Factory::class), $configuration);
        $client->assertBotIdentity();
        $info = $client->configureWebhook($configuration->webhookUrl, $configuration->webhookSecret, false);

        self::assertTrue($info->configured);
        self::assertTrue($info->targetsExpectedUrl);
        self::assertFalse($info->lastErrorPresent);
        Http::assertSentCount(3);
    }

    public function test_report_channel_verification_sends_to_a_negative_channel_id_and_validates_provider_echo(): void
    {
        $configuration = $this->configuration();
        $chatId = -1001234567890;
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 77,
                    'chat' => ['id' => $chatId, 'type' => 'channel'],
                ],
            ]),
        ]);

        (new HttpTelegramBotApi($this->app->make(Factory::class), $configuration))
            ->verifyReportChannel($chatId);

        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === $chatId
            && is_string($request['text'])
            && $request['text'] !== ''
            && $request['disable_notification'] === true
        );
        Http::assertSentCount(1);
    }

    public function test_report_channel_verification_fails_closed_when_provider_echoes_another_chat(): void
    {
        $configuration = $this->configuration();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 77,
                    'chat' => ['id' => -1009999999999, 'type' => 'channel'],
                ],
            ]),
        ]);

        try {
            (new HttpTelegramBotApi($this->app->make(Factory::class), $configuration))
                ->verifyReportChannel(-1001234567890);
            self::fail('Expected report-channel identity mismatch rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram report channel verification returned an invalid result.', $exception->getMessage());
            self::assertStringNotContainsString($configuration->botToken, $exception->getMessage());
        }
    }

    public function test_bot_identity_validation_fails_closed_on_mismatched_telegram_identity(): void
    {
        $configuration = $this->configuration();
        Http::fake([
            'https://api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 987654321, 'is_bot' => true],
            ]),
        ]);

        try {
            (new HttpTelegramBotApi($this->app->make(Factory::class), $configuration))->assertBotIdentity();
            self::fail('Expected mismatched Telegram bot identity rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram Bot API identity does not match configured bot.', $exception->getMessage());
            self::assertStringNotContainsString($configuration->botToken, $exception->getMessage());
        }
    }

    public function test_client_disables_redirect_following_at_transport_boundary(): void
    {
        $pending = Mockery::mock(PendingRequest::class);
        $pending->shouldReceive('acceptJson')->once()->andReturnSelf();
        $pending->shouldReceive('timeout')->once()->andReturnSelf();
        $pending->shouldReceive('connectTimeout')->once()->andReturnSelf();
        $pending->shouldReceive('withoutRedirecting')->once()->andReturnSelf();
        $pending->shouldReceive('retry')->once()->andReturnSelf();
        $pending->shouldReceive('post')->once()->andReturn(new Response(new Psr7Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['ok' => true, 'result' => ['url' => '', 'pending_update_count' => 0]], JSON_THROW_ON_ERROR),
        )));

        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('asJson')->once()->andReturn($pending);

        (new HttpTelegramBotApi($factory, $this->configuration()))->webhookInfo();
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
