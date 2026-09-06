<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use App\Modules\Telegram\Application\TelegramPrivateMediaInput;
use App\Modules\Telegram\Application\TelegramPrivateMediaRejected;
use App\Shared\Application\RestrictedValue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TelegramPrivateMediaDecoderBoundaryFetcher implements TelegramPrivateMediaFetcher
{
    public int $calls = 0;

    public function __construct(public string $content) {}

    public function fetch(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): TelegramPrivateMediaDownload {
        $this->calls++;

        return TelegramPrivateMediaDownload::fromBytes($this->content, strlen($this->content));
    }
}

/** @requirement C2C-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
final class TelegramPrivateMediaDecoderBoundaryTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram private-media decoder verification requires MariaDB/MySQL.');
        }

        Storage::fake('telegram_private_media');
        config()->set('telegram.private_media_max_bytes', 1_048_576);
    }

    public function test_undecodable_png_and_jpeg_never_become_private_receipt_evidence(): void
    {
        [$userId, $accountId] = $this->telegramIdentity(9891);
        $fetcher = new TelegramPrivateMediaDecoderBoundaryFetcher($this->invalidIdatPng());
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $service = $this->app->make(TelegramPrivateMediaIngestor::class);

        $cases = [
            88901 => $this->invalidIdatPng(),
            88902 => $this->insufficientJpeg(),
        ];

        foreach ($cases as $updateId => $content) {
            $fetcher->content = $content;
            $input = new TelegramPrivateMediaInput(
                'document',
                RestrictedValue::fromString('provider-file-decoder-'.$updateId),
                RestrictedValue::fromString('provider-unique-decoder-'.$updateId),
                null,
            );

            try {
                $service->ingest('123456789', $updateId, $accountId, $userId, $input);
                self::fail('Undecodable private media must not become accepted receipt evidence.');
            } catch (TelegramPrivateMediaRejected $exception) {
                self::assertSame('malformed_image', $exception->reasonCode);
            }

            self::assertSame(
                'rejected',
                DB::table('telegram_private_media')->where('update_id', $updateId)->value('state'),
            );
            self::assertSame(
                'malformed_image',
                DB::table('telegram_private_media')->where('update_id', $updateId)->value('rejection_code'),
            );
            self::assertNull(DB::table('telegram_private_media')->where('update_id', $updateId)->value('content_sha256'));
            self::assertNull(DB::table('telegram_private_media')->where('update_id', $updateId)->value('association_type'));
        }

        self::assertSame(2, $fetcher->calls);
        self::assertSame(0, DB::table('c2c_manual_submissions')->count());
        self::assertSame([], Storage::disk('telegram_private_media')->allFiles());
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
            'username' => 'private_media_decoder_test',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$userId, $accountId];
    }

    private function invalidIdatPng(): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
            .$this->pngChunk('IDAT', "\x00")
            .$this->pngChunk('IEND', '');
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .hash('crc32b', $type.$data, true);
    }

    private function insufficientJpeg(): string
    {
        return "\xff\xd8"
            ."\xff\xc0\x00\x0b\x08\x00\x01\x00\x01\x01\x01\x11\x00"
            ."\xff\xda\x00\x08\x01\x01\x00\x00\x3f\x00"
            ."\x00"
            ."\xff\xd9";
    }
}
