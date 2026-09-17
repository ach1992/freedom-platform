<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketRoutingService;
use App\Modules\Support\Application\SupportTicketSearchField;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSupportService;
use App\Modules\Support\Domain\SupportTicketState;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 SEC-002 DAT-003 QUA-004 QUA-008 */
final class SupportTicketRoutingDiscoveryTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use DatabaseTruncation;
    use PurchaseOrderTestSupport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_authorized_exact_search_covers_ticket_user_order_payment_and_service_without_free_form_surface(): void
    {
        $references = $this->referenceBundle('support-routing-search');
        $tickets = new SupportTicketService($this->app->make(DatabaseManager::class), $this->purchaseOrderClock);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $references['user_id'],
            'technical_service',
            'Exact routing discovery',
            'Search must stay inside the Support ticket authority.',
            'support-routing:search',
            orderId: $references['order_id'],
            paymentIntentId: $references['payment_intent_id'],
            serviceSubscriptionId: $references['service_subscription_id'],
        ));
        $operator = $this->administrator(false, 'support');
        $routing = $this->app->make(SupportTicketRoutingService::class);

        foreach ([
            [SupportTicketSearchField::Tracking, strtolower($ticket->trackingNumber)],
            [SupportTicketSearchField::User, (string) $references['user_id']],
            [SupportTicketSearchField::Order, (string) $references['order_id']],
            [SupportTicketSearchField::Payment, (string) $references['payment_intent_id']],
            [SupportTicketSearchField::Service, (string) $references['service_subscription_id']],
        ] as [$field, $value]) {
            $results = $routing->search($operator, $field, $value, 10);
            self::assertSame([$ticket->id], array_map(static fn ($item): int => $item->id, $results));
            self::assertSame($ticket->trackingNumber, $results[0]->trackingNumber);
            self::assertSame($references['user_id'], $results[0]->requesterUserId);
        }

        foreach ([
            [SupportTicketSearchField::Tracking, 'TKT-*'],
            [SupportTicketSearchField::User, '1 OR 1=1'],
            [SupportTicketSearchField::Order, '0'],
            [SupportTicketSearchField::Payment, '-1'],
            [SupportTicketSearchField::Service, '999999999999999999999999'],
        ] as [$field, $value]) {
            try {
                $routing->search($operator, $field, $value);
                self::fail('Invalid exact Support search input must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $routing->search($operator, SupportTicketSearchField::User, (string) $references['user_id'], 51);
    }

    public function test_search_reauthorizes_at_execution_time_and_denies_unprivileged_customer_data_access(): void
    {
        $customer = $this->user();
        $operator = $this->administrator(false, 'support');
        $unprivileged = $this->administrator(false);
        $ticket = $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Private search result',
            'Only an authorized Support operator may discover this ticket.',
            'support-routing:reauth',
        ));
        $routing = $this->app->make(SupportTicketRoutingService::class);

        self::assertSame($ticket->id, $routing->search($operator, SupportTicketSearchField::Tracking, $ticket->trackingNumber)[0]->id);

        try {
            $routing->search($unprivileged, SupportTicketSearchField::Tracking, $ticket->trackingNumber);
            self::fail('Unprivileged administrator must not search Support tickets.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        $administratorId = (int) DB::table('administrators')->where('user_id', $operator)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'deny',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->expectException(AuthorizationException::class);
        $routing->search($operator, SupportTicketSearchField::Tracking, $ticket->trackingNumber);
    }

    public function test_assignment_and_transfer_require_current_support_authority_and_do_not_change_ticket_lifecycle(): void
    {
        $customer = $this->user();
        $actor = $this->administrator(false, 'support');
        $firstAssignee = $this->administrator(false, 'support');
        $secondAssignee = $this->administrator(false, 'support');
        $unprivileged = $this->administrator(false);
        $tickets = $this->app->make(SupportTicketService::class);
        $support = $this->app->make(SupportTicketSupportService::class);
        $routing = $this->app->make(SupportTicketRoutingService::class);
        $ticket = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Transfer authority',
            'Assignment must not rewrite lifecycle state.',
            'support-routing:assign',
        ));
        $before = DB::table('support_tickets')->where('id', $ticket->id)->first(['state', 'state_version', 'priority', 'updated_at']);
        self::assertNotNull($before);

        $assigned = $routing->assign($actor, $ticket->id, $firstAssignee);
        self::assertSame($firstAssignee, $assigned->assignedUserId);
        $assignedAt = $assigned->updatedAt;
        $replayed = $routing->assign($actor, $ticket->id, $firstAssignee);
        self::assertSame($firstAssignee, $replayed->assignedUserId);
        self::assertSame($assignedAt, $replayed->updatedAt, 'Idempotent assignment must not rewrite updated_at.');

        $transferred = $routing->assign($actor, $ticket->id, $secondAssignee);
        self::assertSame($secondAssignee, $transferred->assignedUserId);
        $after = DB::table('support_tickets')->where('id', $ticket->id)->first(['state', 'state_version', 'priority']);
        self::assertNotNull($after);
        self::assertSame((string) $before->state, (string) $after->state);
        self::assertSame((int) $before->state_version, (int) $after->state_version);
        self::assertSame((string) $before->priority, (string) $after->priority);
        self::assertSame(1, DB::table('support_ticket_messages')->where('ticket_id', $ticket->id)->count());

        try {
            $routing->assign($actor, $ticket->id, $unprivileged);
            self::fail('Assignment target without current Support authority must be rejected.');
        } catch (AuthorizationException) {
            self::assertSame($secondAssignee, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        }

        DB::table('administrators')->where('user_id', $firstAssignee)->update(['status' => 'inactive', 'updated_at' => now('UTC')]);
        try {
            $routing->assign($actor, $ticket->id, $firstAssignee);
            self::fail('Inactive Support target must be rejected at execution time.');
        } catch (AuthorizationException) {
            self::assertSame($secondAssignee, (int) DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_user_id'));
        }

        $support->transition($actor, $ticket->id, SupportTicketState::Closed, 'support_closed', 'Completed');
        $this->expectException(DomainException::class);
        $routing->assign($actor, $ticket->id, $secondAssignee);
    }

    /** @return array{user_id:int,order_id:int,payment_intent_id:int,service_subscription_id:int} */
    private function referenceBundle(string $suffix): array
    {
        $settlement = $this->createPurchaseOrderSettlement($suffix);
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('order-'.$suffix),
        );
        $queued = $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-'.$suffix),
        );
        $paymentIntentId = DB::table('payment_intents')
            ->where('public_id', $settlement->intentPublicId)
            ->value('id');
        self::assertNotNull($paymentIntentId);

        return [
            'user_id' => $settlement->userId,
            'order_id' => $order->orderId,
            'payment_intent_id' => (int) $paymentIntentId,
            'service_subscription_id' => $queued->serviceSubscriptionId,
        ];
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
