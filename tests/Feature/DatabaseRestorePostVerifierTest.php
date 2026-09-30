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
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
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
        self::assertNull($runtime->releasePath);
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

    public function test_malformed_pre_payment_purchase_shape_is_a_restore_reconciliation_violation(): void
    {
        $this->withTemporaryPurchaseAuthorityTables(function (): void {
            DB::table('quotes')->insert([
                'id' => 1,
                'public_id' => '01JRESTOREQUOTE000000000001',
                'user_id' => 7,
                'configuration_snapshot_hash' => str_repeat('a', 64),
                'final_price_irr' => 1000,
                'currency' => 'IRR',
            ]);
            DB::table('orders')->insert([
                'source_type' => 'purchase',
                'state' => 'awaiting_payment',
                'state_version' => 0,
                'source_quote_id' => 1,
                'source_quote_public_id' => '01JRESTOREQUOTE000000000001',
                'user_id' => 7,
                'source_quote_configuration_hash' => str_repeat('a', 64),
                'total_amount_irr' => 1000,
                'currency' => 'IRR',
                'purchase_settlement_id' => null,
                'purchase_settlement_public_id' => null,
                'payment_intent_id' => 99,
                'payment_intent_public_id' => '01JRESTOREINTENT00000000001',
                'settled_amount_irr' => null,
                'paid_at' => null,
            ]);

            self::assertSame(1, $this->purchaseViolationCount());
        });
    }

    public function test_broken_paid_provider_relationship_is_a_restore_reconciliation_violation(): void
    {
        $this->withTemporaryPurchaseAuthorityTables(function (): void {
            DB::table('quotes')->insert([
                'id' => 1,
                'public_id' => '01JRESTOREQUOTE000000000002',
                'user_id' => 8,
                'configuration_snapshot_hash' => str_repeat('b', 64),
                'final_price_irr' => 2000,
                'currency' => 'IRR',
            ]);
            DB::table('payment_intents')->insert([
                'id' => 10,
                'public_id' => '01JRESTOREINTENT00000000002',
                'purpose' => 'purchase',
                'user_id' => 8,
                'source_quote_id' => 1,
                'source_quote_public_id' => '01JRESTOREQUOTE000000000002',
                'source_quote_configuration_hash' => str_repeat('b', 64),
                'state' => 'captured',
                'captured_at' => '2026-09-29 09:00:00',
                'amount_irr' => 2000,
                'currency' => 'IRR',
            ]);
            DB::table('purchase_settlements')->insert([
                'id' => 11,
                'payment_intent_id' => 10,
                'provider_transaction_row_id' => 12,
                'user_id' => 8,
                'source_quote_id' => 1,
                'source_quote_public_id' => '01JRESTOREQUOTE000000000002',
                'public_id' => '01JRESTORESETTLE000000000002',
                'provider_code' => 'test',
                'provider_transaction_id' => 'provider-2',
                'evidence_payload_hash' => str_repeat('c', 64),
                'amount_irr' => 2000,
                'currency' => 'IRR',
                'settled_at' => '2026-09-29 09:00:00',
            ]);
            DB::table('payment_provider_transactions')->insert([
                'id' => 12,
                'payment_intent_id' => 10,
                'provider_code' => 'test',
                'provider_transaction_id' => 'provider-2',
                'evidence_payload_hash' => str_repeat('d', 64),
                'transaction_status' => 'settled',
                'amount_irr' => 2000,
                'currency' => 'IRR',
                'settled_at' => '2026-09-29 09:00:00',
            ]);
            DB::table('orders')->insert([
                'source_type' => 'purchase',
                'state' => 'paid',
                'state_version' => 1,
                'source_quote_id' => 1,
                'source_quote_public_id' => '01JRESTOREQUOTE000000000002',
                'user_id' => 8,
                'source_quote_configuration_hash' => str_repeat('b', 64),
                'total_amount_irr' => 2000,
                'currency' => 'IRR',
                'purchase_settlement_id' => 11,
                'purchase_settlement_public_id' => '01JRESTORESETTLE000000000002',
                'payment_intent_id' => 10,
                'payment_intent_public_id' => '01JRESTOREINTENT00000000002',
                'settled_amount_irr' => 2000,
                'paid_at' => '2026-09-29 09:00:00',
            ]);

            self::assertSame(1, $this->purchaseViolationCount());
        });
    }

    private function purchaseViolationCount(): int
    {
        $method = new ReflectionMethod(DatabaseRestorePostVerifier::class, 'orderPaymentViolations');

        return $method->invoke(
            $this->verifier(new RecordingRestoreRuntimeHealthVerifier),
            DB::connection(),
        );
    }

    private function withTemporaryPurchaseAuthorityTables(callable $callback): void
    {
        $definitions = [
            'orders' => <<<'SQL'
CREATE TEMPORARY TABLE orders (
    source_type VARCHAR(32), state VARCHAR(64), state_version INT,
    source_quote_id BIGINT, source_quote_public_id VARCHAR(32), user_id BIGINT,
    source_quote_configuration_hash VARCHAR(64), total_amount_irr BIGINT, currency VARCHAR(3),
    purchase_settlement_id BIGINT NULL, purchase_settlement_public_id VARCHAR(32) NULL,
    payment_intent_id BIGINT NULL, payment_intent_public_id VARCHAR(32) NULL,
    settled_amount_irr BIGINT NULL, paid_at DATETIME NULL
)
SQL,
            'quotes' => <<<'SQL'
CREATE TEMPORARY TABLE quotes (
    id BIGINT, public_id VARCHAR(32), user_id BIGINT, configuration_snapshot_hash VARCHAR(64),
    final_price_irr BIGINT, currency VARCHAR(3)
)
SQL,
            'purchase_settlements' => <<<'SQL'
CREATE TEMPORARY TABLE purchase_settlements (
    id BIGINT, payment_intent_id BIGINT, provider_transaction_row_id BIGINT,
    user_id BIGINT, source_quote_id BIGINT, source_quote_public_id VARCHAR(32),
    public_id VARCHAR(32), provider_code VARCHAR(64), provider_transaction_id VARCHAR(191),
    evidence_payload_hash VARCHAR(64), amount_irr BIGINT, currency VARCHAR(3), settled_at DATETIME
)
SQL,
            'payment_intents' => <<<'SQL'
CREATE TEMPORARY TABLE payment_intents (
    id BIGINT, public_id VARCHAR(32), purpose VARCHAR(32), user_id BIGINT,
    source_quote_id BIGINT, source_quote_public_id VARCHAR(32),
    source_quote_configuration_hash VARCHAR(64), state VARCHAR(64), captured_at DATETIME NULL,
    amount_irr BIGINT, currency VARCHAR(3)
)
SQL,
            'payment_provider_transactions' => <<<'SQL'
CREATE TEMPORARY TABLE payment_provider_transactions (
    id BIGINT, payment_intent_id BIGINT, provider_code VARCHAR(64),
    provider_transaction_id VARCHAR(191), evidence_payload_hash VARCHAR(64),
    transaction_status VARCHAR(32), amount_irr BIGINT, currency VARCHAR(3), settled_at DATETIME NULL
)
SQL,
        ];

        foreach ($definitions as $sql) {
            DB::statement($sql);
        }

        try {
            $callback();
        } finally {
            foreach (array_reverse(array_keys($definitions)) as $table) {
                DB::statement('DROP TEMPORARY TABLE IF EXISTS '.$table);
            }
        }
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

    public ?string $releasePath = null;

    public function verify(string $expectedAuthorityFingerprint, ?string $releasePath = null): void
    {
        $this->expectedAuthorityFingerprint = $expectedAuthorityFingerprint;
        $this->releasePath = $releasePath;
    }
}
