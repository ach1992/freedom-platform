<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\TelegramInteractionDispatcher;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramPrivateMediaDownload;
use App\Modules\Telegram\Application\TelegramPrivateMediaIngestor;
use App\Modules\Telegram\Application\TelegramPrivateMediaInteractionGateway;
use App\Modules\Telegram\Application\TelegramUpdateProcessor;
use App\Shared\Application\RestrictedValue;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TelegramSupportAttachmentDispatchFetcher implements TelegramPrivateMediaFetcher
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

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 DAT-002 DAT-003 DAT-004 SEC-002 SEC-003 SEC-009 QUA-001 QUA-004 */
final class TelegramSupportAttachmentDispatchTest extends TestCase
{
    use DatabaseTruncation;

    private const SECRET = 'telegram_webhook_secret_1234567890_safe';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram Support attachment dispatch verification requires MariaDB/MySQL.');
        }

        (require database_path('migrations/2026_08_25_000100_enable_telegram_interaction_authority.php'))->up();
        (require database_path('migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php'))->up();
        (require database_path('migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php'))->up();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
        Queue::fake();
        Storage::fake('telegram_private_media');
        config([
            'app.url' => 'https://bot.example.test',
            'telegram.bot_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'telegram.webhook_secret' => self::SECRET,
            'telegram.webhook_path' => 'api/telegram/webhook',
            'telegram.max_body_bytes' => 1_048_576,
            'telegram.queue' => 'critical',
            'telegram.processing_lease_seconds' => 120,
            'telegram.api_base_url' => 'https://api.telegram.org',
            'telegram.api_timeout_seconds' => 15,
            'telegram.private_media_max_bytes' => 1_048_576,
        ]);
    }

    public function test_customer_document_and_native_video_attach_to_reply_without_private_reference_leakage(): void
    {
        $telegramUserId = 9931;
        [$userId, $accountId, $sessionPublicId] = $this->startActor($telegramUserId, 99100);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $userId,
            'other',
            'Telegram attachments',
            'Initial customer body',
            'tg-attachment:create:customer',
        ));
        $this->moveToState($accountId, $sessionPublicId, 'support_reply', ['ticket_id' => $ticket->id], 'customer-reply');

        $fetcher = new TelegramSupportAttachmentDispatchFetcher("Support attachment plain text\n");
        $this->bindFetcher($fetcher);
        $fileId = 'support-private-provider-document-1';
        $fileUniqueId = 'support-private-provider-document-unique-1';
        $this->accept($this->documentPayload(99101, $telegramUserId, $fileId, $fileUniqueId, strlen($fetcher->content)));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->processWithPersistedFailureDiagnostics($processor, 99101);
        $this->processWithPersistedFailureDiagnostics($processor, 99101);

        self::assertSame(1, $fetcher->calls);
        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        $attachment = DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->first();
        self::assertNotNull($attachment);
        self::assertSame('file', $attachment->kind);
        self::assertSame('text/plain', $attachment->detected_mime);
        self::assertSame(1, (int) $attachment->customer_visible);
        self::assertSame('associated', DB::table('telegram_private_media')->where('update_id', 99101)->value('state'));
        self::assertSame('support_ticket_attachment', DB::table('telegram_private_media')->where('update_id', 99101)->value('association_type'));
        self::assertSame($attachment->public_id, DB::table('telegram_private_media')->where('update_id', 99101)->value('association_public_id'));
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());

        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('support_ticket', $active->state);
        self::assertSame(['ticket_id' => $ticket->id], $active->payload);

        $durable = $this->durableOrdinaryTelegramEvidence();
        self::assertStringNotContainsString($fileId, $durable);
        self::assertStringNotContainsString($fileUniqueId, $durable);
        self::assertStringNotContainsString((string) $attachment->private_media_reference, $durable);
        self::assertStringNotContainsString((string) DB::table('telegram_private_media')->where('update_id', 99101)->value('storage_path'), $durable);

        $this->moveToState($accountId, $active->publicId, 'support_reply', ['ticket_id' => $ticket->id], 'customer-video-reply');
        $fetcher->content = $this->minimalMp4();
        $this->bindFetcher($fetcher);
        $this->accept($this->videoPayload(99102, $telegramUserId, 'support-private-provider-video-1', 'support-private-provider-video-unique-1', strlen($fetcher->content)));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->processWithPersistedFailureDiagnostics($processor, 99102);

        self::assertSame(2, $fetcher->calls);
        self::assertSame(2, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        self::assertSame('video', DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->orderByDesc('id')->value('kind'));
        self::assertSame('video/mp4', DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->orderByDesc('id')->value('detected_mime'));
    }

    public function test_customer_media_for_another_customer_ticket_is_discarded_without_support_effect(): void
    {
        $telegramUserId = 9935;
        [$customerId, $accountId, $sessionPublicId] = $this->startActor($telegramUserId, 99400);
        $otherCustomerId = $this->user();
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $otherCustomerId,
            'other',
            'Foreign attachment target',
            'Initial customer body',
            'tg-attachment:create:foreign-target',
        ));
        self::assertNotSame($customerId, $otherCustomerId);
        $this->moveToState(
            $accountId,
            $sessionPublicId,
            'support_reply',
            ['ticket_id' => $ticket->id],
            'customer-foreign-ticket',
        );

        $fetcher = new TelegramSupportAttachmentDispatchFetcher("Must be discarded after authorization denial\n");
        $this->bindFetcher($fetcher);
        $this->accept($this->documentPayload(
            99401,
            $telegramUserId,
            'support-customer-foreign-ticket-file',
            'support-customer-foreign-ticket-unique',
            strlen($fetcher->content),
        ));
        $this->processWithPersistedFailureDiagnostics($this->app->make(TelegramUpdateProcessor::class), 99401);

        self::assertSame(1, $fetcher->calls);
        self::assertSame(0, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        self::assertSame(1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('discarded', DB::table('telegram_private_media')->where('update_id', 99401)->value('state'));
        self::assertSame('discarded_unassociated', DB::table('telegram_private_media')->where('update_id', 99401)->value('rejection_code'));
        $discardedPath = (string) DB::table('telegram_private_media')->where('update_id', 99401)->value('storage_path');
        Storage::disk('telegram_private_media')->assertMissing($discardedPath);

        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($accountId);
        self::assertNotNull($active);
        self::assertSame('support_reply', $active->state);
        self::assertSame(['ticket_id' => $ticket->id], $active->payload);
    }

    public function test_staff_media_is_public_permission_checked_and_failed_authorization_discards_unassociated_bytes(): void
    {
        [$customerId] = $this->startActor(9932, 99200);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customerId,
            'other',
            'Staff media',
            'Initial customer body',
            'tg-attachment:create:staff-target',
        ));

        $staffTelegramUserId = 9933;
        [$staffUserId, $staffAccountId, $staffSessionPublicId] = $this->startActor($staffTelegramUserId, 99210);
        $this->makeSupportAdministrator($staffUserId);
        $this->moveToState($staffAccountId, $staffSessionPublicId, 'support_queue_reply', ['ticket_id' => $ticket->id], 'staff-reply');

        $fetcher = new TelegramSupportAttachmentDispatchFetcher($this->passivePdf());
        $this->bindFetcher($fetcher);
        $this->accept($this->documentPayload(99211, $staffTelegramUserId, 'support-staff-file-1', 'support-staff-unique-1', strlen($fetcher->content)));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->processWithPersistedFailureDiagnostics($processor, 99211);

        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        $message = DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->orderByDesc('id')->first();
        self::assertNotNull($message);
        self::assertSame('support_reply', $message->kind);
        self::assertSame(1, (int) $message->customer_visible);
        $customerDetail = $this->app->make(SupportTicketService::class)->ticketForCustomer($ticket->id, $customerId);
        self::assertStringContainsString('📎', $customerDetail->messages[1]->body);

        $active = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount($staffAccountId);
        self::assertNotNull($active);
        $this->moveToState($staffAccountId, $active->publicId, 'support_queue_reply', ['ticket_id' => $ticket->id], 'staff-reply-denied');
        $this->denySupportPermission($staffUserId);
        $fetcher->content = "Denied staff attachment\n";
        $this->bindFetcher($fetcher);
        $this->accept($this->documentPayload(99212, $staffTelegramUserId, 'support-staff-file-denied', 'support-staff-unique-denied', strlen($fetcher->content)));
        $processor = $this->app->make(TelegramUpdateProcessor::class);
        $this->processWithPersistedFailureDiagnostics($processor, 99212);

        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('discarded', DB::table('telegram_private_media')->where('update_id', 99212)->value('state'));
        self::assertSame('discarded_unassociated', DB::table('telegram_private_media')->where('update_id', 99212)->value('rejection_code'));
        $discardedPath = (string) DB::table('telegram_private_media')->where('update_id', 99212)->value('storage_path');
        Storage::disk('telegram_private_media')->assertMissing($discardedPath);
    }

    public function test_internal_note_state_rejects_media_before_private_ingestion(): void
    {
        $staffTelegramUserId = 9934;
        [$staffUserId, $accountId, $sessionPublicId] = $this->startActor($staffTelegramUserId, 99300);
        $this->makeSupportAdministrator($staffUserId);
        $customer = $this->user();
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'No internal media',
            'Initial body',
            'tg-attachment:create:note',
        ));
        $this->moveToState($accountId, $sessionPublicId, 'support_queue_note', ['ticket_id' => $ticket->id], 'staff-note');
        $fetcher = new TelegramSupportAttachmentDispatchFetcher("Must not be fetched\n");
        $this->bindFetcher($fetcher);

        $this->accept($this->documentPayload(99301, $staffTelegramUserId, 'support-note-file', 'support-note-unique', strlen($fetcher->content)));
        $this->processWithPersistedFailureDiagnostics($this->app->make(TelegramUpdateProcessor::class), 99301);

        self::assertSame(0, $fetcher->calls);
        self::assertSame(0, DB::table('telegram_private_media')->where('update_id', 99301)->count());
        self::assertSame(0, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
    }

    /** @return array{0:int,1:int,2:string} */
    private function startActor(int $telegramUserId, int $updateId): array
    {
        $this->accept($this->textPayload($updateId, $telegramUserId, '/start'));
        $this->app->make(TelegramUpdateProcessor::class)->process('123456789', $updateId);
        $account = DB::table('telegram_accounts')->where('telegram_user_id', $telegramUserId)->first(['id', 'user_id']);
        self::assertNotNull($account);
        $session = $this->app->make(TelegramInteractionSessionService::class)->activeForAccount((int) $account->id);
        self::assertNotNull($session);

        return [(int) $account->user_id, (int) $account->id, $session->publicId];
    }

    /** @param array<string,mixed> $payload */
    private function moveToState(
        int $accountId,
        string $sessionPublicId,
        string $state,
        array $payload,
        string $suffix,
    ): void {
        $sessions = $this->app->make(TelegramInteractionSessionService::class);
        $active = $sessions->activeForAccount($accountId);
        self::assertNotNull($active);
        $sessions->transition(
            $sessionPublicId,
            $active->version,
            $state,
            $payload,
            'support-attachment-test:'.$suffix.':'.hash('sha256', $sessionPublicId.':'.$active->version),
        );
    }

    private function bindFetcher(TelegramPrivateMediaFetcher $fetcher): void
    {
        $this->app->instance(TelegramPrivateMediaFetcher::class, $fetcher);
        foreach ([
            TelegramPrivateMediaIngestor::class,
            TelegramPrivateMediaInteractionGateway::class,
            TelegramInteractionDispatcher::class,
            TelegramUpdateProcessor::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    private function processWithPersistedFailureDiagnostics(TelegramUpdateProcessor $processor, int $updateId): void
    {
        try {
            $processor->process('123456789', $updateId);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Telegram update processing failed.') {
                throw $exception;
            }

            $update = DB::table('processed_telegram_updates')
                ->where('bot_id', '123456789')
                ->where('update_id', $updateId)
                ->first(['last_error_class', 'last_error_code']);
            self::fail(sprintf(
                'Telegram update processing failed with persisted error class %s (code %s).',
                (string) ($update->last_error_class ?? 'unknown'),
                (string) ($update->last_error_code ?? 'unknown'),
            ));
        }
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

    /** @param array<string,mixed> $payload */
    private function accept(array $payload): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::SECRET)
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();
    }

    /** @return array<string,mixed> */
    private function textPayload(int $updateId, int $telegramUserId, string $text): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => 1_780_000_000,
                'from' => [
                    'id' => $telegramUserId,
                    'is_bot' => false,
                    'username' => 'support_attachment_dispatch',
                    'language_code' => 'fa',
                ],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'text' => $text,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function documentPayload(
        int $updateId,
        int $telegramUserId,
        string $fileId,
        string $fileUniqueId,
        int $fileSize,
    ): array {
        $payload = $this->textPayload($updateId, $telegramUserId, 'unused');
        unset($payload['message']['text']);
        $payload['message']['document'] = [
            'file_id' => $fileId,
            'file_unique_id' => $fileUniqueId,
            'file_name' => 'untrusted-name.php',
            'mime_type' => 'application/x-httpd-php',
            'file_size' => $fileSize,
        ];

        return $payload;
    }

    /** @return array<string,mixed> */
    private function videoPayload(
        int $updateId,
        int $telegramUserId,
        string $fileId,
        string $fileUniqueId,
        int $fileSize,
    ): array {
        $payload = $this->textPayload($updateId, $telegramUserId, 'unused');
        unset($payload['message']['text']);
        $payload['message']['video'] = [
            'file_id' => $fileId,
            'file_unique_id' => $fileUniqueId,
            'width' => 320,
            'height' => 240,
            'duration' => 1,
            'mime_type' => 'application/octet-stream',
            'file_size' => $fileSize,
        ];

        return $payload;
    }

    private function durableOrdinaryTelegramEvidence(): string
    {
        $parts = [];
        foreach ([
            'processed_telegram_updates',
            'telegram_interaction_sessions',
            'telegram_interaction_transitions',
            'telegram_interaction_update_bindings',
            'telegram_interaction_callbacks',
            'telegram_delivery_operations',
            'telegram_delivery_confidential_presentations',
            'outbox_messages',
        ] as $table) {
            $parts[] = json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        }

        return implode("\n", $parts);
    }

    private function passivePdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function minimalMp4(): string
    {
        return pack('N', 24).'ftyp'.'isom'.pack('N', 0).'isomiso2'.pack('N', 8).'mdat';
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
