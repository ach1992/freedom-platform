<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-004 */
final class SupportTicketTelegramAccessTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
    }

    public function test_support_role_and_owner_are_authorized_but_unprivileged_user_is_denied(): void
    {
        $customer = $this->user();
        $supportUser = $this->administrator(false, 'support');
        $owner = $this->administrator(true);
        $unprivileged = $this->administrator(false);
        $tickets = $this->app->make(SupportTicketService::class);
        $support = $this->app->make(SupportTicketSupportService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest($customer, 'other', 'Authorization', 'Need help', 'access:create'));

        self::assertTrue($support->availableFor($supportUser));
        self::assertTrue($support->availableFor($owner));
        self::assertFalse($support->availableFor($unprivileged));
        self::assertSame([$ticket->id], array_map(static fn ($item): int => $item->id, $support->queue($supportUser)));
        self::assertSame($ticket->id, $support->detail($owner, $ticket->id)->ticket->id);

        $this->expectException(AuthorizationException::class);
        $support->queue($unprivileged);
    }

    public function test_customer_detail_filters_internal_notes_while_authorized_support_detail_retains_them(): void
    {
        $customer = $this->user();
        $supportUser = $this->administrator(false, 'support');
        $tickets = $this->app->make(SupportTicketService::class);
        $support = $this->app->make(SupportTicketSupportService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest($customer, 'other', 'Visibility', 'Customer body', 'visibility:create'));

        $support->reply($supportUser, $ticket->id, 'Public support reply', 'visibility:reply');
        $support->internalNote($supportUser, $ticket->id, 'Private internal note', 'visibility:note');

        $customerDetail = $tickets->ticketForCustomer($ticket->id, $customer);
        $supportDetail = $support->detail($supportUser, $ticket->id);

        self::assertSame(['Customer body', 'Public support reply'], array_map(static fn ($message): string => $message->body, $customerDetail->messages));
        self::assertSame(['Customer body', 'Public support reply', 'Private internal note'], array_map(static fn ($message): string => $message->body, $supportDetail->messages));
        self::assertNotContains('Private internal note', array_map(static fn ($message): string => $message->body, $customerDetail->messages));
    }

    public function test_claim_is_atomic_and_support_permission_is_reauthorized_for_every_mutation(): void
    {
        $customer = $this->user();
        $firstSupport = $this->administrator(false, 'support');
        $secondSupport = $this->administrator(false, 'support');
        $tickets = $this->app->make(SupportTicketService::class);
        $support = $this->app->make(SupportTicketSupportService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest($customer, 'other', 'Claim', 'Please claim', 'claim:create'));

        $claimed = $support->claim($firstSupport, $ticket->id);
        self::assertSame($firstSupport, $claimed->assignedUserId);
        self::assertSame($firstSupport, $support->claim($firstSupport, $ticket->id)->assignedUserId);

        try {
            $support->claim($secondSupport, $ticket->id);
            self::fail('A second support actor must not steal an already claimed ticket.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        $administratorId = (int) DB::table('administrators')->where('user_id', $firstSupport)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->expectException(AuthorizationException::class);
        $support->setPriority($firstSupport, $ticket->id, SupportTicketPriority::Urgent);
    }

    public function test_closed_tickets_leave_support_queue_and_reject_stale_claim_or_priority_mutations(): void
    {
        $customer = $this->user();
        $supportUser = $this->administrator(false, 'support');
        $tickets = $this->app->make(SupportTicketService::class);
        $support = $this->app->make(SupportTicketSupportService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest($customer, 'other', 'Terminal queue', 'Close this ticket', 'terminal:create'));

        $support->transition($supportUser, $ticket->id, SupportTicketState::Closed, 'support_closed', 'Completed');
        self::assertSame([], $support->queue($supportUser));

        foreach ([
            static fn () => $support->claim($supportUser, $ticket->id),
            static fn () => $support->setPriority($supportUser, $ticket->id, SupportTicketPriority::Urgent),
        ] as $mutation) {
            try {
                $mutation();
                self::fail('Closed ticket must reject stale support triage mutation.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        self::assertNull(DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        self::assertSame('normal', DB::table('support_tickets')->where('id', $ticket->id)->value('priority'));
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
