<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketRatingService;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Domain\SupportTicketState;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-002 SEC-002 DAT-003 QUA-004 QUA-008 */
final class SupportTicketRatingTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SupportTicketCategorySeeder::class);
    }

    public function test_customer_can_rate_owned_closed_ticket_once_and_replay_same_score(): void
    {
        $customer = $this->user();
        $tickets = $this->app->make(SupportTicketService::class);
        $ratings = $this->app->make(SupportTicketRatingService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Rating lifecycle',
            'Customer feedback must stay append-only.',
            'support-rating:create',
        ));
        $closed = $tickets->closeForCustomer($ticket->id, $customer, 'Completed successfully');
        self::assertSame(SupportTicketState::Closed, $closed->state);
        $lifecycleBeforeRating = DB::table('support_tickets')->where('id', $ticket->id)->first([
            'state',
            'state_version',
            'priority',
            'assigned_user_id',
            'close_reason',
            'closed_at',
            'reopen_until',
        ]);
        self::assertNotNull($lifecycleBeforeRating);

        $created = $ratings->rate($ticket->id, $customer, 5);
        self::assertFalse($created->replayed);
        self::assertSame($ticket->id, $created->rating->ticketId);
        self::assertSame($customer, $created->rating->requesterUserId);
        self::assertSame(5, $created->rating->score);

        $replay = $ratings->rate($ticket->id, $customer, 5);
        self::assertTrue($replay->replayed);
        self::assertSame($created->rating->id, $replay->rating->id);
        self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());

        try {
            $ratings->rate($ticket->id, $customer, 4);
            self::fail('A conflicting second Support rating must be rejected.');
        } catch (DomainException) {
            self::assertSame(5, (int) DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->value('score'));
        }

        $lifecycleAfterRating = DB::table('support_tickets')->where('id', $ticket->id)->first([
            'state',
            'state_version',
            'priority',
            'assigned_user_id',
            'close_reason',
            'closed_at',
            'reopen_until',
        ]);
        self::assertEquals($lifecycleBeforeRating, $lifecycleAfterRating);

        $reopened = $tickets->reopenForCustomer($ticket->id, $customer);
        self::assertSame(SupportTicketState::AwaitingSupport, $reopened->state);
        $preserved = $ratings->ratingForCustomer($ticket->id, $customer);
        self::assertNotNull($preserved);
        self::assertSame(5, $preserved->score);

        try {
            $ratings->rate($ticket->id, $customer, 5);
            self::fail('A reopened Support ticket must not accept a new same-score rating request.');
        } catch (DomainException) {
            self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());
            self::assertSame(5, (int) DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->value('score'));
        }
    }

    public function test_rating_requires_owned_currently_closed_ticket_and_valid_score(): void
    {
        $customer = $this->user();
        $otherCustomer = $this->user();
        $tickets = $this->app->make(SupportTicketService::class);
        $ratings = $this->app->make(SupportTicketRatingService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Rating authorization',
            'Only the requester may rate a closed ticket.',
            'support-rating:authorization',
        ));

        try {
            $ratings->rate($ticket->id, $customer, 5);
            self::fail('An open Support ticket must not be rateable.');
        } catch (DomainException) {
            self::assertSame(0, DB::table('support_ticket_ratings')->count());
        }

        $tickets->closeForCustomer($ticket->id, $customer, 'Ready for rating');

        try {
            $ratings->rate($ticket->id, $otherCustomer, 5);
            self::fail('A different customer must not rate another customer ticket.');
        } catch (RuntimeException) {
            self::assertSame(0, DB::table('support_ticket_ratings')->count());
        }

        foreach ([0, 6, -1] as $invalidScore) {
            try {
                $ratings->rate($ticket->id, $customer, $invalidScore);
                self::fail('Invalid Support ticket rating score must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('support_ticket_ratings')->count());
            }
        }
    }

    public function test_database_enforces_one_immutable_rating_and_closed_requester_identity(): void
    {
        $customer = $this->user();
        $otherCustomer = $this->user();
        $tickets = $this->app->make(SupportTicketService::class);
        $ratings = $this->app->make(SupportTicketRatingService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Database rating barrier',
            'Database constraints must protect the rating authority.',
            'support-rating:database',
        ));

        try {
            DB::table('support_ticket_ratings')->insert([
                'ticket_id' => $ticket->id,
                'requester_user_id' => $customer,
                'score' => 5,
                'created_at' => now('UTC'),
            ]);
            self::fail('The database must reject a rating while the ticket is not closed.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('support_ticket_ratings')->count());
        }

        $tickets->closeForCustomer($ticket->id, $customer, 'Closed for database rating checks');

        try {
            DB::table('support_ticket_ratings')->insert([
                'ticket_id' => $ticket->id,
                'requester_user_id' => $otherCustomer,
                'score' => 5,
                'created_at' => now('UTC'),
            ]);
            self::fail('The database must reject a mismatched rating requester.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('support_ticket_ratings')->count());
        }

        $rating = $ratings->rate($ticket->id, $customer, 5)->rating;

        try {
            DB::table('support_ticket_ratings')->insert([
                'ticket_id' => $ticket->id,
                'requester_user_id' => $customer,
                'score' => 4,
                'created_at' => now('UTC'),
            ]);
            self::fail('The database unique key must reject a second ticket rating.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('support_ticket_ratings')->where('ticket_id', $ticket->id)->count());
        }

        try {
            DB::table('support_ticket_ratings')->where('id', $rating->id)->update(['score' => 4]);
            self::fail('Support ticket ratings must be append-only.');
        } catch (QueryException) {
            self::assertSame(5, (int) DB::table('support_ticket_ratings')->where('id', $rating->id)->value('score'));
        }

        try {
            DB::table('support_ticket_ratings')->where('id', $rating->id)->delete();
            self::fail('Support ticket ratings must not be deletable.');
        } catch (QueryException) {
            self::assertTrue(DB::table('support_ticket_ratings')->where('id', $rating->id)->exists());
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
