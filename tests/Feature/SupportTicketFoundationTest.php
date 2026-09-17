<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\SupportTicketPriority;
use App\Modules\Support\Domain\SupportTicketState;
use App\Shared\Application\Clock;
use Database\Seeders\SupportTicketCategorySeeder;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 SEC-002 DAT-003 QUA-001 QUA-004 */
final class SupportTicketFoundationTest extends TestCase
{
    use DatabaseTruncation;

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

        try {
            $service->closeForCustomer($first->id, $secondUser, 'Not mine');
            self::fail('A customer must not close another customer ticket.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }
        self::assertSame(SupportTicketState::New->value, DB::table('support_tickets')->where('id', $first->id)->value('state'));
    }

    public function test_idempotency_key_cannot_replay_a_different_message_payload(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $user = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest($user, 'other', 'Strict replay', 'Initial body', 'create:strict'));

        $service->replyAsCustomer($ticket->id, $user, 'First body', 'reply:strict');

        try {
            $service->replyAsCustomer($ticket->id, $user, 'Different body', 'reply:strict');
            self::fail('Support ticket idempotency keys must bind to one exact message payload.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame('First body', DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->where('idempotency_key', 'reply:strict')->value('body'));
    }

    public function test_triage_updates_assignment_and_priority(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $assignee = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest($customer, 'other', 'Triage', 'Needs review', 'create:triage'));

        $triaged = $service->triage($ticket->id, $assignee, SupportTicketPriority::High);

        self::assertSame($assignee, $triaged->assignedUserId);
        self::assertSame(SupportTicketPriority::High, $triaged->priority);
        self::assertSame($assignee, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        self::assertSame('high', DB::table('support_tickets')->where('id', $ticket->id)->value('priority'));
    }

    public function test_close_sets_default_72_hour_window_and_customer_reopen_fails_after_expiry(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $user = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest($user, 'other', 'Reopen', 'Need help', 'create:reopen'));

        $service->transition($ticket->id, SupportTicketState::Resolved, $user, 'support_resolved');
        $closed = $service->closeForCustomer($ticket->id, $user, 'Solved');
        self::assertSame('2026-09-19 00:00:00.000000', $closed->reopenUntil);
        self::assertNotNull(DB::table('support_tickets')->where('id', $ticket->id)->value('resolved_at'));

        $support = $this->user();
        $operatorTicket = $service->create(new SupportTicketCreateRequest(
            $user,
            'other',
            'Operator reopen default',
            'Operator close must keep the 72-hour default.',
            'create:operator-reopen-default',
        ));
        $service->transition($operatorTicket->id, SupportTicketState::Resolved, $support, 'support_resolved');
        $operatorClosed = $service->transition(
            $operatorTicket->id,
            SupportTicketState::Closed,
            $support,
            'support_closed',
            'Solved by support',
        );
        self::assertSame('2026-09-19 00:00:00.000000', $operatorClosed->reopenUntil);

        $clock->set(new DateTimeImmutable('2026-09-18T23:59:59+00:00'));
        $reopened = $service->reopenForCustomer($ticket->id, $user);
        self::assertSame(SupportTicketState::AwaitingSupport, $reopened->state);
        self::assertNull(DB::table('support_tickets')->where('id', $ticket->id)->value('resolved_at'));

        $service->closeForCustomer($ticket->id, $user, 'Solved again');
        $clock->set(new DateTimeImmutable('2026-09-22T00:00:00+00:00'));

        $this->expectException(DomainException::class);
        $service->reopenForCustomer($ticket->id, $user);
    }

    public function test_configured_reopen_window_applies_to_customer_and_support_close_and_is_snapshotted(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        config(['support.reopen_window_hours' => '24']);
        $closedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expectedReopenUntil = $closedAt->modify('+24 hours')->format('Y-m-d H:i:s.u');
        $clock = new MutableSupportClock($closedAt);
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $support = $this->user();

        $customerTicket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Customer close window',
            'Customer close must use configured window.',
            'create:configured-reopen-customer',
        ));
        $service->transition($customerTicket->id, SupportTicketState::Resolved, $support, 'support_resolved');
        $customerClosed = $service->closeForCustomer($customerTicket->id, $customer, 'Solved by customer');
        self::assertSame($expectedReopenUntil, $customerClosed->reopenUntil);

        $supportTicket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Support close window',
            'Support close must use configured window.',
            'create:configured-reopen-support',
        ));
        $service->transition($supportTicket->id, SupportTicketState::Resolved, $support, 'support_resolved');
        $supportClosed = $service->transition(
            $supportTicket->id,
            SupportTicketState::Closed,
            $support,
            'support_closed',
            'Solved by support',
        );
        self::assertSame($expectedReopenUntil, $supportClosed->reopenUntil);

        config(['support.reopen_window_hours' => 1]);
        self::assertSame(
            $expectedReopenUntil,
            DB::table('support_tickets')->where('id', $customerTicket->id)->value('reopen_until'),
        );
        self::assertSame(
            $expectedReopenUntil,
            DB::table('support_tickets')->where('id', $supportTicket->id)->value('reopen_until'),
        );

        $clock->set($closedAt->modify('+2 hours'));
        self::assertSame(
            SupportTicketState::AwaitingSupport,
            $service->reopenForCustomer($customerTicket->id, $customer)->state,
        );
        self::assertSame(
            SupportTicketState::AwaitingSupport,
            $service->reopenForCustomer($supportTicket->id, $customer)->state,
        );
    }

    public function test_invalid_reopen_window_configuration_fails_closed_before_customer_close_mutation(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Invalid reopen config',
            'Invalid configuration must not mutate the ticket.',
            'create:invalid-reopen-config',
        ));

        foreach ([0, -1, 721, '0', '721', 'invalid', 1.5, true, null] as $invalid) {
            config(['support.reopen_window_hours' => $invalid]);
            try {
                $service->closeForCustomer($ticket->id, $customer, 'Must not close');
                self::fail('Invalid Support reopen configuration must fail closed.');
            } catch (RuntimeException) {
                self::assertSame(SupportTicketState::New->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
                self::assertNull(DB::table('support_tickets')->where('id', $ticket->id)->value('reopen_until'));
                self::assertSame(1, DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->count());
            }
        }
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

    public function test_exact_reply_replay_survives_later_terminal_state(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $support = $this->user();

        $customerTicket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Customer replay',
            'Initial body',
            'create:customer-terminal-replay',
        ));
        $firstCustomerReply = $service->replyAsCustomer(
            $customerTicket->id,
            $customer,
            'Committed customer reply',
            'reply:customer-terminal-replay',
        );
        $service->transition($customerTicket->id, SupportTicketState::Resolved, $support, 'support_resolved');

        $customerReplay = $service->replyAsCustomer(
            $customerTicket->id,
            $customer,
            'Committed customer reply',
            'reply:customer-terminal-replay',
        );
        self::assertTrue($customerReplay->replayed);
        self::assertSame($firstCustomerReply->messageId, $customerReplay->messageId);
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $customerTicket->id)->count());
        self::assertSame(2, DB::table('support_ticket_state_histories')->where('ticket_id', $customerTicket->id)->count());

        try {
            $service->replyAsCustomer(
                $customerTicket->id,
                $customer,
                'Conflicting delayed retry',
                'reply:customer-terminal-replay',
            );
            self::fail('Conflicting delayed replay must remain rejected after terminal transition.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $supportTicket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Support replay',
            'Initial support case',
            'create:support-terminal-replay',
        ));
        $firstSupportReply = $service->addSupportMessage(
            $supportTicket->id,
            $support,
            'Committed support reply',
            'reply:support-terminal-replay',
        );
        $service->transition($supportTicket->id, SupportTicketState::Closed, $support, 'support_closed', 'Resolved by support');

        $supportReplay = $service->addSupportMessage(
            $supportTicket->id,
            $support,
            'Committed support reply',
            'reply:support-terminal-replay',
        );
        self::assertTrue($supportReplay->replayed);
        self::assertSame($firstSupportReply->messageId, $supportReplay->messageId);
        self::assertSame(2, DB::table('support_ticket_messages')->where('ticket_id', $supportTicket->id)->count());
        self::assertSame(3, DB::table('support_ticket_state_histories')->where('ticket_id', $supportTicket->id)->count());
    }

    public function test_database_transition_guard_keeps_state_and_history_one_atomic_chain(): void
    {
        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $support = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Database authority',
            'Initial body',
            'create:database-authority',
        ));

        self::assertSame(1, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('state_version'));
        self::assertSame([1], DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->orderBy('to_version')->pluck('to_version')->map(static fn (mixed $value): int => (int) $value)->all());

        try {
            DB::table('support_tickets')->where('id', $ticket->id)->update([
                'state' => SupportTicketState::Closed->value,
                'updated_at' => '2026-09-16 00:01:00.000000',
            ]);
            self::fail('Direct lifecycle mutation without exact versioned transition metadata must fail.');
        } catch (QueryException) {
            self::assertSame(SupportTicketState::New->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));
        }

        $clock->set(new DateTimeImmutable('2026-09-16T00:02:00+00:00'));
        $service->transition($ticket->id, SupportTicketState::Investigating, $support, 'support_investigating');

        self::assertSame(2, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('state_version'));
        self::assertSame(
            [
                [null, SupportTicketState::New->value, null, 1],
                [SupportTicketState::New->value, SupportTicketState::Investigating->value, 1, 2],
            ],
            DB::table('support_ticket_state_histories')
                ->where('ticket_id', $ticket->id)
                ->orderBy('to_version')
                ->get(['from_state', 'to_state', 'from_version', 'to_version'])
                ->map(static fn (object $row): array => [
                    $row->from_state === null ? null : (string) $row->from_state,
                    (string) $row->to_state,
                    $row->from_version === null ? null : (int) $row->from_version,
                    (int) $row->to_version,
                ])
                ->all(),
        );

        try {
            DB::table('support_ticket_state_histories')->insert([
                'ticket_id' => $ticket->id,
                'from_state' => SupportTicketState::Investigating->value,
                'to_state' => SupportTicketState::AwaitingSupport->value,
                'from_version' => 2,
                'to_version' => 3,
                'actor_user_id' => $support,
                'reason_code' => 'forged_history',
                'created_at' => '2026-09-16 00:03:00.000000',
            ]);
            self::fail('History cannot advance independently of the authoritative ticket state.');
        } catch (QueryException) {
            self::assertSame(2, DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->count());
        }
    }

    public function test_concurrent_same_key_customer_replies_converge_to_one_message_and_transition(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for Support reply concurrency verification.');
        }

        $this->seed(SupportTicketCategorySeeder::class);
        $clock = new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:00:00+00:00'));
        $service = new SupportTicketService($this->app->make(DatabaseManager::class), $clock);
        $customer = $this->user();
        $support = $this->user();
        $ticket = $service->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Concurrent reply',
            'Initial body',
            'create:concurrent-reply',
        ));
        $service->addSupportMessage($ticket->id, $support, 'Please reply', 'support:concurrent-reply');
        self::assertSame(SupportTicketState::AwaitingCustomer->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));

        $prefix = sys_get_temp_dir().'/support-reply-'.bin2hex(random_bytes(8));
        $barrier = $prefix.'-go';
        $results = [$prefix.'-1', $prefix.'-2'];
        DB::disconnect();

        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                $outcome = ['error' => 'unknown'];
                try {
                    while (! file_exists($barrier)) {
                        usleep(1000);
                    }
                    DB::reconnect();
                    $childService = new SupportTicketService(
                        $this->app->make(DatabaseManager::class),
                        new MutableSupportClock(new DateTimeImmutable('2026-09-16T00:01:00+00:00')),
                    );
                    $receipt = $childService->replyAsCustomer(
                        $ticket->id,
                        $customer,
                        'One concurrent payload',
                        'reply:concurrent-same-key',
                    );
                    $outcome = ['message_id' => $receipt->messageId, 'replayed' => $receipt->replayed];
                } catch (\Throwable $throwable) {
                    $outcome = ['error' => $throwable::class.':'.$throwable->getMessage()];
                }
                file_put_contents($results[$index], json_encode($outcome, JSON_THROW_ON_ERROR));
                exit(0);
            }
            $children[] = $pid;
        }

        touch($barrier);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        DB::reconnect();

        $outcomes = array_map(static function (string $file): array {
            $decoded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $decoded;
        }, $results);
        foreach ($outcomes as $outcome) {
            self::assertArrayNotHasKey('error', $outcome, (string) ($outcome['error'] ?? ''));
        }
        self::assertSame($outcomes[0]['message_id'], $outcomes[1]['message_id']);
        $replayed = [(bool) $outcomes[0]['replayed'], (bool) $outcomes[1]['replayed']];
        sort($replayed);
        self::assertSame([false, true], $replayed);
        self::assertSame(3, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());
        self::assertSame(3, DB::table('support_ticket_state_histories')->where('ticket_id', $ticket->id)->count());
        self::assertSame(SupportTicketState::AwaitingSupport->value, DB::table('support_tickets')->where('id', $ticket->id)->value('state'));

        @unlink($barrier);
        foreach ($results as $file) {
            @unlink($file);
        }
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
