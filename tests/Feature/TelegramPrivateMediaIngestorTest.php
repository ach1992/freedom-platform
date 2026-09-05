<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use App\Modules\Telegram\Application\TelegramPrivateMediaInput;
use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use App\Modules\Telegram\Infrastructure\HttpTelegramPrivateMediaFetcher;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use App\Shared\Application\RestrictedValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TelegramPrivateMediaTestFetcher implements TelegramPrivateMediaFetcher
{
    public int $calls = 0;

    public int $retryableFailuresRemaining = 0;

    public function __construct(public string $content) {}

    public function fetch(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): TelegramPrivateMediaDownload {
        $this->calls++;
        if ($this->retryableFailuresRemaining > 0) {
            $this->retryableFailuresRemaining--;

            throw new RuntimeException('Simulated retryable Telegram provider failure.');
        }

        return TelegramPrivateMediaDownload::fromBytes($this->content, strlen($this->content));
    }
}

/** @requirement C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
final class TelegramPrivateMediaIngestorTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram private-media persistence verification requires MariaDB/MySQL.');
        }

        Storage::fake('telegram_private_media');
        config()->set('telegram.private_media_max_bytes', 1_048_576);
    }

    public function test_valid_image_is_private_idempotent_and_association_survives_replay_cleanup(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9811);
        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaTestFetcher($png);
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $input = new TelegramPrivateMediaInput(
            'photo',
            RestrictedValue::fromString('provider-file-secret-A'),
            RestrictedValue::fromString('provider-unique-secret-A'),
            strlen($png),
        );

        $first = $service->ingest('123456789', 88001, $accountId, $userId, $input);
        $replay = $service->ingest('123456789', 88001, $accountId, $userId, $input);

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->publicId, $replay->publicId);
        self::assertSame(hash('sha256', $png), $first->contentSha256);
        self::assertSame('image/png', $first->detectedMime);
        self::assertSame(1, $fetcher->calls);
        self::assertSame(1, DB::table('telegram_private_media')->count());

        $row = DB::table('telegram_private_media')->first();
        self::assertNotNull($row);
        self::assertSame('stored', $row->state);
        self::assertStringNotContainsString('provider-file-secret-A', (string) $row->encrypted_file_id);
        self::assertStringNotContainsString('provider-unique-secret-A', (string) $row->encrypted_file_unique_id);
        self::assertStringNotContainsString('public', (string) $row->storage_path);
        self::assertStringNotContainsString('provider', (string) $row->storage_path);
        Storage::disk('telegram_private_media')->assertExists((string) $row->storage_path);

        $submissionPublicId = strtoupper((string) Str::ulid());
        $service->associate($first, $userId, 'c2c_manual_submission', $submissionPublicId);
        $service->discardIfUnassociated($first, $userId);
        $associatedReplay = $service->ingest('123456789', 88001, $accountId, $userId, $input);

        self::assertTrue($associatedReplay->replayed);
        self::assertSame(1, $fetcher->calls);
        $associated = DB::table('telegram_private_media')->first();
        self::assertNotNull($associated);
        self::assertSame('associated', $associated->state);
        self::assertSame('c2c_manual_submission', $associated->association_type);
        self::assertSame($submissionPublicId, $associated->association_public_id);
        Storage::disk('telegram_private_media')->assertExists((string) $associated->storage_path);
    }

    public function test_all_supported_complete_image_formats_are_accepted_by_content_validation(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9818);
        $fetcher = new TelegramPrivateMediaTestFetcher($this->onePixelPng());
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $fixtures = [
            88020 => ['mime' => 'image/png', 'bytes' => $this->onePixelPng()],
            88021 => ['mime' => 'image/jpeg', 'bytes' => $this->onePixelJpeg()],
            88022 => ['mime' => 'image/webp', 'bytes' => $this->onePixelWebp()],
            88023 => ['mime' => 'image/webp', 'bytes' => $this->animatedOnePixelWebp()],
        ];

        foreach ($fixtures as $updateId => $fixture) {
            $fetcher->content = $fixture['bytes'];
            $receipt = $service->ingest(
                '123456789',
                $updateId,
                $accountId,
                $userId,
                new TelegramPrivateMediaInput(
                    'document',
                    RestrictedValue::fromString('provider-file-secret-'.$updateId),
                    RestrictedValue::fromString('provider-unique-secret-'.$updateId),
                    null,
                ),
            );

            self::assertSame($fixture['mime'], $receipt->detectedMime);
            self::assertSame(hash('sha256', $fixture['bytes']), $receipt->contentSha256);
            self::assertSame(
                'stored',
                DB::table('telegram_private_media')->where('update_id', $updateId)->value('state'),
            );
            $path = (string) DB::table('telegram_private_media')->where('update_id', $updateId)->value('storage_path');
            Storage::disk('telegram_private_media')->assertExists($path);
        }
    }

    public function test_retryable_provider_failure_keeps_durable_media_pending_for_same_update_recovery(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9816);
        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaTestFetcher($png);
        $fetcher->retryableFailuresRemaining = 1;
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $input = new TelegramPrivateMediaInput(
            'photo',
            RestrictedValue::fromString('provider-file-secret-retryable'),
            RestrictedValue::fromString('provider-unique-secret-retryable'),
            strlen($png),
        );

        try {
            $service->ingest('123456789', 88007, $accountId, $userId, $input);
            self::fail('Retryable provider failure must propagate without terminal rejection.');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated retryable Telegram provider failure.', $exception->getMessage());
        }

        self::assertSame('pending', DB::table('telegram_private_media')->where('update_id', 88007)->value('state'));
        self::assertNull(DB::table('telegram_private_media')->where('update_id', 88007)->value('rejection_code'));
        self::assertSame([], Storage::disk('telegram_private_media')->allFiles());

        $receipt = $service->ingest('123456789', 88007, $accountId, $userId, $input);

        self::assertTrue($receipt->replayed);
        self::assertSame(2, $fetcher->calls);
        self::assertSame(1, DB::table('telegram_private_media')->where('update_id', 88007)->count());
        self::assertSame('stored', DB::table('telegram_private_media')->where('update_id', 88007)->value('state'));
    }

    public function test_repeated_empty_http_download_keeps_durable_media_pending_for_recovery(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9817);
        $png = $this->onePixelPng();
        Http::fake([
            'https://api.telegram.org/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/getFile' => Http::response([
                'ok' => true,
                'result' => [
                    'file_id' => 'provider-file-secret-empty-durable',
                    'file_unique_id' => 'provider-unique-secret-empty-durable',
                    'file_size' => strlen($png),
                    'file_path' => 'photos/empty-durable.png',
                ],
            ], 200),
        ]);
        Http::fakeSequence('https://api.telegram.org/file/bot123456789:abcdefghijklmnopqrstuvwxyz_ABCDE/photos/empty-durable.png')
            ->push('', 200)
            ->push('', 200);

        $this->app->instance(
            TelegramPrivateMediaFetcher::class,
            new HttpTelegramPrivateMediaFetcher(
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
            ),
        );
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $input = new TelegramPrivateMediaInput(
            'photo',
            RestrictedValue::fromString('provider-file-secret-empty-durable'),
            RestrictedValue::fromString('provider-unique-secret-empty-durable'),
            strlen($png),
        );

        try {
            $service->ingest('123456789', 88008, $accountId, $userId, $input);
            self::fail('Repeated empty provider responses must remain retryable at the durable media boundary.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram private-media download returned an empty body.', $exception->getMessage());
        }

        self::assertSame('pending', DB::table('telegram_private_media')->where('update_id', 88008)->value('state'));
        self::assertNull(DB::table('telegram_private_media')->where('update_id', 88008)->value('rejection_code'));
        self::assertSame([], Storage::disk('telegram_private_media')->allFiles());
        Http::assertSentCount(4);
    }

    public function test_unsafe_or_conflicting_media_fails_closed_without_accepted_private_file(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9812);
        $fetcher = new TelegramPrivateMediaTestFetcher('not-an-image');
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $input = new TelegramPrivateMediaInput(
            'document',
            RestrictedValue::fromString('provider-file-secret-B'),
            RestrictedValue::fromString('provider-unique-secret-B'),
            strlen('not-an-image'),
        );

        try {
            $service->ingest('123456789', 88002, $accountId, $userId, $input);
            self::fail('Non-image private media must be rejected.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('unsupported_image_type', $exception->reasonCode);
        }
        self::assertSame('rejected', DB::table('telegram_private_media')->where('update_id', 88002)->value('state'));
        self::assertSame([], Storage::disk('telegram_private_media')->allFiles());

        $png = $this->onePixelPng();
        $fetcher->content = $png;
        $acceptedInput = new TelegramPrivateMediaInput(
            'photo',
            RestrictedValue::fromString('provider-file-secret-C'),
            RestrictedValue::fromString('provider-unique-secret-C'),
            strlen($png),
        );
        $service->ingest('123456789', 88003, $accountId, $userId, $acceptedInput);

        $this->expectException(RuntimeException::class);
        $service->ingest(
            '123456789',
            88003,
            $accountId,
            $userId,
            new TelegramPrivateMediaInput(
                'photo',
                RestrictedValue::fromString('different-provider-file'),
                RestrictedValue::fromString('provider-unique-secret-C'),
                strlen($png),
            ),
        );
    }

    public function test_unassociated_discard_is_terminal_and_replay_does_not_redownload(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9814);
        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaTestFetcher($png);
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $input = new TelegramPrivateMediaInput(
            'photo',
            RestrictedValue::fromString('provider-file-secret-discard'),
            RestrictedValue::fromString('provider-unique-secret-discard'),
            strlen($png),
        );

        $receipt = $service->ingest('123456789', 88005, $accountId, $userId, $input);
        $path = (string) DB::table('telegram_private_media')->where('update_id', 88005)->value('storage_path');
        Storage::disk('telegram_private_media')->assertExists($path);

        $service->discardIfUnassociated($receipt, $userId);

        self::assertSame('discarded', DB::table('telegram_private_media')->where('update_id', 88005)->value('state'));
        self::assertSame('discarded_unassociated', DB::table('telegram_private_media')->where('update_id', 88005)->value('rejection_code'));
        Storage::disk('telegram_private_media')->assertMissing($path);
        self::assertSame(1, $fetcher->calls);

        try {
            $service->ingest('123456789', 88005, $accountId, $userId, $input);
            self::fail('Discarded private media must not become accepted evidence on replay.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('discarded_unassociated', $exception->reasonCode);
        }
        self::assertSame(1, $fetcher->calls, 'Discarded replay must not contact Telegram again.');
    }

    public function test_mariadb_constraints_reject_unsafe_private_media_shapes(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9815);
        $png = $this->onePixelPng();
        $fetcher = new TelegramPrivateMediaTestFetcher($png);
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);
        $service->ingest(
            '123456789',
            88006,
            $accountId,
            $userId,
            new TelegramPrivateMediaInput(
                'photo',
                RestrictedValue::fromString('provider-file-secret-constraint'),
                RestrictedValue::fromString('provider-unique-secret-constraint'),
                strlen($png),
            ),
        );

        try {
            DB::table('telegram_private_media')->where('update_id', 88006)->update([
                'content_sha256' => str_repeat('z', 64),
            ]);
            self::fail('MariaDB must reject a non-hex private-media content hash.');
        } catch (QueryException) {
            self::assertSame(hash('sha256', $png), DB::table('telegram_private_media')->where('update_id', 88006)->value('content_sha256'));
        }

        try {
            DB::table('telegram_private_media')->where('update_id', 88006)->update([
                'storage_path' => '../public/receipt.png',
            ]);
            self::fail('MariaDB must reject an unsafe private-media storage path.');
        } catch (QueryException) {
            self::assertMatchesRegularExpression(
                '/\Areceipts\/[0-9a-f]{2}\/[0-9A-HJKMNP-TV-Z]{26}\.media\z/',
                (string) DB::table('telegram_private_media')->where('update_id', 88006)->value('storage_path'),
            );
        }
    }

    public function test_reported_oversize_is_rejected_before_provider_or_persistence(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9813);
        $fetcher = new TelegramPrivateMediaTestFetcher($this->onePixelPng());
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);

        try {
            $service->ingest(
                '123456789',
                88004,
                $accountId,
                $userId,
                new TelegramPrivateMediaInput(
                    'document',
                    RestrictedValue::fromString('provider-file-secret-D'),
                    RestrictedValue::fromString('provider-unique-secret-D'),
                    1_048_577,
                ),
            );
            self::fail('Reported oversize media must fail before provider access.');
        } catch (TelegramPrivateMediaRejected $exception) {
            self::assertSame('file_too_large', $exception->reasonCode);
        }

        self::assertSame(0, $fetcher->calls);
        self::assertSame(0, DB::table('telegram_private_media')->where('update_id', 88004)->count());
    }

    /** @return array{0:int,1:int} */
    private function telegramIdentity(int $telegramUserId): array
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountId = (int) DB::table('telegram_accounts')->insertGetId([
            'user_id' => $userId,
            'bot_id' => 123456789,
            'telegram_user_id' => $telegramUserId,
            'username' => 'private_media_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$userId, $accountId];
    }

    private function onePixelJpeg(): string
    {
        return $this->decodeImageFixture(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AJgA/9k=',
        );
    }

    private function animatedOnePixelWebp(): string
    {
        return $this->decodeImageFixture(
            'UklGRlIAAABXRUJQVlA4WAoAAAASAAAAAAAAAAAAQU5JTQYAAAD/////AABBTk1GJgAAAAAAAAAAAAAAAAAAAGQAAABWUDhMDQAAAC8AAAAQBxAREYiI/gcA',
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
