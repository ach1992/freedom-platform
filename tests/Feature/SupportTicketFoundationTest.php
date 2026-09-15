<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\Clock;
use Database\Seeders\SupportTicketCategorySeeder;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 SEC-002 DAT-003 QUA-001 QUA-004 */
final class SupportTicketFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_seed_idempotently_without_overwriting_operator_edits(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        self::assertSame(6, DB::table('support_ticket_categories')->count());

        DB::table('support_ticket_categories')->where('code', 'other')->update(['name_en' => 'Custom other']);
        $this->seed(SupportTicketCategorySeeder::class);

        self::assertSame(6, DB::table('support_ticket_categories')->count());
        self::assertSame('Custom other', DB::table('support_ticket_categories')->where('code', 'other')->value('name_en'));
    }

    public function test_customer_reads_are_owner_scoped_and_duplicate_reply_is_idempotent(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $firstUser = $this->user();
        $secondUser = $this->user();

        $first = $service->create(new SupportTicketCreateRequest(
            $firstUser,
            'other',
            'First ticket',
            'Initial customer message',
            'create:first',
        ));
        $second = $service->create(new SupportTicketCreateRequest(
            $secondUser,
            'other',
            'Second ticket',
            'Second customer message',
            'create:second',
        ));

        self::assertMatchesRegularExpression('/\ATKT-[A-Z0-9]{16}\z/', $first->trackingNumber);
        self::assertNotSame($first->trackingNumber, $second->trackingNumber);
        self::assertSame([$first->id], array_map(static fn ($ticket): int => $ticket->id, $service->ticketsForCustomer($firstUser)));
        self::assertSame([$second->id], array_map(static fn ($ticket): int => $ticket->id, $service->ticketsForCustomer($secondUser)));

        $firstReply = $service->replyAsCustomer($first->id, $firstUser, 'One follow-up', 'reply:one');
        $replay = $service->replyAsCustomer($first->id, $firstUser, 'One follow-up', 'reply:one');

        self::assertFalse($firstReply->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($firstReply->messageId, $replay->messageId);
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $first->id)->count());
    }

    public function test_close_sets_default_72_hour_window_and_customer_reopen_fails_after_expiry(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $user = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest($user, 'other', 'Reopen', 'Need help', 'create:reopen'));

        $closed = $service->transition($ticket->id, SupportTicketState::Closed, $user, 'customer_closed', 'Solved');
        self::assertSame('2026-09-19 00:00:00.000000', $closed->reopenUntil);

        $clock->set(new DateTimeImmutable('2026-09-18T23:59:59+00:00'));
        $reopened = $service->reopenForCustomer($ticket->id, $user);
        self::assertSame(SupportTicketState::AwaitingSupport, $reopened->state);

        $service->transition($ticket->id, SupportTicketState::Closed, $user, 'customer_closed_again', 'Solved again');
        $clock->set(new DateTimeImmutable('2026-09-22T00:00:00+00:00'));

        $this->expectException(DomainException::class);
        $service->reopenForCustomer($ticket->id, $user);
    }

    public function test_message_and_state_history_are_database_append_only(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $user = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest($user, 'other', 'Immutable', 'Original body', 'create:immutable'));

        try {
            DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->update(['body' => 'changed']);
            self::fail('Support ticket message update must be blocked by the database.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->delete();
            self::fail('Support ticket state history delete must be blocked by the database.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame('Original body', DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->value('body'));
        self::assertSame(1, DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->count());
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

final class MutableSupportClock implements Clock
{
    public function __construct(private DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function set(DateTimeImmutable $value): void
    {
        $this->value = $value;
    }
}
