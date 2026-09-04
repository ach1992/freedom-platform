<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use App\Modules\Telegram\Infrastructure\HttpTelegramPrivateMediaFetcher;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use App\Shared\Application\RestrictedValue;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement C2C-002 DAT-002 SEC-002 SEC-003 SEC-009 INT-001 INT-002 QUA-001 QUA-004 */
final class HttpTelegramPrivateMediaFetcherTest extends TestCase
{
    public function test_read_only_provider_lookup_and_bounded_download_succeeds_without_redirects(): void
    {
        $png = $this->onePixelPng();
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-1',
                    'file_unique_id' => 'unique-secret-1',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/receipt.png',
                ],
            ], 200),
            'https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/receipt.png' => Http::response($png, 200),
        ]);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-1'),
            RestrictedValue::fromString('unique-secret-1'),
            1_048_576,
        );

        self::assertSame($png, $download->bytes());
        self::assertSame(strlen($png), $download->providerFileSize);
        Http::assertSentCount(2);
    }

    public function test_transient_get_file_failure_retries_read_only_lookup_before_download(): void
    {
        $png = $this->onePixelPng();
        Http::fakeSequence('https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile')
            ->push(['ok' => false, 'error_code' => 500], 500)
            ->push([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-2',
                    'file_unique_id' => 'unique-secret-2',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/retry.png',
                ],
            ], 200);
        Http::fake([
            'https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/retry.png' => Http::response($png, 200),
        ]);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-2'),
            RestrictedValue::fromString('unique-secret-2'),
            1_048_576,
        );

        self::assertSame($png, $download->bytes());
        Http::assertSentCount(3);
    }

    public function test_provider_identity_mismatch_fails_closed_before_download(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'different-file-id',
                    'file_unique_id' => 'unique-secret-3',
                    'file_path' => 'photos/mismatch.png',
                ],
            ], 200),
        ]);

        try {
            $this->fetcher()->fetch(
                RestrictedValue::fromString('file-secret-3'),
                RestrictedValue::fromString('unique-secret-3'),
                1024,
            );
            self::fail('Provider identity mismatch must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram getFile identity does not match the accepted update.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_provider_body_overflow_is_bounded_before_acceptance(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-4',
                    'file_unique_id' => 'unique-secret-4',
                    'file_path' => 'photos/overflow.png',
                ],
            ], 200),
            'https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/overflow.png' => Http::response(str_repeat('x', 1025), 200),
        ]);

        try {
            $this->fetcher()->fetch(
                RestrictedValue::fromString('file-secret-4'),
                RestrictedValue::fromString('unique-secret-4'),
                1024,
            );
            self::fail('Provider body overflow must be bounded before full acceptance.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('file_too_large', $exception->reasonCode);
        }
    }

    private function fetcher(): HttpTelegramPrivateMediaFetcher
    {
        return new HttpTelegramPrivateMediaFetcher(
            $this->app->make(Factory::class),
            new TelegramRuntimeConfiguration(
                '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
                '123456789',
                'telegram_webhook_secret_1234567890_safe',
                'https://bot.example.test/api/telegram/webhook',
                1_048_576,
                'critical',
                120,
                'https://api.telegram.org',
                15,
            ),
        );
    }

    private function onePixelPng(): string
    {
        $decoded = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true,
        );
        if (! is_string($decoded)) {
            throw new RuntimeException('PNG test fixture could not be decoded.');
        }

        return $decoded;
    }
}
