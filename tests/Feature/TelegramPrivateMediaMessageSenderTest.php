<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramResolvedPrivateMediaPresentation;
use App\Modules\Telegram\Infrastructure\HttpTelegramPrivateMediaMessageSender;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement COM-001 SEC-002 SEC-003 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
final class TelegramPrivateMediaMessageSenderTest extends TestCase
{
    private const BOT_ID = 123456;

    private const TELEGRAM_USER_ID = 99887766;

    public function test_photo_caption_is_sent_once_and_success_recipient_identity_is_verified(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 8801,
                    'chat' => ['id' => self::TELEGRAM_USER_ID],
                ],
            ], 200),
        ]);

        $bytes = $this->onePixelPng();
        $presentation = new TelegramResolvedPrivateMediaPresentation(
            'photo',
            $bytes,
            'admin-direct-photo.png',
            'safe photo caption',
            'image/png',
            strlen($bytes),
            hash('sha256', $bytes),
        );

        $result = $this->sender()->send(self::TELEGRAM_USER_ID, $presentation);

        self::assertSame(TelegramMutationOutcome::Success, $result->outcome);
        self::assertSame(8801, $result->messageId);
        self::assertSame('[RESOLVED_PRIVATE_TELEGRAM_MEDIA_PRESENTATION]', (string) $presentation);
        self::assertSame(
            ['redacted' => true, 'type' => 'photo', 'mime' => 'image/png', 'size' => strlen($bytes)],
            $presentation->__debugInfo(),
        );
        $encoded = json_encode($presentation, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"redacted":true', $encoded);
        self::assertStringNotContainsString('safe photo caption', $encoded);
        self::assertStringNotContainsString(hash('sha256', $bytes), $encoded);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            $hasScalar = static function (string $name, string $value) use ($body): bool {
                $pattern = '~name="'.preg_quote($name, '~').'"(?:\\r\\n[^\\r\\n]+)*\\r\\n\\r\\n'.preg_quote($value, '~').'\\r\\n~';

                return preg_match($pattern, $body) === 1;
            };

            return str_ends_with($request->url(), '/sendPhoto')
                && $hasScalar('chat_id', (string) self::TELEGRAM_USER_ID)
                && $hasScalar('caption', 'safe photo caption')
                && str_contains($body, 'admin-direct-photo.png');
        });
    }

    public function test_video_and_document_use_their_exact_provider_methods_without_hidden_retries(): void
    {
        Http::fakeSequence()
            ->push([
                'ok' => true,
                'result' => ['message_id' => 8802, 'chat' => ['id' => self::TELEGRAM_USER_ID]],
            ], 200)
            ->push([
                'ok' => true,
                'result' => ['message_id' => 8803, 'chat' => ['id' => self::TELEGRAM_USER_ID]],
            ], 200);

        $videoBytes = 'validated-private-video';
        $documentBytes = '%PDF-1.4 validated-private-document';
        $sender = $this->sender();

        $video = $sender->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'video',
                $videoBytes,
                'admin-direct-video.mp4',
                '',
                'video/mp4',
                strlen($videoBytes),
                hash('sha256', $videoBytes),
            ),
        );
        $document = $sender->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'document',
                $documentBytes,
                'admin-direct-document.pdf',
                '',
                'application/pdf',
                strlen($documentBytes),
                hash('sha256', $documentBytes),
            ),
        );

        self::assertSame(TelegramMutationOutcome::Success, $video->outcome);
        self::assertSame(TelegramMutationOutcome::Success, $document->outcome);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        self::assertCount(2, $requests);
        self::assertStringEndsWith('/sendVideo', $requests[0][0]->url());
        self::assertStringEndsWith('/sendDocument', $requests[1][0]->url());
        self::assertStringContainsString('disable_content_type_detection', $requests[1][0]->body());
        self::assertStringContainsString('true', $requests[1][0]->body());
    }

    public function test_oversized_photo_cannot_reach_the_provider_boundary(): void
    {
        Http::fake();
        $bytes = str_repeat('x', 10_000_001);

        try {
            new TelegramResolvedPrivateMediaPresentation(
                'photo',
                $bytes,
                'admin-direct-photo.png',
                '',
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            );
            self::fail('A photo above Telegram sendPhoto multipart limit must fail before provider delivery.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_retry_after_is_preserved_without_a_hidden_second_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 60],
            ], 429),
        ]);

        $bytes = $this->onePixelPng();
        $result = $this->sender()->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'photo',
                $bytes,
                'admin-direct-photo.png',
                '',
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            ),
        );

        self::assertSame(TelegramMutationOutcome::RetryAfter, $result->outcome);
        self::assertSame('telegram_retry_after', $result->resultCode);
        self::assertSame(60, $result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_explicit_permanent_rejection_is_definitive_after_one_attempt(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden',
            ], 403),
        ]);

        $bytes = $this->onePixelPng();
        $result = $this->sender()->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'photo',
                $bytes,
                'admin-direct-photo.png',
                '',
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            ),
        );

        self::assertSame(TelegramMutationOutcome::DefinitiveFailure, $result->outcome);
        self::assertSame('telegram_api_error_403', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_success_for_a_different_recipient_is_uncertain(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 8804,
                    'chat' => ['id' => self::TELEGRAM_USER_ID + 1],
                ],
            ], 200),
        ]);

        $bytes = $this->onePixelPng();
        $result = $this->sender()->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'photo',
                $bytes,
                'admin-direct-photo.png',
                '',
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            ),
        );

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_success_recipient_mismatch', $result->resultCode);
        self::assertNull($result->messageId);
        Http::assertSentCount(1);
    }

    public function test_transport_exception_is_uncertain_after_exactly_one_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts): never {
            $attempts++;

            throw new RuntimeException('Simulated private-media transport timeout.');
        });

        $bytes = $this->onePixelPng();
        $result = $this->sender()->send(
            self::TELEGRAM_USER_ID,
            new TelegramResolvedPrivateMediaPresentation(
                'photo',
                $bytes,
                'admin-direct-photo.png',
                '',
                'image/png',
                strlen($bytes),
                hash('sha256', $bytes),
            ),
        );

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_transport_uncertain', $result->resultCode);
        self::assertSame(1, $attempts);
    }

    private function sender(): HttpTelegramPrivateMediaMessageSender
    {
        return new HttpTelegramPrivateMediaMessageSender(
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

    private function onePixelPng(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNg+A8AAQIBANEay48AAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($decoded)) {
            throw new RuntimeException('PNG test fixture could not be decoded.');
        }

        return $decoded;
    }
}
