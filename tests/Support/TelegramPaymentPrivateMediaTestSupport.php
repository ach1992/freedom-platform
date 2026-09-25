<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

trait TelegramPaymentPrivateMediaTestSupport
{
    protected function paymentEvidencePng(int $variant = 0): string
    {
        $fixtures = [
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mPgFpcHAAByAELH9UOGAAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mMwiOsBAAHcARt0nzIgAAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mMIXfoTAANGAfRKBBY8AAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mOoepMGAAOwAc3+auLrAAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mOYb3wZAAMaAabt5xugAAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mM4UuUAAAOEAX9BgEDWAAAAAElFTkSuQmCC',
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mN4eXAtAATuAlhrfOJFAAAAAElFTkSuQmCC',
        ];
        if (! array_key_exists($variant, $fixtures)) {
            throw new RuntimeException('Payment evidence PNG fixture variant is invalid.');
        }

        $decoded = base64_decode($fixtures[$variant], true);
        if (! is_string($decoded)) {
            throw new RuntimeException('Payment evidence PNG fixture could not be decoded.');
        }

        return $decoded;
    }

    protected function paymentPrivateReference(): string
    {
        return 'telegram-private-media:'.strtoupper((string) Str::ulid());
    }

    protected function storePaymentPrivateMedia(
        int $userId,
        string $privateReference,
        string $associationType,
        string $associationPublicId,
        string $contents,
        int $telegramUserId,
    ): string {
        if (preg_match('/\Atelegram-private-media:([0-9A-HJKMNP-TV-Z]{26})\z/', $privateReference, $matches) !== 1
            || ! in_array($associationType, [
                'c2c_manual_submission',
                'gift_card_submission',
                'usdt_txid_submission',
            ], true)
            || ! Str::isUlid($associationPublicId)
            || $contents === '') {
            throw new RuntimeException('Payment private-media test fixture identity is invalid.');
        }

        $now = now('UTC');
        $accountId = DB::table('telegram_accounts')
            ->where('bot_id', 123456789)
            ->where('user_id', $userId)
            ->value('id');
        if (! is_int($accountId) && ! is_string($accountId)) {
            $accountId = DB::table('telegram_accounts')->insertGetId([
                'user_id' => $userId,
                'bot_id' => 123456789,
                'telegram_user_id' => $telegramUserId,
                'username' => 'payment_evidence_test',
                'language_code' => 'en',
                'is_bot' => false,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $mediaPublicId = strtoupper($matches[1]);
        $path = 'receipts/'.substr(hash('sha256', $mediaPublicId), 0, 2).'/'.$mediaPublicId.'.media';
        $hash = hash('sha256', $contents);
        Storage::fake('telegram_private_media');
        Storage::disk('telegram_private_media')->put($path, $contents);
        DB::table('telegram_private_media')->insert([
            'public_id' => $mediaPublicId,
            'bot_id' => '123456789',
            'update_id' => $telegramUserId,
            'telegram_account_id' => (int) $accountId,
            'user_id' => $userId,
            'source_kind' => 'photo',
            'encrypted_file_id' => 'payment-evidence-encrypted-file-id',
            'encrypted_file_unique_id' => 'payment-evidence-encrypted-unique-id',
            'reported_file_size' => strlen($contents),
            'storage_path' => $path,
            'state' => 'associated',
            'detected_mime' => 'image/png',
            'byte_size' => strlen($contents),
            'content_sha256' => $hash,
            'association_type' => $associationType,
            'association_public_id' => strtoupper($associationPublicId),
            'rejection_code' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $path;
    }
}
