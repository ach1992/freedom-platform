<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 WAL-001 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
final class WalletTopUpPaymentIntentTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_wallet_top_up_intent_is_cash_only_idempotent_and_conflict_safe(): void
    {
        $userId = $this->user();
        $cashWalletId = $this->wallet($userId, 'cash', 'intent-cash');
        $promotionalWalletId = $this->wallet($userId, 'promotional', 'intent-promotional');
        $service = $this->app->make(WalletTopUpPaymentService::class);

        $intent = $service->create(
            'topup.intent.create.000001',
            $userId,
            $cashWalletId,
            'fake_gateway',
            Money::irr(500_000),
            $this->correlation('create-intent'),
        );
        self::assertSame(PaymentIntentState::AwaitingUserAction, $intent->state);
        self::assertSame($cashWalletId, $intent->walletAccountId);
        self::assertSame(500_000, $intent->amount->amount());
        self::assertFalse($intent->replayed);

        $replay = $service->create(
            'topup.intent.create.000001',
            $userId,
            $cashWalletId,
            'fake_gateway',
            Money::irr(500_000),
            $this->correlation('create-intent-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($intent->intentPublicId, $replay->intentPublicId);
        self::assertSame(1, DB::table('payment_intents')->count());

        $this->assertRuntimeMessage(
            'Payment intent creation key conflict.',
            fn (): mixed => $service->create(
                'topup.intent.create.000001',
                $userId,
                $cashWalletId,
                'fake_gateway',
                Money::irr(500_001),
                $this->correlation('create-intent-conflict'),
            ),
        );
        $this->assertDomainMessage(
            'Wallet top-up target must be an active owned IRR cash wallet.',
            fn (): mixed => $service->create(
                'topup.intent.create.000002',
                $userId,
                $promotionalWalletId,
                'fake_gateway',
                Money::irr(100_000),
                $this->correlation('create-promotional-rejected'),
            ),
        );
    }

    public function test_non_authoritative_browser_like_evidence_cannot_capture_or_credit_wallet(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, 'cash', 'non-authoritative');
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.intent.nonauth.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(300_000),
            $this->correlation('create-nonauth'),
        );

        $this->assertDomainMessage(
            'Non-authoritative payment evidence cannot capture a wallet top-up.',
            fn (): mixed => $service->capture(
                $intent->intentPublicId,
                'fake_gateway',
                $this->event('evt-nonauth-1', 'txn-nonauth-1', 300_000, false),
                $this->correlation('capture-nonauth'),
            ),
        );

        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('payment_provider_transactions')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
        self::assertSame(0, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->ledgerBalance->amount);
    }

    public function test_authoritative_settled_evidence_credits_cash_wallet_exactly_once_and_replays(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, 'cash', 'capture');
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.intent.capture.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(750_000),
            $this->correlation('create-capture'),
        );
        $event = $this->event('evt-capture-1', 'txn-capture-1', 750_000, true);

        $settlement = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $event,
            $this->correlation('capture-authoritative'),
        );
        self::assertSame(PaymentIntentState::Captured, $settlement->state);
        self::assertSame(750_000, $settlement->amount->amount());
        self::assertFalse($settlement->replayed);
        self::assertSame('captured', DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('state'));
        self::assertNotNull(DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('captured_at'));
        self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->count());
        self::assertSame(1, DB::table('payment_attempts')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payment.wallet_top_up.captured')->count());

        $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
        self::assertSame(750_000, $balance->ledgerBalance->amount);
        self::assertSame(750_000, $balance->availableBalance->amount);

        $clearingId = (int) DB::table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::EXTERNAL_TOP_UP_CLEARING)
            ->value('id');
        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $settlement->ledgerTransactionId)
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->map(static fn (object $row): array => [(int) $row->ledger_account_id, $row->direction, (int) $row->amount_irr])
            ->all();
        self::assertContains([$clearingId, 'debit', 750_000], $entries);
        self::assertContains([$walletId, 'credit', 750_000], $entries);
        self::assertCount(2, $entries);

        $replay = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $event,
            $this->correlation('capture-authoritative-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($settlement->settlementId, $replay->settlementId);
        self::assertSame($settlement->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
        self::assertSame(750_000, $this->app->make(WalletHoldService::class)->balance($userId, $walletId)->ledgerBalance->amount);
    }

    public function test_duplicate_transaction_with_new_event_replays_but_conflicting_event_or_cross_intent_reuse_fails(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, 'cash', 'duplicate-event');
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.intent.dup.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(210_000),
            $this->correlation('create-dup-event'),
        );
        $primary = $this->event('evt-dup-primary', 'txn-shared-1', 210_000, true);
        $settlement = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $primary,
            $this->correlation('capture-dup-primary'),
        );

        $newEventSameTransaction = $this->event('evt-dup-secondary', 'txn-shared-1', 210_000, true);
        $replay = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $newEventSameTransaction,
            $this->correlation('capture-dup-secondary'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($settlement->settlementId, $replay->settlementId);
        self::assertSame(2, DB::table('payment_provider_events')->count());
        self::assertSame(1, DB::table('payment_provider_transactions')->count());

        $this->assertRuntimeMessage(
            'Payment provider event replay conflicts with the accepted event.',
            fn (): mixed => $service->capture(
                $intent->intentPublicId,
                'fake_gateway',
                $this->event('evt-dup-primary', 'txn-shared-1', 210_001, true),
                $this->correlation('capture-dup-event-conflict'),
            ),
        );

        $secondWalletId = $this->wallet($userId, 'cash', 'duplicate-event-second');
        $secondIntent = $service->create(
            'topup.intent.dup.000002',
            $userId,
            $secondWalletId,
            'fake_gateway',
            Money::irr(210_000),
            $this->correlation('create-dup-second-intent'),
        );
        $this->assertRuntimeMessage(
            'Payment provider event replay conflicts with the accepted event.',
            fn (): mixed => $service->capture(
                $secondIntent->intentPublicId,
                'fake_gateway',
                $primary,
                $this->correlation('capture-cross-intent-event'),
            ),
        );
        self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
    }

    public function test_provider_amount_mismatch_and_sensitive_safe_evidence_fail_closed(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, 'cash', 'evidence-guard');
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.intent.guard.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(120_000),
            $this->correlation('create-evidence-guard'),
        );

        $this->assertRuntimeMessage(
            'Authoritative provider evidence amount does not match the payment intent.',
            fn (): mixed => $service->capture(
                $intent->intentPublicId,
                'fake_gateway',
                $this->event('evt-amount-mismatch', 'txn-amount-mismatch', 120_001, true),
                $this->correlation('capture-amount-mismatch'),
            ),
        );

        $this->assertDomainMessage(
            'Payment safe evidence contains a forbidden sensitive field.',
            fn (): mixed => $service->capture(
                $intent->intentPublicId,
                'fake_gateway',
                $this->event('evt-secret-field', 'txn-secret-field', 120_000, true, ['authorization_token' => 'do-not-store']),
                $this->correlation('capture-secret-field'),
            ),
        );
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('payment_provider_events')->count());
    }

    public function test_payment_intent_and_settlement_financial_identity_is_database_immutable(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, 'cash', 'immutable');
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.intent.immutable.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(330_000),
            $this->correlation('create-immutable'),
        );
        $settlement = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $this->event('evt-immutable', 'txn-immutable', 330_000, true),
            $this->correlation('capture-immutable'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->update(['amount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('public_id', $intent->intentPublicId)
            ->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_top_up_settlements')
            ->where('id', $settlement->settlementId)
            ->update(['amount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_top_up_settlements')
            ->where('id', $settlement->settlementId)
            ->delete());
        self::assertSame(330_000, (int) DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('amount_irr'));
        self::assertSame(330_000, (int) DB::table('wallet_top_up_settlements')->where('id', $settlement->settlementId)->value('amount_irr'));
    }

    /** @param array<string, scalar|null> $safeEvidence */
    private function event(
        string $eventId,
        string $transactionId,
        int $amountIrr,
        bool $authoritative,
        array $safeEvidence = ['bank_reference' => 'SAFE-REFERENCE'],
    ): VerifiedPaymentEvent {
        $occurredAt = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $settledAt = new DateTimeImmutable('2026-08-08T10:00:01+00:00');

        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'event:'.$eventId.':'.$transactionId.':'.$amountIrr),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                $authoritative ? PaymentEvidenceAuthority::Authoritative : PaymentEvidenceAuthority::NonAuthoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amountIrr),
                $occurredAt,
                $settledAt,
                hash('sha256', 'evidence:'.$eventId.':'.$transactionId.':'.$amountIrr),
                $safeEvidence,
            ),
        );
    }

    private function wallet(int $userId, string $bucket, string $suffix): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.'.$bucket.'.topup.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function user(): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'wallet-top-up:'.$suffix), 0, 64);
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
