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
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
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

final class PurchaseWalletRefundBindingClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-002 PAY-003 WAL-002 WAL-004 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseWalletRefundBindingTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseWalletRefundBindingClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new PurchaseWalletRefundBindingClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_real_reversal_bound_to_one_purchase_cannot_authorize_another_same_wallet_purchase(): void
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $eligibility = $this->app->make(PaymentMethodEligibilityService::class);
        $this->configureWallet($eligibility, $administratorId);
        $quoteA = $this->quote($userId, $offering['id'], 'a');
        $quoteB = $this->quote($userId, $offering['id'], 'b');
        self::assertSame($quoteA->finalPriceIrr, $quoteB->finalPriceIrr);
        $decisionA = $eligibility->evaluate('eligibility.purchase.wallet.binding.a.000001', $userId, $quoteA->quotePublicId);
        $decisionB = $eligibility->evaluate('eligibility.purchase.wallet.binding.b.000001', $userId, $quoteB->quotePublicId);
        $walletId = $this->fundedWallet($userId, ($quoteA->finalPriceIrr * 2) + 500_000);
        $payments = $this->app->make(PurchaseWalletPaymentService::class);

        $intentA = $payments->reserve(
            'purchase.wallet.binding.a.000001',
            $userId,
            $walletId,
            $quoteA->quotePublicId,
            $decisionA->publicId,
            $this->correlation('reserve-a'),
        );
        $orderA = $payments->capture($intentA->intentPublicId, $this->correlation('capture-a'));
        self::assertNotNull($orderA->purchaseSettlementPublicId);

        $intentB = $payments->reserve(
            'purchase.wallet.binding.b.000001',
            $userId,
            $walletId,
            $quoteB->quotePublicId,
            $decisionB->publicId,
            $this->correlation('reserve-b'),
        );
        $orderB = $payments->capture($intentB->intentPublicId, $this->correlation('capture-b'));
        self::assertNotNull($orderB->purchaseSettlementPublicId);

        $a = $this->authority($orderA->purchaseSettlementPublicId);
        $b = $this->authority($orderB->purchaseSettlementPublicId);
        $refundKey = 'purchase.wallet.binding.refund.000001';
        $sourceForA = $this->ledgerSourceId(
            $refundKey,
            (int) $a->settlement_id,
            (int) $a->intent_id,
            (int) $a->hold_id,
            (int) $a->capture_ledger_id,
        );
        $clearingId = (int) DB::table('ledger_accounts')->where('code', WalletSystemAccountCode::PURCHASE_CLEARING)->value('id');
        $ledger = $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.binding.reversal.000001',
            'wallet_purchase_refund',
            $this->correlation('reversal'),
            [
                new LedgerEntryDraft($clearingId, LedgerDirection::Debit, IrrMoney::positive($quoteA->finalPriceIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($quoteA->finalPriceIrr)),
            ],
            'purchase_refund',
            $sourceForA,
        );
        $finalizedAt = (string) DB::table('ledger_transactions')->where('id', $ledger->transactionId)->value('finalized_at');
        $occurredAt = new DateTimeImmutable($finalizedAt);
        $providerRefundId = hash('sha256', "wallet\0refund-ledger:".$ledger->transactionId);
        $providerEventId = hash('sha256', 'wallet-binding-event');
        $payloadHash = hash('sha256', 'wallet-binding-payload');
        $event = new VerifiedPaymentEvent(
            $providerEventId,
            $payloadHash,
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Refunded,
                $providerRefundId,
                $providerEventId,
                Money::irr($quoteB->finalPriceIrr),
                $occurredAt,
                $occurredAt,
                $payloadHash,
                ['payment_intent' => $intentB->intentPublicId],
            ),
        );

        $this->assertQueryRejected(fn (): mixed => $this->app->make(PurchaseRefundService::class)->record(
            $refundKey,
            $orderB->purchaseSettlementPublicId,
            'wallet',
            $event,
            $this->correlation('record-b'),
        ));

        self::assertSame(0, DB::table('purchase_refunds')->where('payment_intent_id', (int) $b->intent_id)->count());
        self::assertSame(0, DB::table('payment_provider_events')->where('provider_event_id', $providerEventId)->count());
        self::assertSame('captured', DB::table('payment_intents')->where('id', (int) $b->intent_id)->value('state'));
    }

    private function configureWallet(PaymentMethodEligibilityService $service, int $administratorId): void
    {
        $service->configureMethod(
            'eligibility.method.wallet.binding.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet refund binding test configuration.',
            $this->correlation('method'),
        );
        $service->recordHealth(
            'eligibility.health.wallet.binding.000001',
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy wallet refund binding observation.',
            $this->correlation('health'),
        );
    }

    private function quote(int $userId, int $offeringId, string $suffix): object
    {
        return $this->app->make(QuoteService::class)->create(
            'purchase.wallet.binding.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
            $userId,
            $offeringId,
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
    }

    private function fundedWallet(int $userId, int $amountIrr): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.wallet.binding.test.asset',
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.binding.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.binding.fund.000001',
            'wallet_binding_test_funding',
            $this->correlation('fund'),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'wallet-binding',
        );

        return $walletId;
    }

    private function authority(string $settlementPublicId): object
    {
        $row = DB::table('purchase_settlements as settlement')
            ->join('purchase_wallet_reservations as reservation', 'reservation.payment_intent_id', '=', 'settlement.payment_intent_id')
            ->join('wallet_holds as hold', 'hold.id', '=', 'reservation.wallet_hold_id')
            ->where('settlement.public_id', $settlementPublicId)
            ->first([
                'settlement.id as settlement_id',
                'settlement.payment_intent_id as intent_id',
                'hold.id as hold_id',
                'hold.captured_ledger_transaction_id as capture_ledger_id',
            ]);
        self::assertNotNull($row);
        self::assertNotNull($row->capture_ledger_id);

        return $row;
    }

    private function ledgerSourceId(string $refundKey, int $settlementId, int $intentId, int $holdId, int $captureLedgerId): string
    {
        return hash('sha256', implode("\0", [
            'wallet-purchase-refund',
            $refundKey,
            'settlement:'.$settlementId,
            'intent:'.$intentId,
            'hold:'.$holdId,
            'capture-ledger:'.$captureLedgerId,
        ]));
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-wallet-binding:'.$suffix);
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
