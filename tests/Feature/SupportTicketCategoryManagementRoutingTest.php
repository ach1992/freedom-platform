<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\Application\SupportTicketCategoryManagementService;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketRoutingService;
use App\Modules\Support\Application\SupportTicketSearchField;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Application\SupportTicketSnapshot;
use App\Modules\Support\Application\SupportTicketSupportService;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\SupportTicketAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement SUP-001 SUP-002 ACL-001 ACL-002 SEC-002 DAT-003 CNT-001 QUA-004 QUA-008 */
final class SupportTicketCategoryManagementRoutingTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(SupportTicketAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
    }

    public function test_authorized_operator_edits_existing_categories_idempotently_and_seed_reruns_preserve_changes(): void
    {
        $operator = $this->administrator(false, ['support']);
        $unprivileged = $this->administrator(false, ['technical']);
        $categories = $this->app->make(SupportTicketCategoryManagementService::class);

        self::assertCount(6, $categories->categories($operator));

        $namedFa = $categories->setName($operator, 'technical_service', 'fa', 'پشتیبانی فنی ویژه');
        self::assertSame('technical_service', $namedFa->code);
        self::assertSame('پشتیبانی فنی ویژه', $namedFa->nameFa);
        $namedEn = $categories->setName($operator, 'technical_service', 'en', 'Priority technical support');
        $sorted = $categories->setSortOrder($operator, 'technical_service', 5);
        $routed = $categories->setRouteRole($operator, 'technical_service', 'technical');
        $disabled = $categories->setActive($operator, 'technical_service', false);

        self::assertSame('Priority technical support', $namedEn->nameEn);
        self::assertSame(5, $sorted->sortOrder);
        self::assertSame('technical', $routed->routeRoleCode);
        self::assertFalse($disabled->isActive);

        $beforeReplay = $disabled->updatedAt;
        $replayed = $categories->setActive($operator, 'technical_service', false);
        self::assertSame($beforeReplay, $replayed->updatedAt, 'No-op category mutation must not rewrite updated_at.');

        $this->seed(SupportTicketCategorySeeder::class);
        $preserved = $categories->category($operator, 'technical_service');
        self::assertSame('پشتیبانی فنی ویژه', $preserved->nameFa);
        self::assertSame('Priority technical support', $preserved->nameEn);
        self::assertSame(5, $preserved->sortOrder);
        self::assertSame('technical', $preserved->routeRoleCode);
        self::assertFalse($preserved->isActive);

        try {
            $categories->categories($unprivileged);
            self::fail('Category management must require current Support authority.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        DB::table('roles')->where('code', 'sales_content')->update(['is_active' => false, 'updated_at' => now('UTC')]);
        try {
            $categories->setRouteRole($operator, 'technical_service', 'sales_content');
            self::fail('Inactive route role must be rejected.');
        } catch (RuntimeException) {
            self::assertSame('technical', $categories->category($operator, 'technical_service')->routeRoleCode);
        }
    }

    public function test_disabled_category_disappears_from_customer_discovery_but_existing_ticket_remains_durable(): void
    {
        $operator = $this->administrator(false, ['support']);
        $customer = $this->user('customer');
        $categories = $this->app->make(SupportTicketCategoryManagementService::class);
        $tickets = $this->app->make(SupportTicketService::class);
        $existing = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'technical_service',
            'Existing technical ticket',
            'This ticket must survive later category disablement.',
            'category-management:existing-ticket',
        ));

        $categories->setSortOrder($operator, 'technical_service', 1);
        $categories->setName($operator, 'technical_service', 'en', 'Technical queue renamed');
        $activeBeforeDisable = $tickets->activeCategories();
        self::assertSame('technical_service', $activeBeforeDisable[0]->code);
        self::assertSame('Technical queue renamed', $activeBeforeDisable[0]->nameEn);
        self::assertFalse(property_exists($activeBeforeDisable[0], 'routeRoleCode'));
        self::assertFalse(property_exists($activeBeforeDisable[0], 'isActive'));

        $categories->setActive($operator, 'technical_service', false);
        self::assertNotContains(
            'technical_service',
            array_map(static fn ($category): string => $category->code, $tickets->activeCategories()),
        );
        self::assertSame($existing->id, $tickets->ticketForCustomer($existing->id, $customer)->ticket->id);

        try {
            $tickets->create(new SupportTicketCreateRequest(
                $customer,
                'technical_service',
                'Rejected disabled category',
                'New tickets must not enter a disabled category.',
                'category-management:disabled-create',
            ));
            self::fail('Disabled category must reject new ticket creation.');
        } catch (DomainException) {
            self::assertSame(1, DB::table('support_tickets')->where('category_id', $existing->categoryId)->count());
        }
    }

    public function test_queue_routing_is_allocation_only_and_preserves_owner_search_detail_and_assignment_authorities(): void
    {
        $manager = $this->administrator(false, ['support']);
        $supportOnly = $this->administrator(false, ['support']);
        $technicalOnly = $this->administrator(false, ['technical']);
        $technicalWithOverride = $this->administrator(false, ['technical']);
        $owner = $this->administrator(true);
        $this->allowSupportPermission($technicalWithOverride);
        $customer = $this->user('customer');

        $categories = $this->app->make(SupportTicketCategoryManagementService::class);
        $categories->setRouteRole($manager, 'technical_service', 'technical');
        $categories->setRouteRole($manager, 'purchase_payment', 'sales_content');

        $tickets = $this->app->make(SupportTicketService::class);
        $unrouted = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'other',
            'Unrouted queue ticket',
            'Every authorized support operator may see unrouted work.',
            'category-routing:unrouted',
        ));
        $technical = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'technical_service',
            'Technical routed ticket',
            'Only matching route roles should see this in normal queue discovery.',
            'category-routing:technical',
        ));
        $sales = $tickets->create(new SupportTicketCreateRequest(
            $customer,
            'purchase_payment',
            'Sales routed ticket',
            'Owner sees every routed queue.',
            'category-routing:sales',
        ));

        $support = $this->app->make(SupportTicketSupportService::class);
        $routing = $this->app->make(SupportTicketRoutingService::class);

        self::assertSame([$unrouted->id], $this->ticketIds($support->queue($supportOnly)));

        try {
            $support->queue($technicalOnly);
            self::fail('Route membership alone must never grant Support authority.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }

        self::assertSame(
            [$unrouted->id, $technical->id],
            $this->ticketIds($support->queue($technicalWithOverride)),
        );
        self::assertSame(
            [$unrouted->id, $technical->id, $sales->id],
            $this->ticketIds($support->queue($owner)),
        );

        self::assertSame($technical->id, $support->detail($supportOnly, $technical->id)->ticket->id);
        self::assertSame(
            $technical->id,
            $routing->search($supportOnly, SupportTicketSearchField::Tracking, $technical->trackingNumber)[0]->id,
        );

        $assigned = $routing->assign($manager, $technical->id, $supportOnly);
        self::assertSame($supportOnly, $assigned->assignedUserId);
        self::assertSame(
            [$unrouted->id, $technical->id],
            $this->ticketIds($support->queue($supportOnly)),
            'Explicit assignment must keep routed work discoverable for the assignee.',
        );
    }

    /** @param list<SupportTicketSnapshot> $tickets */
    private function ticketIds(array $tickets): array
    {
        return array_map(static fn ($ticket): int => $ticket->id, $tickets);
    }

    private function user(string $accountType = 'administrator'): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param list<string> $roles */
    private function administrator(bool $owner, array $roles = []): int
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
        foreach ($roles as $roleCode) {
            DB::table('administrator_role_assignments')->insert([
                'administrator_id' => $administratorId,
                'role_id' => (int) DB::table('roles')->where('code', $roleCode)->value('id'),
                'granted_by_administrator_id' => null,
                'granted_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $userId;
    }

    private function allowSupportPermission(int $userId): void
    {
        $administratorId = (int) DB::table('administrators')->where('user_id', $userId)->value('id');
        $permissionId = (int) DB::table('permissions')->where('code', SupportTicketSupportService::PERMISSION)->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'allow',
            'changed_by_administrator_id' => null,
            'reason_code' => null,
            'reason' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
