<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Provisioning\Application\InitialProvisioningQueueService;
use App\Modules\Reporting\Application\DatabaseReportingSnapshotService;
use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportMetric;
use App\Modules\Reporting\Application\ReportSnapshot;
use App\Modules\Support\Application\SupportTicketCreateRequest;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletCorrectionService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\ReportingAccessFoundationSeeder;
use Database\Seeders\SupportTicketCategorySeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 DAT-002 DAT-003 SEC-002 */
final class ReportingSnapshotIntegrationTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use PurchaseOrderTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(ReportingAccessFoundationSeeder::class);
        $this->seed(SupportTicketCategorySeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function test_owner_report_reconciles_captured_sales_refund_adjustment_and_wallet_liability(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('reporting');
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('create-reporting'),
        );

        $refundAmount = intdiv($settlement->amount->amount(), 4);
        $this->app->make(PurchaseRefundService::class)->record(
            'purchase.refund.reporting.000001',
            $settlement->settlementPublicId,
            'order_gateway_reporting',
            new VerifiedPaymentEvent(
                'purchase-refund-event-reporting',
                hash('sha256', 'purchase-refund-event-payload:reporting'),
                new PaymentEvidence(
                    ProviderOperationOutcome::Success,
                    PaymentEvidenceAuthority::Authoritative,
                    PaymentTransactionStatus::Refunded,
                    'purchase-refund-transaction-reporting',
                    'purchase-refund-event-reporting',
                    Money::irr($refundAmount),
                    $this->purchaseOrderClock->value,
                    null,
                    hash('sha256', 'purchase-refund-evidence:reporting'),
                    ['provider_reference' => 'purchase-refund-transaction-reporting'],
                ),
            ),
            $this->purchaseOrderCorrelation('refund-reporting'),
        );

        $walletId = $this->walletAccount($settlement->userId, 'cash', 'reporting');
        $this->fundWallet($walletId, 100_000, 'reporting');
        $ownerAdministratorId = (int) DB::table('administrators')->where('is_owner', true)->value('id');
        $preview = $this->app->make(WalletCorrectionService::class)->preview(
            'correction.reporting.000001',
            $settlement->userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(25_000),
            'Correct reporting fixture balance.',
            $this->accessContext($ownerAdministratorId, 'reporting-correction-preview'),
            'order',
            (string) DB::table('orders')->value('public_id'),
        );
        $this->app->make(WalletCorrectionService::class)->execute(
            $preview->previewId,
            $preview->confirmationToken,
            null,
            $this->accessContext($ownerAdministratorId, 'reporting-correction-execute'),
        );

        $this->app->make(InitialProvisioningQueueService::class)->queueInitial(
            $order->orderPublicId,
            $this->purchaseOrderCorrelation('queue-reporting'),
        );

        $ownerUserId = (int) DB::table('administrators')->where('id', $ownerAdministratorId)->value('user_id');
        $range = new ReportDateRange(
            'integration',
            $this->purchaseOrderClock->value->modify('-1 hour'),
            $this->purchaseOrderClock->value->modify('+1 hour'),
        );
        $snapshot = $this->app->make(DatabaseReportingSnapshotService::class)->generate(
            $ownerUserId,
            $range,
            'reporting-integration-correlation',
            'reporting-integration-request',
        );

        self::assertSame($settlement->amount->amount(), $this->metric($snapshot, 'sales.captured_irr'));
        self::assertSame(0, $this->metric($snapshot, 'sales.discount_irr'));
        self::assertSame($settlement->amount->amount(), $this->metric($snapshot, 'sales.gross_irr'));
        self::assertSame($refundAmount, $this->metric($snapshot, 'sales.refund_irr'));
        self::assertSame($settlement->amount->amount() - $refundAmount, $this->metric($snapshot, 'sales.net_irr'));
        self::assertSame(25_000, $this->metric($snapshot, 'wallet.exact_adjustment_irr'));
        self::assertSame(125_000, $this->metric($snapshot, 'wallet.liability_irr'));
        self::assertSame(1, $this->metric($snapshot, 'gateways.captured_count', 'order_gateway_reporting'));
        self::assertSame(1, $this->metric($snapshot, 'services.created'));
        self::assertSame(1, $this->metric($snapshot, 'services.current_inventory_by_lifecycle_state', 'active'));
        self::assertSame(1, DB::table('audit_logs')->where('action', 'report.view')->count());
        self::assertSame(64, strlen((string) DB::table('audit_logs')->where('action', 'report.view')->value('request_fingerprint')));
    }

    public function test_owner_report_keeps_representative_domain_dimensions_and_zero_states_visible(): void
    {
        $ownerAdministratorId = $this->ownerAdministrator();
        $ownerUserId = (int) DB::table('administrators')->where('id', $ownerAdministratorId)->value('user_id');
        $agentUserId = $this->agentSubject('reporting-dimension-agent');
        $customerUserId = $this->quoteUser('customer');

        $this->app->make(SupportTicketService::class)->create(new SupportTicketCreateRequest(
            $customerUserId,
            'other',
            'Reporting dimension ticket',
            'Ticket fixture for reporting dimension acceptance.',
            'reporting-dimension-ticket',
        ));

        DB::table('panel_connections')->insert([
            'code' => 'reporting-dimension-panel',
            'provider_type' => 'fake',
            'name_fa' => 'Reporting Fixture',
            'name_en' => 'Reporting Fixture',
            'base_url' => 'https://reporting-fixture.example.test',
            'encrypted_credentials' => 'fixture-ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'disabled',
            'last_test_status' => null,
            'last_panel_version' => null,
            'last_capabilities_hash' => null,
            'last_tested_at' => null,
            'version' => 1,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $snapshot = $this->app->make(DatabaseReportingSnapshotService::class)->generate(
            $ownerUserId,
            new ReportDateRange(
                'domain-dimensions',
                new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                new \DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            ),
            'reporting-domain-dimensions',
            'reporting-domain-dimensions-request',
        );

        self::assertGreaterThan(0, $agentUserId);
        self::assertSame(1, $this->metric($snapshot, 'agents.approved'));
        self::assertSame(1, $this->metric($snapshot, 'tickets.created'));
        self::assertSame(1, $this->metric($snapshot, 'panels.current_inventory', 'fake:disabled'));

        // These always-present metrics must remain explicit zeroes rather than disappear silently.
        self::assertSame(0, $this->metric($snapshot, 'services.created'));
        self::assertSame(0, $this->metric($snapshot, 'referrals.accrued_count'));
        self::assertSame(0, $this->metric($snapshot, 'referrals.accrued_irr'));
        self::assertSame(0, $this->metric($snapshot, 'broadcasts.campaigns_created'));
        self::assertSame(0, $this->metric($snapshot, 'broadcasts.recipients_sent'));
        self::assertSame(0, $this->metric($snapshot, 'failures.failed_jobs'));
        self::assertSame(0, $this->metric($snapshot, 'outbox.pending_with_error'));
    }

    public function test_non_owner_without_permission_is_denied_and_explicit_override_allows_view(): void
    {
        $userId = $this->quoteUser('customer');
        $now = $this->purchaseOrderTimestamp();
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $range = new ReportDateRange(
            'permission-test',
            $this->purchaseOrderClock->value->modify('-1 hour'),
            $this->purchaseOrderClock->value->modify('+1 hour'),
        );
        $service = $this->app->make(DatabaseReportingSnapshotService::class);

        try {
            $service->generate($userId, $range, 'report-denied-correlation', 'report-denied-request');
            self::fail('Expected reporting authorization to fail closed.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('audit_logs')->where('action', 'report.view')->count());
        }

        $permissionId = (int) DB::table('permissions')->where('code', 'reports.view')->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $permissionId,
            'effect' => 'allow',
            'changed_by_administrator_id' => null,
            'reason_code' => 'reporting_integration_test',
            'reason' => 'Explicit report-view grant for test.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $snapshot = $service->generate($userId, $range, 'report-allowed-correlation', 'report-allowed-request');
        self::assertInstanceOf(ReportSnapshot::class, $snapshot);
        self::assertSame([], $snapshot->metrics, 'reports.view alone must not widen domain visibility.');

        $identityPermissionId = (int) DB::table('permissions')->where('code', 'identity.customers.view')->value('id');
        DB::table('administrator_permission_overrides')->insert([
            'administrator_id' => $administratorId,
            'permission_id' => $identityPermissionId,
            'effect' => 'allow',
            'changed_by_administrator_id' => null,
            'reason_code' => 'reporting_domain_visibility_test',
            'reason' => 'Grant customer visibility only for report-domain intersection test.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $identitySnapshot = $service->generate(
            $userId,
            $range,
            'report-identity-allowed-correlation',
            'report-identity-allowed-request',
        );
        self::assertNotEmpty($identitySnapshot->metrics);
        foreach ($identitySnapshot->metrics as $metric) {
            self::assertStringStartsWith('users.', $metric->code);
        }
        self::assertSame(2, DB::table('audit_logs')->where('action', 'report.view')->count());
    }

    private function metric(ReportSnapshot $snapshot, string $code, ?string $dimension = null): int
    {
        foreach ($snapshot->metrics as $metric) {
            if ($metric instanceof ReportMetric && $metric->code === $code && $metric->dimension === $dimension) {
                return $metric->value;
            }
        }

        self::fail('Expected report metric was not found: '.$code.' / '.($dimension ?? '-'));
    }

    private function walletAccount(int $userId, string $bucket, string $suffix): int
    {
        $now = $this->purchaseOrderTimestamp();

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.'.$bucket.'.reporting.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function systemAccount(string $code, string $class): int
    {
        $now = $this->purchaseOrderTimestamp();

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function fundWallet(int $walletId, int $amount, string $suffix): void
    {
        $offsetId = $this->systemAccount('system.wallet.reporting.funding.'.$suffix, 'equity');
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.reporting.funding.'.$suffix,
            'reporting_test_funding',
            'corr-reporting-funding-'.$suffix,
            [
                new LedgerEntryDraft($offsetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'test_fixture',
            $suffix,
        );
    }

    private function accessContext(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'reporting-test-request:'.$suffix),
            substr(hash('sha256', 'reporting-test-correlation:'.$suffix), 0, 64),
            'reporting_test',
            'Reporting integration test reason.',
            $administratorId,
        );
    }
}
