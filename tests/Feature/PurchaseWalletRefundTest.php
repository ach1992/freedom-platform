<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseRefundService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Application\PurchaseWalletRefundService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Application\Clock;
use App\Shared\Domain\Money;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseWalletRefundClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-002 PAY-003 WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseWalletRefundTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseWalletRefundClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new PurchaseWalletRefundClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_wallet_purchase_refund_credits_the_reserved_wallet_append_only_and_replays_exactly_once(): void
    {
        [$userId, $quote, $walletId, $intentPublicId, $settlementPublicId, $fundedAmount] = $this->settledPurchase('refund');
        $refunds = $this->app->make(PurchaseWalletRefundService::class);
        $firstAmount = intdiv($quote->finalPriceIrr, 2);
        $secondAmount = $quote->finalPriceIrr - $firstAmount;
        self::assertGreaterThan(0, $firstAmount);
        self::assertGreaterThan(0, $secondAmount);

        $partialRequestId = 'wallet-refund-partial-000001';
        $first = $refunds->refund(
            $partialRequestId,
            $settlementPublicId,
            $firstAmount,
            $this->correlation('refund-partial'),
        );

        self::assertFalse($first->replayed);
        self::assertSame($firstAmount, $first->amount->amount());
        self::assertSame($firstAmount, $first->cumulativeRefunded->amount());
        self::assertSame(PaymentIntentState::PartiallyRefunded, $first->state);
        self::assertSame('partially_refunded', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(1, DB::table('purchase_refunds')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_purchase_refund')->count());

        $firstLedger = DB::table('ledger_transactions')
            ->where('transaction_type', 'wallet_purchase_refund')
            ->where('source_type', 'purchase_refund')
            ->first();
        self::assertNotNull($firstLedger);
        self::assertNotNull($firstLedger->finalized_at);
        self::assertSame(64, strlen((string) $firstLedger->source_id));
        self::assertNotSame($partialRequestId, $firstLedger->source_id);
        self::assertSame(
            hash('sha256', "wallet\0refund-ledger:".(int) $firstLedger->id),
            $first->providerRefundId,
        );

        $purchaseClearingId = (int) DB::table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::PURCHASE_CLEARING)
            ->value('id');
        self::assertSame(1, DB::table('ledger_entries')
            ->where('ledger_transaction_id', (int) $firstLedger->id)
            ->where('ledger_account_id', $purchaseClearingId)
            ->where('direction', 'debit')
            ->where('amount_irr', $firstAmount)
            ->count());
        self::assertSame(1, DB::table('ledger_entries')
            ->where('ledger_transaction_id', (int) $firstLedger->id)
            ->where('ledger_account_id', $walletId)
            ->where('direction', 'credit')
            ->where('amount_irr', $firstAmount)
            ->count());
        self::assertSame(
            $fundedAmount - $quote->finalPriceIrr + $firstAmount,
            $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount,
        );

        $replay = $refunds->refund(
            $partialRequestId,
            $settlementPublicId,
            $firstAmount,
            $this->correlation('refund-partial-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->refundId, $replay->refundId);
        self::assertSame($first->providerRefundId, $replay->providerRefundId);
        self::assertSame(1, DB::table('purchase_refunds')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_purchase_refund')->count());

        $second = $refunds->refund(
            'wallet-refund-remainder-000001',
            $settlementPublicId,
            $secondAmount,
            $this->correlation('refund-remainder'),
        );
        self::assertFalse($second->replayed);
        self::assertSame($quote->finalPriceIrr, $second->cumulativeRefunded->amount());
        self::assertSame(PaymentIntentState::Refunded, $second->state);
        self::assertSame('refunded', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(2, DB::table('purchase_refunds')->where('provider_code', 'wallet')->count());
        self::assertSame(2, DB::table('ledger_transactions')->where('transaction_type', 'wallet_purchase_refund')->count());
        self::assertSame(
            $fundedAmount,
            $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount,
        );
        self::assertSame('captured', DB::table('wallet_holds')->where('source_id', $intentPublicId)->value('status'));
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_hold_capture')->count());
    }

    public function test_generic_purchase_refund_cannot_forge_wallet_refund_without_exact_ledger_reversal(): void
    {
        [$userId, $quote, $walletId, $intentPublicId, $settlementPublicId, $fundedAmount] = $this->settledPurchase('forged-refund');
        $providerEventId = hash('sha256', 'wallet-purchase-forged-refund-event');
        $providerRefundId = hash('sha256', 'wallet-purchase-forged-refund-transaction');
        $payloadHash = hash('sha256', 'wallet-purchase-forged-refund-payload');
        $occurredAt = $this->clock->value;
        $verified = new VerifiedPaymentEvent(
            $providerEventId,
            $payloadHash,
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Refunded,
                $providerRefundId,
                $providerEventId,
                Money::irr($quote->finalPriceIrr),
                $occurredAt,
                $occurredAt,
                $payloadHash,
                ['payment_intent' => $intentPublicId],
            ),
        );

        $this->assertQueryRejected(fn (): mixed => $this->app->make(PurchaseRefundService::class)->record(
            'wallet-refund-forged-000001',
            $settlementPublicId,
            'wallet',
            $verified,
            $this->correlation('forged-refund'),
        ));

        self::assertSame(0, DB::table('purchase_refunds')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('payment_provider_events')->where('provider_event_id', $providerEventId)->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_purchase_refund')->count());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intentPublicId)->value('state'));
        self::assertSame(
            $fundedAmount - $quote->finalPriceIrr,
            $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount,
        );
    }

    /** @return array{0:int,1:object,2:int,3:string,4:string,5:int} */
    private function settledPurchase(string $suffix): array
    {
        [$userId, $quote, $decision] = $this->purchaseContext($suffix);
        $fundedAmount = $quote->finalPriceIrr + 500_000;
        $walletId = $this->fundedWallet($userId, $fundedAmount, $suffix);
        $payments = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $payments->reserve(
            'purchase.wallet.refund.reserve.'.$suffix.'.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('reserve-'.$suffix),
        );
        DB::statement('SET timestamp = '.$this->clock->value->modify('+1 second')->getTimestamp());
        $order = $payments->capture($intent->intentPublicId, $this->correlation('capture-'.$suffix));
        self::assertNotNull($order->purchaseSettlementPublicId);

        return [
            $userId,
            $quote,
            $walletId,
            $intent->intentPublicId,
            $order->purchaseSettlementPublicId,
            $fundedAmount,
        ];
    }

    /** @return array{0:int,1:object,2:object} */
    private function purchaseContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.wallet.refund.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
            $userId,
            $offering['id'],
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->value->modify('+30 minutes'),
            ),
            $this->correlation('quote-'.$suffix),
        );
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureHealthyWalletMethod($eligibility, $administratorId, $suffix);
        $decision = $eligibility->evaluate(
            'eligibility.purchase.wallet.refund.'.$suffix.'.000001',
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote, $decision];
    }

    private function configureHealthyWalletMethod(PaymentMethodEligibilityService $service, int $administratorId, string $suffix): void
    {
        $service->configureMethod(
            'eligibility.method.wallet.refund.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet purchase refund test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'eligibility.health.wallet.refund.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy wallet purchase refund observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.wallet.purchase.refund.test.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.purchase.refund.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.purchase.refund.fund.'.$suffix.'.000001',
            'wallet_purchase_refund_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'wallet-purchase-refund-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-wallet-refund:'.$suffix);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
