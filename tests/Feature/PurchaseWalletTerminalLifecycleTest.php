<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Shared\Application\Clock;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PaymentEligibilityAccessFoundationSeeder;
use Database\Seeders\WalletFinancialFoundationSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PurchaseWalletTerminalClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement PAY-001 PAY-002 WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 QUA-004 */
final class PurchaseWalletTerminalLifecycleTest extends TestCase
{
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private PurchaseWalletTerminalClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
        $this->seed(PaymentEligibilityAccessFoundationSeeder::class);
        $this->seed(WalletFinancialFoundationSeeder::class);
        $this->clock = new PurchaseWalletTerminalClock(now('UTC')->toDateTimeImmutable());
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_expiry_releases_hold_restores_balance_and_blocks_capture(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('expiry');
        $funded = $quote->finalPriceIrr + 500_000;
        $walletId = $this->fundedWallet($userId, $funded, 'expiry');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'purchase.wallet.terminal.expiry.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('expiry-reserve'),
        );
        $ledgerBefore = DB::table('ledger_transactions')->count();

        $this->clock->value = $this->clock->value->modify('+31 minutes');
        DB::statement('SET timestamp = '.$this->clock->value->getTimestamp());
        $expired = $service->expire($intent->intentPublicId, $this->correlation('expiry'));

        self::assertFalse($expired->replayed);
        self::assertSame('released', $expired->status->value);
        self::assertSame(PaymentIntentState::Expired->value, DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('released', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame($ledgerBefore, DB::table('ledger_transactions')->count());
        self::assertSame($funded, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);
        self::assertSame(0, DB::table('purchase_settlements')->count());

        self::assertTrue($service->expire($intent->intentPublicId, $this->correlation('expiry-replay'))->replayed);
        $this->assertRuntimeMessage(
            'Wallet purchase intent is not in a capturable state.',
            fn (): mixed => $service->capture($intent->intentPublicId, $this->correlation('expiry-capture')),
        );
    }

    public function test_cancel_requires_release_authority_then_converges_idempotently(): void
    {
        [$userId, $quote, $decision] = $this->purchaseContext('cancel');
        $funded = $quote->finalPriceIrr + 400_000;
        $walletId = $this->fundedWallet($userId, $funded, 'cancel');
        $service = $this->app->make(PurchaseWalletPaymentService::class);
        $intent = $service->reserve(
            'purchase.wallet.terminal.cancel.000001',
            $userId,
            $walletId,
            $quote->quotePublicId,
            $decision->publicId,
            $this->correlation('cancel-reserve'),
        );
        $ledgerBefore = DB::table('ledger_transactions')->count();

        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->update(['state' => PaymentIntentState::Canceled->value]));
        self::assertSame('active', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));

        $canceled = $service->cancel($intent->intentPublicId, $this->correlation('cancel'));
        self::assertFalse($canceled->replayed);
        self::assertSame('released', $canceled->status->value);
        self::assertSame(PaymentIntentState::Canceled->value, DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertSame('released', DB::table('wallet_holds')->where('source_id', $intent->intentPublicId)->value('status'));
        self::assertSame($ledgerBefore, DB::table('ledger_transactions')->count());
        self::assertSame($funded, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->availableBalance->amount);

        self::assertTrue($service->cancel($intent->intentPublicId, $this->correlation('cancel-replay'))->replayed);
        $this->assertRuntimeMessage(
            'Wallet purchase intent is not in a capturable state.',
            fn (): mixed => $service->capture($intent->intentPublicId, $this->correlation('cancel-capture')),
        );
    }

    /** @return array{0:int,1:object,2:object} */
    private function purchaseContext(string $suffix): array
    {
        $administratorId = $this->ownerAdministrator();
        $userId = $this->quoteUser('customer');
        $offering = $this->quoteOffering();
        $quote = $this->app->make(QuoteService::class)->create(
            'purchase.wallet.terminal.quote.'.$suffix.'.'.substr(hash('sha256', (string) $userId), 0, 16),
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
        $eligibility->configureMethod(
            'eligibility.method.wallet.terminal.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            false,
            1,
            'Wallet terminal lifecycle test configuration.',
            $this->correlation('method-'.$suffix),
        );
        $eligibility->recordHealth(
            'eligibility.health.wallet.terminal.'.$suffix.'.000001',
            $administratorId,
            'wallet',
            true,
            $this->clock->value->modify('+10 minutes'),
            'Healthy wallet terminal lifecycle observation.',
            $this->correlation('health-'.$suffix),
        );
        $decision = $eligibility->evaluate(
            'eligibility.purchase.wallet.terminal.'.$suffix.'.000001',
            $userId,
            $quote->quotePublicId,
        );

        return [$userId, $quote, $decision];
    }

    private function fundedWallet(int $userId, int $amountIrr, string $suffix): int
    {
        $now = now('UTC');
        $assetId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'system.wallet.terminal.test.asset.'.$suffix,
            'account_class' => 'asset',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $walletId = (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.terminal.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.terminal.fund.'.$suffix.'.000001',
            'wallet_terminal_test_funding',
            $this->correlation('fund-'.$suffix),
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive($amountIrr)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amountIrr)),
            ],
            'test_fixture',
            'wallet-terminal-'.$suffix,
        );

        return $walletId;
    }

    private function correlation(string $suffix): string
    {
        return hash('sha256', 'purchase-wallet-terminal:'.$suffix);
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

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }
}
