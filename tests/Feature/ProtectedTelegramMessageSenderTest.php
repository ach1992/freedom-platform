<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\ProtectedTelegramSendOutcome;
use App\Modules\Telegram\Infrastructure\HttpProtectedTelegramMessageSender;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement SVC-002 SVC-014 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
final class ProtectedTelegramMessageSenderTest extends TestCase
{
    private const BOT_ID = 123456;

    private const TELEGRAM_USER_ID = 99887766;

    public function test_valid_retry_after_is_preserved_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 60],
            ], 429),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::RetryAfter, $result->outcome);
        self::assertSame('telegram_retry_after', $result->resultCode);
        self::assertSame(60, $result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_unrepresentable_retry_after_is_quarantined_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 90_000],
            ], 429),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_retry_after_unrepresentable', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_http_rate_limit_without_error_code_or_retry_after_is_quarantined_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
            ], 429),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_retry_after_missing', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_malformed_retry_after_is_quarantined_even_without_http_or_json_429(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'parameters' => ['retry_after' => '60'],
            ], 400),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_retry_after_malformed', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_string_json_rate_limit_code_is_quarantined_without_http_429(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => '429',
            ], 400),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_retry_after_missing', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_integer_json_rate_limit_code_is_quarantined_without_http_429(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
            ], 400),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_retry_after_missing', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_float_json_rate_limit_code_is_quarantined_without_http_429(): void
    {
        Http::fake([
            '*' => Http::response(
                '{"ok":false,"error_code":429.0}',
                400,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_error_code_malformed', $result->resultCode);
        self::assertNull($result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_unparseable_http_rate_limit_is_uncertain_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response('not-json', 429, ['Content-Type' => 'text/plain']),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_response_unparseable', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_explicit_permanent_rejection_remains_definitive_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden',
            ], 403),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('telegram_api_error_403', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_transport_exception_becomes_uncertain_without_a_second_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts): never {
            $attempts++;
            throw new RuntimeException('Simulated transport timeout.');
        });

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_transport_uncertain', $result->resultCode);
        self::assertSame(1, $attempts);
    }

    public function test_parseable_redirect_response_is_not_followed_and_is_uncertain_after_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response(
                [
                    'ok' => false,
                    'error_code' => 403,
                ],
                307,
                ['Location' => 'https://redirect.example.test/sendMessage'],
            ),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_redirect_ambiguous', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_unparseable_response_is_uncertain_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response('not-json', 502, ['Content-Type' => 'text/plain']),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_response_unparseable', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_ok_true_on_non_success_http_status_is_ambiguous_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 778899],
            ], 500),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_response_ambiguous', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_success_without_message_identity_is_uncertain_with_one_http_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [],
            ], 200),
        ]);

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, ProtectedTelegramPresentation::text('protected-test-message'));

        self::assertSame(ProtectedTelegramSendOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_success_identity_missing', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_svg_document_is_sent_once_as_a_protected_document(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 779900],
            ], 200),
        ]);

        $result = $this->sender()->send(
            self::TELEGRAM_USER_ID,
            ProtectedTelegramPresentation::svgDocument('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Service details'),
        );

        self::assertSame(ProtectedTelegramSendOutcome::Success, $result->outcome);
        self::assertSame(779900, $result->messageId);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return str_ends_with($request->url(), '/sendDocument')
                && ($data['chat_id'] ?? null) === self::TELEGRAM_USER_ID
                && ($data['caption'] ?? null) === 'Service details'
                && ($data['protect_content'] ?? null) === true
                && ($data['disable_content_type_detection'] ?? null) === true
                && str_contains($request->body(), 'service-details.svg');
        });
    }

    private function sender(): HttpProtectedTelegramMessageSender
    {
        return new HttpProtectedTelegramMessageSender(
            $this->app->make(Factory::class),
            new TelegramRuntimeConfiguration(
                botToken: self::BOT_ID.':abcdefghijklmnopqrstuvwxyzABCDE',
                botId: (string) self::BOT_ID,
                webhookSecret: str_repeat('w', 32),
                webhookUrl: 'https://example.test/api/telegram/webhook',
                maximumBodyBytes: 1_048_576,
                queue: 'telegram-ingress',
                processingLeaseSeconds: 120,
                apiBaseUrl: 'https://api.telegram.org',
                apiTimeoutSeconds: 15,
            ),
        );
    }
}
