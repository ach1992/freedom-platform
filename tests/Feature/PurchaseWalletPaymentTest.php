<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\PurchaseSettlementService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
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
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PurchaseWalletPaymentClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement BUY-002 PAY-001 PAY-002 PAY-003 WAL-001 WAL-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 QUA-004 */
final class PurchaseWalletPaymentTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseWalletPaymentClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new PurchaseWalletPaymentClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_reserve_binds_one_purchase_intent_to_one_cash_hold_and_replays_without_duplicate_effects(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('reserve');
        $walletId = $this->fundedWallet($userId, 1_500_000, 'reserve');
        $service = $this->app->make(PurchaseWalletPaymentService::class);

        $receipt = $service->reserve(
            'purchase.wallet.reserve.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('reserve'),
        );

        self::assertFalse($receipt->replayed);
        self::assertSame(PaymentIntentState::AwaitingUserAction, $receipt->state);
        self::assertSame('wallet', $receipt->methodCode);
        self::assertSame(1, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('source_type', 'payment_intent')->count());
        self::assertSame('active', DB::table('wallet_holds')->value('status'));
        self::assertSame($receipt->intentPublicId, DB::table('wallet_holds')->value('source_id'));
        self::assertNull(DB::table('payment_intents')->where('public_id', $receipt->intentPublicId)->value('wallet_account_id'));
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());

        $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
        self::assertSame(1_500_000, $balance->ledgerBalance->amount);
        self::assertSame(1_000_000, $balance->activeHolds->amount);
        self::assertSame(500_000, $balance->availableBalance->amount);

        $replay = $service->reserve(
            'purchase.wallet.reserve.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('reserve-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->intentPublicId, $replay->intentPublicId);
        self::assertSame(1, DB::table('payment_intents')->where('purpose', 'purchase')->count());
        self::assertSame(1, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(1, DB::table('wallet_holds')->where('source_type', 'payment_intent')->count());
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
    }

    public function test_insufficient_wallet_balance_rolls_back_purchase_intent_reservation_and_hold_together(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('insufficient');
        $walletId = $this->fundedWallet($userId, 500_000, 'insufficient');
        $service = $this->app->make(PurchaseWalletPaymentService::class);

        $this->assertDomainMessage(
            'Wallet available balance is insufficient for this hold.',
            fn (): mixed => $service->reserve(
                'purchase.wallet.insufficient.000001',
                $userId,
                $walletId,
                $quote->quotePublicId,
                $decision->publicId,
                $this->correlation('insufficient'),
            ),
        );

        self::assertSame(0, DB::table('payment_intents')->where('purpose', 'purchase')->count());
        self::assertSame(0, DB::table('purchase_wallet_reservations')->count());
        self::assertSame(0, DB::table('wallet_holds')->where('source_type', 'payment_intent')->count());
        $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
        self::assertSame(500_000, $balance->ledgerBalance->amount);
        self::assertSame(0, $balance->activeHolds->amount);
        self::assertSame(500_000, $balance->availableBalance->amount);
    }

    public function test_capture_debits_wallet_once_and_converges_into_same_pre_payment_order_and_purchase_settlement(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('capture');
        $walletId = $this->fundedWallet($userId, 1_500_000, 'capture');
        DB::statement('SET timestamp = '.$this->clock->value->modify('+1 second')->getTimestamp());
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('order-open'),
        );
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'purchase.wallet.capture.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('capture-reserve'),
        );

        $paid = $service->capture($intent->intentPublicId, $this->correlation('capture'));

        self::assertSame($opening->orderId, $paid->orderId);
        self::assertSame($opening->orderPublicId, $paid->orderPublicId);
        self::assertSame('paid', $paid->state->value);
        self::assertSame(1, $paid->stateVersion);
        self::assertSame($quote->finalPriceIrr, $paid->commercialAmount->amount());
        self::assertSame($quote->finalPriceIrr, $paid->settledAmount->amount());
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('captured', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('payment_provider_events')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('orders')->where('source_quote_id', $quote->quoteId)->count());
        self::assertSame('paid', DB::table('orders')->where('id', $opening->orderId)->value('state'));
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('promotion_usage_reservations')->count());
        self::assertSame(0, DB::table('promotion_usage_redemptions')->count());
        self::assertSame(0, DB::table('promotion_usage_releases')->count());

        $hold = DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->first();
        self::assertNotNull($hold);
        $ledgerId = (int) $hold->captured_ledger_transaction_id;
        $ledger = DB::table('ledger_transactions')->where('id', $ledgerId)->first();
        self::assertNotNull($ledger);
        self::assertSame('wallet_hold_capture', $ledger->transaction_type);
        self::assertSame('wallet_hold', $ledger->source_type);
        self::assertSame((string) $hold->id, $ledger->source_id);
        self::assertSame(
            hash('sha256', "wallet\0ledger:".$ledgerId),
            DB::table('purchase_settlements')->where('provider_code', 'wallet')->value('provider_transaction_id'),
        );

        $purchaseClearingId = (int) DB::table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::PURCHASE_CLEARING)
            ->value('id');
        self::assertSame(1, DB::table('ledger_entries')
            ->where('ledger_transaction_id', $ledgerId)
            ->where('ledger_account_id', $walletId)
            ->where('direction', 'debit')
            ->where('amount_irr', $quote->finalPriceIrr)
            ->count());
        self::assertSame(1, DB::table('ledger_entries')
            ->where('ledger_transaction_id', $ledgerId)
            ->where('ledger_account_id', $purchaseClearingId)
            ->where('direction', 'credit')
            ->where('amount_irr', $quote->finalPriceIrr)
            ->count());

        $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
        self::assertSame(500_000, $balance->ledgerBalance->amount);
        self::assertSame(0, $balance->activeHolds->amount);
        self::assertSame(500_000, $balance->availableBalance->amount);

        $replay = $service->capture($intent->intentPublicId, $this->correlation('capture-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($paid->purchaseSettlementPublicId, $replay->purchaseSettlementPublicId);
        self::assertSame($paid->orderPublicId, $replay->orderPublicId);
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_hold_capture')->count());
        self::assertSame(1, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(1, DB::table('orders')->where('source_quote_id', $quote->quoteId)->count());
    }

    public function test_release_restores_available_balance_without_money_movement_and_blocks_later_capture(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('release');
        $walletId = $this->fundedWallet($userId, 1_200_000, 'release');
        DB::statement('SET timestamp = '.$this->clock->value->modify('+1 second')->getTimestamp());
        $opening = $this->app->make(PurchaseOrderService::class)->openFromQuote(
            $quote->quotePublicId,
            $userId,
            $this->correlation('release-order-open'),
        );
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'purchase.wallet.release.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('release-reserve'),
        );
        $ledgerBefore = DB::table('ledger_transactions')->count();

        $released = $service->release($intent->intentPublicId, 'wallet purchase abandoned');
        self::assertFalse($released->replayed);
        self::assertSame('released', $released->status->value);
        self::assertSame($ledgerBefore, DB::table('ledger_transactions')->count());
        self::assertSame(1_200_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);

        $replay = $service->release($intent->intentPublicId, 'wallet purchase abandoned');
        self::assertTrue($replay->replayed);
        $this->assertRuntimeMessage(
            'Wallet hold release replay conflicts with the accepted reason.',
            fn (): mixed => $service->release($intent->intentPublicId, 'different reason'),
        );
        $this->assertRuntimeMessage(
            'Released wallet hold cannot be captured.',
            fn (): mixed => $service->capture($intent->intentPublicId, $this->correlation('release-capture-rejected')),
        );

        self::assertSame('awaiting_user_action', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('awaiting_payment', DB::table('orders')->where('id', $opening->orderId)->value('state'));
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_hold_capture')->count());
    }

    public function test_database_guards_reject_reservation_mutation_and_wallet_settlement_without_captured_hold(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('guards');
        $walletId = $this->fundedWallet($userId, 1_500_000, 'guards');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'purchase.wallet.guards.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('guards-reserve'),
        );
        $reservationId = (int) DB::table('purchase_wallet_reservations')->value('id');

        $this->assertQueryRejected(static fn (): int => DB::table('purchase_wallet_reservations')
            ->where('id', $reservationId)
            ->update(['wallet_account_id' => $walletId + 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('purchase_wallet_reservations')
            ->where('id', $reservationId)
            ->delete());

        DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->update(['state' => PaymentIntentState::Submitted->value]);
        $eventId = hash('sha256', 'wallet-forged-event');
        $transactionId = hash('sha256', 'wallet-forged-transaction');
        $payloadHash = hash('sha256', 'wallet-forged-payload');
        $occurredAt = $this->clock->value;
        $verified = new VerifiedPaymentEvent(
            $eventId,
            $payloadHash,
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($quote->finalPriceIrr),
                $occurredAt,
                $occurredAt,
                $payloadHash,
                ['wallet_hold_id' => $reservationId],
            ),
        );

        $this->assertQueryRejected(fn (): mixed => $this->app->make(PurchaseSettlementService::class)->capture(
            $intent->intentPublicId,
            'wallet',
            $verified,
            $this->correlation('forged-settlement'),
        ));
        self::assertSame(0, DB::table('purchase_settlements')->where('provider_code', 'wallet')->count());
        self::assertSame(0, DB::table('payment_provider_transactions')->where('provider_code', 'wallet')->count());
        self::assertSame('active', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
    }

    /** @return array{0:int,1:object,2:object} */
    private function purchaseContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.wallet.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
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
            'eligibility.purchase.wallet.'.$suffix.'.000001',
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote, $decision];
    }

    private function configureHealthyWalletMethod(PaymentMethodEligibilityService $service, int $administratorId, string $suffix): void
    {
        $service->configureMethod(
            'eligibility.method.wallet.purchase.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet purchase payment test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $service->recordHealth(
            'eligibility.health.wallet.purchase.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy wallet purchase payment observation.',
            $this->correlation('health-'.$suffix),
        );
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.wallet.purchase.test.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.purchase.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.purchase.fund.'.$suffix.'.000001',
            'wallet_purchase_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'wallet-purchase-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-wallet:'.$suffix);
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
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
