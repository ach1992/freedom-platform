<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/AgentPricingQuoteIntegrationTestSupport.php';
require_once __DIR__.'/PurchaseOrderTestSupport.php';

use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use App\Modules\Operations\Application\Contracts\RestoreRuntimeHealthVerifier;
use App\Modules\Operations\Infrastructure\DatabaseRestorePostVerifier;
use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @requirement BAK-002 OPS-001 SEC-001 QUA-001 */
final class DatabaseRestorePostVerifierTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use PurchaseOrderTestSupport;
    use RefreshDatabase {
        refreshDatabase as private refreshMariaDbDatabase;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->bootPurchaseOrderClock();
    }

    public function refreshDatabase(): void
    {
        $connection = config('database.default');
        $driver = is_string($connection) ? config('database.connections.'.$connection.'.driver') : null;

        if ($driver !== 'mysql') {
            self::markTestSkipped('Restore post-verification requires the MariaDB integration runtime.');
        }

        $this->refreshMariaDbDatabase();
    }

    public function test_current_empty_mariadb_schema_passes_restore_integrity_and_attests_exact_authority_identity(): void
    {
        $runtime = new RecordingRestoreRuntimeHealthVerifier;
        $result = $this->verifier($runtime)->verify();

        self::assertGreaterThan(0, $result['schema_migrations']);
        self::assertSame(0, $result['ledger_violations']);
        self::assertSame(0, $result['order_payment_violations']);
        self::assertSame(0, $result['service_violations']);
        self::assertTrue($result['runtime_health']);
        self::assertSame(str_repeat('a', 64), $runtime->expectedAuthorityFingerprint);
    }

    public function test_valid_awaiting_payment_purchase_order_is_not_a_restore_reconciliation_violation(): void
    {
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'restore.verifier.prepayment.quote',
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->purchaseOrderClock->value->modify('+30 minutes'),
            ),
            $this->purchaseOrderCorrelation('restore-verifier-prepayment-quote'),
        );

        $opened = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->purchaseOrderCorrelation('restore-verifier-prepayment-order'),
        );

        self::assertSame('awaiting_payment', $opened->state->value);
        self::assertSame(0, $opened->stateVersion);

        $result = $this->verifier(new RecordingRestoreRuntimeHealthVerifier)->verify();

        self::assertSame(0, $result['order_payment_violations']);
    }

    public function test_valid_captured_purchase_authority_is_not_a_restore_reconciliation_violation(): void
    {
        $settlement = $this->createPurchaseOrderSettlement('restore-verifier-paid');
        $order = $this->app->make(PurchaseOrderService::class)->createFromSettlement(
            $settlement->settlementPublicId,
            $this->purchaseOrderCorrelation('restore-verifier-paid-order'),
        );

        self::assertSame('paid', $order->state->value);
        self::assertSame(1, $order->stateVersion);

        $result = $this->verifier(new RecordingRestoreRuntimeHealthVerifier)->verify();

        self::assertSame(0, $result['order_payment_violations']);
    }

    private function verifier(
        RecordingRestoreRuntimeHealthVerifier $runtime,
    ): DatabaseRestorePostVerifier {
        return new DatabaseRestorePostVerifier(
            $this->app->make(DatabaseManager::class),
            new FixedRestoreCriticalAuthorityIdentity(str_repeat('a', 64)),
            $runtime,
            database_path('migrations'),
        );
    }
}

final readonly class FixedRestoreCriticalAuthorityIdentity implements RestoreCriticalAuthorityIdentity
{
    public function __construct(private string $fingerprint) {}

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }
}

final class RecordingRestoreRuntimeHealthVerifier implements RestoreRuntimeHealthVerifier
{
    public ?string $expectedAuthorityFingerprint = null;

    public function verify(string $expectedAuthorityFingerprint): void
    {
        $this->expectedAuthorityFingerprint = $expectedAuthorityFingerprint;
    }
}
