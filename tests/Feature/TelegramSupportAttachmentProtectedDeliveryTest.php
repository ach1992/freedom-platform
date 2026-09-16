<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketAttachmentService;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use App\Modules\Telegram\Application\TelegramPrivateMediaInput;
use App\Modules\Telegram\Application\TelegramPrivateMediaReceipt;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use App\Shared\Application\RestrictedValue;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TelegramSupportAttachmentDeliveryFetcher implements TelegramPrivateMediaFetcher
{
    public function __construct(private readonly string $content) {}

    public function fetch(
        RestrictedValue $fileId,
        RestrictedValue $expectedFileUniqueId,
        int $maximumBytes,
    ): TelegramPrivateMediaDownload {
        return TelegramPrivateMediaDownload::fromBytes($this->content, strlen($this->content));
    }
}

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-008 SEC-009 QUA-001 QUA-004 */
final class TelegramSupportAttachmentProtectedDeliveryTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Protected Support attachment delivery verification requires MariaDB/MySQL.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
        Storage::fake('telegram_private_media');
        config()->set('telegram.private_media_max_bytes', 1_048_576);
    }

    public function test_customer_protected_delivery_resolves_bytes_only_after_owner_and_integrity_checks(): void
    {
        $customer = $this->user(9941);
        $otherCustomer = $this->user(9942);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Protected attachment delivery',
            'Initial body',
            'protected-attachment:create',
        ));
        $content = "Private Support attachment body\n";
        [$media, $attachmentPublicId] = $this->storeCustomerAttachment(
            $customer['user_id'],
            $customer['account_id'],
            $ticket->id,
            $content,
            99411,
        );
        $reference = TelegramProtectedPresentationReference::supportAttachment(
            $attachmentPublicId,
            'customer',
            'fa',
        );

        $presentation = $this->app->make(TelegramProtectedPresentationResolver::class)
            ->resolveForSelf($customer['user_id'], $reference);

        self::assertFalse($presentation->isText());
        self::assertSame($content, $presentation->documentContents());
        self::assertSame('support-attachment-'.$attachmentPublicId.'.txt', $presentation->documentFilename());
        self::assertStringContainsString($attachmentPublicId, $presentation->caption());
        self::assertStringNotContainsString($media->privateReference, $reference->durableText());

        try {
            $this->app->make(TelegramProtectedPresentationResolver::class)
                ->resolveForSelf($otherCustomer['user_id'], $reference);
            self::fail('Another customer must not resolve the protected Support attachment.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $path = (string) DB::table('telegram_private_media')
            ->where('public_id', substr($media->privateReference, strlen('telegram-private-media:')))
            ->value('storage_path');
        Storage::disk('telegram_private_media')->put($path, "tampered\n");

        $this->expectException(RuntimeException::class);
        $this->app->make(TelegramProtectedPresentationResolver::class)
            ->resolveForSelf($customer['user_id'], $reference);
    }

    public function test_support_protected_delivery_reauthorizes_permission_at_resolution_time(): void
    {
        $customer = $this->user(9943);
        $support = $this->user(9944);
        $this->makeSupportAdministrator($support['user_id']);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer['user_id'],
            'other',
            'Protected staff delivery',
            'Initial body',
            'protected-attachment:staff:create',
        ));
        [, $attachmentPublicId] = $this->storeCustomerAttachment(
            $customer['user_id'],
            $customer['account_id'],
            $ticket->id,
            "Attachment visible to authorized Support\n",
            99431,
        );
        $reference = TelegramProtectedPresentationReference::supportAttachment(
            $attachmentPublicId,
            'support',
            'en',
        );
        $resolver = $this->app->make(TelegramProtectedPresentationResolver::class);

        $presentation = $resolver->resolveForSelf($support['user_id'], $reference);
        self::assertFalse($presentation->isText());
        self::assertStringContainsString($attachmentPublicId, $presentation->caption());

        $this->denySupportPermission($support['user_id']);

        $this->expectException(DomainException::class);
        $resolver->resolveForSelf($support['user_id'], $reference);
    }

    /**
     * @return array{0:TelegramPrivateMediaReceipt,1:string}
     */
    private function storeCustomerAttachment(
        int $userId,
        int $accountId,
        int $ticketId,
        string $content,
        int $updateId,
    ): array {
        $this->app->instance(
            TelegramPrivateMediaFetcher::class,
            new TelegramSupportAttachmentDeliveryFetcher($content),
        );
        $this->app->forgetInstance(TelegramPrivateMediaIngestor::class);
        $mediaService = $this->app->make(TelegramPrivateMediaIngestor::class);
        $media = $mediaService->ingest(
            '123456789',
            $updateId,
            $accountId,
            $userId,
            new TelegramPrivateMediaInput(
                'document',
                RestrictedValue::fromString('protected-delivery-file-'.$updateId),
                RestrictedValue::fromString('protected-delivery-unique-'.$updateId),
                strlen($content),
            ),
        );
        $attachment = $this->app->make(SupportTicketAttachmentService::class)->addForCustomer(
            $ticketId,
            $userId,
            'file',
            $media->detectedMime,
            $media->byteSize,
            $media->contentSha256,
            RestrictedValue::fromString($media->privateReference),
            'protected-delivery-attachment:'.$updateId,
        );
        $mediaService->associate(
            $media,
            $userId,
            'support_ticket_attachment',
            $attachment->attachment->publicId,
        );

        return [$media, $attachment->attachment->publicId];
    }

    /** @return array{user_id:int,account_id:int} */
    private function user(int $telegramUserId): array
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
            'username' => 'support_attachment_delivery',
            'language_code' => 'fa',
            'is_bot' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['user_id' => $userId, 'account_id' => $accountId];
    }

    private function makeSupportAdministrator(int $userId): void
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) DB::table('roles')->where('code', 'support')->value('id');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => null,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function denySupportPermission(int $userId): void
    {
        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        $administratorId = (int) DB::table('administrators')->where('user_id', $userId)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
