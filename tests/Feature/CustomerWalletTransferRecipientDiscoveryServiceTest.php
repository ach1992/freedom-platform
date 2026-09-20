<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Application\CustomerWalletTransferRecipientDiscoveryService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement WAL-003 SEC-003 DAT-003 QUA-001 */
final class CustomerWalletTransferRecipientDiscoveryServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_exact_username_telegram_id_and_public_id_resolution_is_self_excluding_and_locale_aware(): void
    {
        [$senderId, $senderPublicId] = $this->user('active', 'fa');
        [$recipientId, $recipientPublicId] = $this->user('active', 'en');
        $this->telegramAccount($senderId, 800001, 'sender_user');
        $this->telegramAccount($recipientId, 800002, 'recipient_user');

        $service = $this->app->make(CustomerWalletTransferRecipientDiscoveryService::class);

        $username = $service->searchForSelf($senderId, $senderId, '123456', '@recipient_user');
        self::assertTrue($username->isMatched());
        self::assertSame($recipientPublicId, $username->recipient?->accountPublicId);
        self::assertSame('800002', $username->recipient?->telegramUserId);
        self::assertSame('en', $username->recipient?->locale);
        self::assertNotSame('recipient_user', $username->recipient?->maskedUsername);

        $telegramId = $service->searchForSelf($senderId, $senderId, '123456', '800002');
        self::assertTrue($telegramId->isMatched());
        self::assertSame($recipientPublicId, $telegramId->recipient?->accountPublicId);

        $publicId = $service->searchForSelf($senderId, $senderId, '123456', $recipientPublicId);
        self::assertTrue($publicId->isMatched());

        $self = $service->searchForSelf($senderId, $senderId, '123456', $senderPublicId);
        self::assertFalse($self->isMatched());
        self::assertFalse($self->isAmbiguous());
    }

    public function test_inactive_recipient_is_not_found_and_cross_identity_collision_is_ambiguous(): void
    {
        [$senderId] = $this->user('active', 'fa');
        [$inactiveId] = $this->user('suspended', 'fa');
        [$usernameId] = $this->user('active', 'fa');
        [$telegramIdOwner] = $this->user('active', 'fa');

        $this->telegramAccount($inactiveId, 810001, 'inactive_user');
        $this->telegramAccount($usernameId, 810002, '1234567');
        $this->telegramAccount($telegramIdOwner, 1234567, 'other_user');

        $service = $this->app->make(CustomerWalletTransferRecipientDiscoveryService::class);

        $inactive = $service->searchForSelf($senderId, $senderId, '123456', '@inactive_user');
        self::assertFalse($inactive->isMatched());

        $ambiguous = $service->searchForSelf($senderId, $senderId, '123456', '1234567');
        self::assertTrue($ambiguous->isAmbiguous());
        self::assertFalse($ambiguous->isMatched());
    }

    /** @return array{0:int,1:string} */
    private function user(string $status, string $locale): array
    {
        $now = now('UTC');
        $publicId = (string) Str::ulid();
        $id = (int) DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'account_type' => 'customer',
            'account_status' => $status,
            'locale' => $locale,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$id, $publicId];
    }

    private function telegramAccount(int $userId, int $telegramUserId, string $username): void
    {
        $now = now('UTC');
        DB::table('telegram_accounts')->insert([
            'user_id' => $userId,
            'bot_id' => 123456,
            'telegram_user_id' => $telegramUserId,
            'username' => $username,
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
