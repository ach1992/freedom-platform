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

    public function test_provider_file_id_alias_change_with_stable_unique_id_is_accepted(): void
    {
        $png = $this->onePixelPng();
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'different-valid-file-id-alias',
                    'file_unique_id' => 'unique-secret-3',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/alias.png',
                ],
            ], 200),
            'https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/alias.png' => Http::response($png, 200),
        ]);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-3'),
            RestrictedValue::fromString('unique-secret-3'),
            1024,
        );

        self::assertSame($png, $download->bytes());
        Http::assertSentCount(2);
    }

    public function test_provider_unique_identity_mismatch_fails_closed_before_download(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'different-valid-file-id-alias',
                    'file_unique_id' => 'different-unique-secret',
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
            self::fail('Provider stable identity mismatch must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram getFile identity does not match the accepted update.', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_empty_successful_download_is_retried_and_can_recover(): void
    {
        $png = $this->onePixelPng();
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-empty-retry',
                    'file_unique_id' => 'unique-secret-empty-retry',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/empty-retry.png',
                ],
            ], 200),
        ]);
        Http::fakeSequence('https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/empty-retry.png')
            ->push('', 200)
            ->push($png, 200);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-empty-retry'),
            RestrictedValue::fromString('unique-secret-empty-retry'),
            1024,
        );

        self::assertSame($png, $download->bytes());
        Http::assertSentCount(4);
    }

    public function test_repeated_empty_successful_downloads_exhaust_as_retryable_provider_failure(): void
    {
        $png = $this->onePixelPng();
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-empty-exhaust',
                    'file_unique_id' => 'unique-secret-empty-exhaust',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/empty-exhaust.png',
                ],
            ], 200),
        ]);
        Http::fakeSequence('https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/empty-exhaust.png')
            ->push('', 200)
            ->push('', 200);

        try {
            $this->fetcher()->fetch(
                RestrictedValue::fromString('file-secret-empty-exhaust'),
                RestrictedValue::fromString('unique-secret-empty-exhaust'),
                1024,
            );
            self::fail('Repeated empty provider responses must remain a retryable provider failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram private-media download returned an empty body.', $exception->getMessage());
        }
        Http::assertSentCount(4);
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

    public function test_missing_provider_size_truncated_download_retries_and_recovers(): void
    {
        $png = $this->onePixelPng();
        $truncated = substr($png, 0, -12);
        Http::fakeSequence('https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile')
            ->push([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-truncated-retry',
                    'file_unique_id' => 'unique-secret-truncated-retry',
                    'file_path' => 'photos/truncated-retry.png',
                ],
            ], 200)
            ->push([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-truncated-retry-alias',
                    'file_unique_id' => 'unique-secret-truncated-retry',
                    'file_path' => 'photos/truncated-retry.png',
                ],
            ], 200);
        Http::fakeSequence('https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/truncated-retry.png')
            ->push($truncated, 200)
            ->push($png, 200);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-truncated-retry'),
            RestrictedValue::fromString('unique-secret-truncated-retry'),
            1024,
        );

        self::assertSame($png, $download->bytes());
        self::assertNull($download->providerFileSize);
        Http::assertSentCount(4);
    }

    public function test_partial_magic_truncation_retries_and_recovers_when_provider_size_is_missing(): void
    {
        $png = $this->onePixelPng();
        Http::fakeSequence('https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile')
            ->push([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-partial-magic',
                    'file_unique_id' => 'unique-secret-partial-magic',
                    'file_path' => 'photos/partial-magic.png',
                ],
            ], 200)
            ->push([
                'ok' => true,
                'result' => [
                    'file_id' => 'file-secret-partial-magic-alias',
                    'file_unique_id' => 'unique-secret-partial-magic',
                    'file_path' => 'photos/partial-magic.png',
                ],
            ], 200);
        Http::fakeSequence('https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/partial-magic.png')
            ->push(substr($png, 0, 7), 200)
            ->push($png, 200);

        $download = $this->fetcher()->fetch(
            RestrictedValue::fromString('file-secret-partial-magic'),
            RestrictedValue::fromString('unique-secret-partial-magic'),
            1024,
        );

        self::assertSame($png, $download->bytes());
        self::assertNull($download->providerFileSize);
        Http::assertSentCount(4);
    }

    public function test_missing_provider_size_repeated_truncation_for_all_supported_formats_exhausts_as_retryable_provider_failure(): void
    {
        $getFile = Http::fakeSequence('https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile');
        $fixtures = [
            'png' => ['mime' => 'image/png', 'bytes' => $this->onePixelPng()],
            'jpg' => ['mime' => 'image/jpeg', 'bytes' => $this->onePixelJpeg()],
            'webp' => ['mime' => 'image/webp', 'bytes' => $this->onePixelWebp()],
        ];

        foreach ($fixtures as $format => $fixture) {
            $fileId = 'file-secret-truncated-'.$format;
            $uniqueId = 'unique-secret-truncated-'.$format;
            $filePath = 'photos/truncated-exhaust.'.$format;
            $truncated = substr($fixture['bytes'], 0, -1);

            self::assertSame($fixture['mime'], (new \finfo(FILEINFO_MIME_TYPE))->buffer($truncated));
            $legacyInspection = @getimagesizefromstring($truncated);
            self::assertIsArray($legacyInspection);
            self::assertSame($fixture['mime'], image_type_to_mime_type($legacyInspection[2]));

            $getFile
                ->push([
                    'ok' => true,
                    'result' => [
                        'file_id' => $fileId,
                        'file_unique_id' => $uniqueId,
                        'file_path' => $filePath,
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'result' => [
                        'file_id' => $fileId.'-alias',
                        'file_unique_id' => $uniqueId,
                        'file_path' => $filePath,
                    ],
                ], 200);
            Http::fakeSequence(
                'https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/'.$filePath,
            )
                ->push($truncated, 200)
                ->push($truncated, 200);
        }

        foreach (array_keys($fixtures) as $format) {
            try {
                $this->fetcher()->fetch(
                    RestrictedValue::fromString('file-secret-truncated-'.$format),
                    RestrictedValue::fromString('unique-secret-truncated-'.$format),
                    1024,
                );
                self::fail('Repeated incomplete '.$format.' responses must not be accepted as image evidence.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'Telegram private-media download remained incomplete after bounded retry.',
                    $exception->getMessage(),
                );
            }
        }

        Http::assertSentCount(12);
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

    private function onePixelJpeg(): string
    {
        return $this->decodeImageFixture(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJgA/9k=',
        );
    }

    private function onePixelWebp(): string
    {
        return $this->decodeImageFixture(
            'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA',
        );
    }

    private function onePixelPng(): string
    {
        return $this->decodeImageFixture(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9RYVFHYAAAAASUVORK5CYII=',
        );
    }

    private function decodeImageFixture(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)) {
            throw new RuntimeException('Image test fixture could not be decoded.');
        }

        return $decoded;
    }
}
