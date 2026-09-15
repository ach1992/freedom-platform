<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\NonPaidOrderService;
use App\Modules\Orders\Application\OrderSourceAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement BUY-001 BUY-002 ADM-002 DAT-003 DAT-004 SEC-002 QUA-004 */
final class NonPaidOrderAuditFingerprintTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_distinct_admin_authorizations_with_same_payload_materialize_distinct_audits(): void
    {
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $offering = $this->activeBenefitOffering('non-paid-audit-fingerprint');
        $authorizations = $this->app->make(OrderSourceAuthorizationService::class);

        $first = $authorizations->authorizeAdministratorGrant(
            'audit-fingerprint-admin-grant-01',
            $ownerId,
            $userId,
            $offering['id'],
            'service_import',
            'audit-fingerprint-auth-correlation-01',
        );
        $second = $authorizations->authorizeAdministratorGrant(
            'audit-fingerprint-admin-grant-02',
            $ownerId,
            $userId,
            $offering['id'],
            'service_import',
            'audit-fingerprint-auth-correlation-02',
        );

        self::assertNotSame($first->authorizationId, $second->authorizationId);
        $payloadHashes = DB::table('order_source_authorizations')
            ->whereIn('id', [$first->authorizationId, $second->authorizationId])
            ->pluck('request_payload_hash');
        self::assertCount(2, $payloadHashes);
        self::assertSame(1, $payloadHashes->unique()->count());

        $orders = $this->app->make(NonPaidOrderService::class);
        $firstOrder = $orders->materialize($first->publicId, 'audit-fingerprint-order-correlation-01');
        $secondOrder = $orders->materialize($second->publicId, 'audit-fingerprint-order-correlation-02');

        self::assertNotSame($firstOrder->orderId, $secondOrder->orderId);
        self::assertSame(2, DB::table('orders')
            ->whereIn('order_source_authorization_id', [$first->authorizationId, $second->authorizationId])
            ->count());

        $audits = DB::table('audit_logs')
            ->where('action', 'order.non_paid.authorized')
            ->whereIn('target_id', [$firstOrder->orderPublicId, $secondOrder->orderPublicId])
            ->get(['request_fingerprint']);
        self::assertCount(2, $audits);
        self::assertSame(2, $audits->pluck('request_fingerprint')->unique()->count());
    }
}
