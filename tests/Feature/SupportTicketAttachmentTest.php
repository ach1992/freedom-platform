<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketAttachmentMetadataService;
use App\Modules\Support\Application\SupportTicketAttachmentService;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\RestrictedValue;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 DAT-003 DAT-004 SEC-002 SEC-003 QUA-001 QUA-004 */
final class SupportTicketAttachmentTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Support attachment authority verification requires MariaDB/MySQL.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
    }

    public function test_customer_attachment_is_owner_scoped_append_only_and_exactly_replayable(): void
    {
        $customer = $this->user();
        $otherCustomer = $this->user();
        $tickets = $this->app->make(SupportTicketService::class);
        $attachments = $this->app->make(SupportTicketAttachmentService::class);
        $metadata = $this->app->make(SupportTicketAttachmentMetadataService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Attachment authority',
            'Initial body',
            'attachment:create',
        ));
        $reference = 'telegram-private-media:'.strtoupper((string) Str::ulid());
        $content = "Customer support attachment\n";

        $first = $attachments->addForCustomer(
            $ticket->id,
            $customer,
            'file',
            'text/plain',
            strlen($content),
            hash('sha256', $content),
            RestrictedValue::fromString($reference),
            'attachment:customer:1',
        );
        $replay = $attachments->addForCustomer(
            $ticket->id,
            $customer,
            'file',
            'text/plain',
            strlen($content),
            hash('sha256', $content),
            RestrictedValue::fromString($reference),
            'attachment:customer:1',
        );

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->attachment->publicId, $replay->attachment->publicId);
        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame($reference, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->value('private_media_reference'));

        $safe = $metadata->forCustomer($ticket->id, $customer);
        self::assertCount(1, $safe);
        self::assertSame($first->attachment->publicId, $safe[0]->publicId);
        self::assertArrayNotHasKey('privateMediaReference', get_object_vars($safe[0]));
        self::assertArrayNotHasKey('contentSha256', get_object_vars($safe[0]));

        try {
            $metadata->forCustomer($ticket->id, $otherCustomer);
            self::fail('A customer must not enumerate another customer ticket attachments.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        try {
            $attachments->deliveryForCustomer($first->attachment->publicId, $otherCustomer);
            self::fail('A customer must not resolve another customer private attachment.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        try {
            $attachments->addForCustomer(
                $ticket->id,
                $customer,
                'file',
                'text/plain',
                strlen($content),
                hash('sha256', $content.'conflict'),
                RestrictedValue::fromString($reference),
                'attachment:customer:1',
            );
            self::fail('Attachment idempotency key must bind to one exact payload.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        try {
            DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->update(['byte_size' => 1]);
            self::fail('Support ticket attachment update must be blocked by the database.');
        } catch (QueryException) {
            self::assertSame(strlen($content), (int) DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->value('byte_size'));
        }
        try {
            DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->delete();
            self::fail('Support ticket attachment delete must be blocked by the database.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
        }
    }

    public function test_exact_customer_attachment_replay_survives_later_terminal_state_but_new_attachment_is_rejected(): void
    {
        $customer = $this->user();
        $supportUser = $this->administrator(false, 'support');
        $tickets = $this->app->make(SupportTicketService::class);
        $attachments = $this->app->make(SupportTicketAttachmentService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Terminal attachment replay',
            'Initial body',
            'attachment:terminal:create',
        ));
        $content = "Committed attachment\n";
        $reference = 'telegram-private-media:'.strtoupper((string) Str::ulid());

        $first = $attachments->addForCustomer(
            $ticket->id,
            $customer,
            'file',
            'text/plain',
            strlen($content),
            hash('sha256', $content),
            RestrictedValue::fromString($reference),
            'attachment:terminal:committed',
        );
        $tickets->transition($ticket->id, SupportTicketState::Closed, $supportUser, 'support_closed', 'Completed');

        $replay = $attachments->addForCustomer(
            $ticket->id,
            $customer,
            'file',
            'text/plain',
            strlen($content),
            hash('sha256', $content),
            RestrictedValue::fromString($reference),
            'attachment:terminal:committed',
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->attachment->publicId, $replay->attachment->publicId);
        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());

        try {
            $attachments->addForCustomer(
                $ticket->id,
                $customer,
                'file',
                'text/plain',
                3,
                hash('sha256', 'new'),
                RestrictedValue::fromString('telegram-private-media:'.strtoupper((string) Str::ulid())),
                'attachment:terminal:new',
            );
            self::fail('A new customer attachment must not be accepted on a terminal ticket.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
    }

    public function test_support_attachment_is_public_and_permission_is_reauthorized_for_each_access(): void
    {
        $customer = $this->user();
        $supportUser = $this->administrator(false, 'support');
        $tickets = $this->app->make(SupportTicketService::class);
        $attachments = $this->app->make(SupportTicketAttachmentService::class);
        $metadata = $this->app->make(SupportTicketAttachmentMetadataService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Support attachment',
            'Initial customer body',
            'attachment:support:create',
        ));
        $content = "%PDF-1.4\n%%EOF\n";
        $reference = 'telegram-private-media:'.strtoupper((string) Str::ulid());

        $receipt = $attachments->addForSupport(
            $supportUser,
            $ticket->id,
            'file',
            'application/pdf',
            strlen($content),
            hash('sha256', $content),
            RestrictedValue::fromString($reference),
            'attachment:support:1',
        );

        $customerDetail = $tickets->ticketForCustomer($ticket->id, $customer);
        self::assertStringContainsString($receipt->attachment->publicId, $customerDetail->messages[1]->body);
        self::assertStringNotContainsString($reference, $customerDetail->messages[1]->body);
        self::assertCount(1, $metadata->forSupport($supportUser, $ticket->id));
        self::assertSame($reference, $attachments->deliveryForSupport($supportUser, $receipt->attachment->publicId)->privateMediaReference->reveal());

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        $administratorId = (int) DB::table('administrators')->where('user_id', $supportUser)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        foreach ([
            static fn () => $metadata->forSupport($supportUser, $ticket->id),
            static fn () => $attachments->deliveryForSupport($supportUser, $receipt->attachment->publicId),
            static fn () => $attachments->addForSupport(
                $supportUser,
                $ticket->id,
                'file',
                'text/plain',
                3,
                hash('sha256', 'new'),
                RestrictedValue::fromString('telegram-private-media:'.strtoupper((string) Str::ulid())),
                'attachment:support:denied',
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail('Support ticket attachment authority must reauthorize support.tickets.manage.');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(1, DB::table('support_ticket_attachments')->where('ticket_id', $ticket->id)->count());
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

    private function administrator(bool $owner, ?string $roleCode = null): int
    {
        $userId = $this->user();
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($roleCode !== null) {
            $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
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

        return $userId;
    }
}
