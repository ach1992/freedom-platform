<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Customers\Application\Contracts\CustomerTierPurchaseMetricsSource;
use App\Modules\Customers\Application\CustomerTierAutomaticRecalculationService;
use App\Modules\Customers\Application\CustomerTierMaintenanceService;
use App\Modules\Customers\Application\CustomerTierPurchaseMetrics;
use App\Modules\Customers\Application\CustomerTierService;
use App\Modules\Customers\Application\PurchaseSettlementTierOutboxHandler;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxMessage;
use Database\Seeders\IdentityAccessFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerTierRuntimeWiringClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class CustomerTierRuntimeMetricsSource implements CustomerTierPurchaseMetricsSource
{
    /** @var array<int, CustomerTierPurchaseMetrics> */
    public array $metrics = [];

    public function metricsFor(int $userId): CustomerTierPurchaseMetrics
    {
        return $this->metrics[$userId] ?? new CustomerTierPurchaseMetrics(0, 0);
    }
}

/** @requirement USR-002 DAT-002 DAT-003 QUA-001 QUA-004 */
final class CustomerTierRuntimeWiringTest extends TestCase
{
    use RefreshDatabase;

    private CustomerTierRuntimeWiringClock $clock;

    private CustomerTierRuntimeMetricsSource $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);

        $this->clock = new CustomerTierRuntimeWiringClock(
            new DateTimeImmutable('2026-09-26T00:15:00+00:00'),
        );
        $this->metrics = new CustomerTierRuntimeMetricsSource;
        $this->app->instance(Clock::class, $this->clock);
        $this->app->instance(CustomerTierPurchaseMetricsSource::class, $this->metrics);

        foreach ([
            CustomerTierService::class,
            CustomerTierAutomaticRecalculationService::class,
            PurchaseSettlementTierOutboxHandler::class,
            CustomerTierMaintenanceService::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }
    }

    public function test_purchase_outbox_replay_and_daily_maintenance_preserve_lock_and_no_downgrade_policy(): void
    {
        $promotedUserId = $this->customer('new', false, '-120 days');
        $this->metrics->metrics[$promotedUserId] = new CustomerTierPurchaseMetrics(10, 4_000_000);

        $settlementPublicId = (string) Str::ulid();
        $message = new OutboxMessage(
            (string) Str::uuid(),
            'purchase.customer_tier.recalculate:'.$settlementPublicId,
            'purchase.customer_tier.recalculation_requested',
            'purchase_settlement',
            $settlementPublicId,
            [
                'purchase_settlement_public_id' => $settlementPublicId,
                'user_id' => $promotedUserId,
            ],
            'customer-tier-runtime-settlement-0001',
            1,
            1,
        );

        $handler = $this->app->make(PurchaseSettlementTierOutboxHandler::class);
        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame('vip', $this->tierCode($promotedUserId));
        self::assertSame(1, DB::table('customer_tier_histories')->where('user_id', $promotedUserId)->count());

        self::assertSame(OutboxDispatchOutcome::Success, $handler->handle($message));
        self::assertSame(1, DB::table('customer_tier_histories')->where('user_id', $promotedUserId)->count());
        self::assertSame(1, DB::table('audit_logs')
            ->where('target_type', 'user')
            ->where('target_id', (string) $promotedUserId)
            ->where('action', 'customer.tier.recalculate')
            ->count());

        $lockedUserId = $this->customer('loyal', true, '-120 days');
        $vipUserId = $this->customer('vip', false, '-120 days');
        $maturingUserId = $this->customer('new', false, '-40 days');
        $this->metrics->metrics[$lockedUserId] = new CustomerTierPurchaseMetrics(20, 10_000_000);
        $this->metrics->metrics[$vipUserId] = new CustomerTierPurchaseMetrics(0, 0);
        $this->metrics->metrics[$maturingUserId] = new CustomerTierPurchaseMetrics(3, 1_000_000);

        $result = $this->app->make(CustomerTierMaintenanceService::class)->process(2);

        self::assertSame(4, $result['examined']);
        self::assertSame(1, $result['changed']);
        self::assertSame('loyal', $this->tierCode($lockedUserId));
        self::assertSame('vip', $this->tierCode($vipUserId));
        self::assertSame('loyal', $this->tierCode($maturingUserId));
        self::assertSame(0, DB::table('customer_tier_histories')->where('user_id', $lockedUserId)->count());
        self::assertSame(0, DB::table('customer_tier_histories')->where('user_id', $vipUserId)->count());
        self::assertSame(1, DB::table('customer_tier_histories')->where('user_id', $maturingUserId)->count());
    }

    private function customer(string $tierCode, bool $locked, string $firstSeenModifier): int
    {
        $now = $this->clock->now();
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now->modify($firstSeenModifier)->format('Y-m-d H:i:s.u'),
            'last_seen_at' => $now->format('Y-m-d H:i:s.u'),
            'created_at' => $now->modify($firstSeenModifier)->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);
        $tierId = (int) DB::table('customer_tiers')->where('code', $tierCode)->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => $locked,
            'tier_lock_reason_code' => $locked ? 'manual_override' : null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'updated_at' => $now->format('Y-m-d H:i:s.u'),
        ]);

        return $userId;
    }

    private function tierCode(int $userId): string
    {
        return (string) DB::table('customer_profiles as profile')
            ->join('customer_tiers as tier', 'tier.id', '=', 'profile.current_tier_id')
            ->where('profile.user_id', $userId)
            ->value('tier.code');
    }
}
