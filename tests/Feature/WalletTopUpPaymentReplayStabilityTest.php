<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\Contracts\PaymentEvidence;
use App\Modules\Payments\Application\Contracts\PaymentEvidenceAuthority;
use App\Modules\Payments\Application\Contracts\PaymentTransactionStatus;
use App\Modules\Payments\Application\Contracts\ProviderOperationOutcome;
use App\Modules\Payments\Application\Contracts\VerifiedPaymentEvent;
use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Shared\Domain\Money;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PAY-003 WAL-001 DAT-003 DAT-004 QUA-001 */
final class WalletTopUpPaymentReplayStabilityTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_exact_accepted_capture_replays_after_wallet_is_later_deactivated(): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId);
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.replay.stability.000001',
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(275_000),
            $this->correlation('create'),
        );
        $event = $this->event();
        $settlement = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $event,
            $this->correlation('capture'),
        );

        DB::table('ledger_accounts')->where('id', $walletId)->update([
            'is_active' => false,
            'updated_at' => now('UTC'),
        ]);

        $replay = $service->capture(
            $intent->intentPublicId,
            'fake_gateway',
            $event,
            $this->correlation('replay'),
        );

        self::assertTrue($replay->replayed);
        self::assertSame($settlement->settlementId, $replay->settlementId);
        self::assertSame($settlement->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'payment.wallet_top_up.captured')->count());
    }

    private function event(): VerifiedPaymentEvent
    {
        $eventId = 'evt-replay-stability';
        $transactionId = 'txn-replay-stability';
        $amount = 275_000;

        return new VerifiedPaymentEvent(
            $eventId,
            hash('sha256', 'event:'.$eventId.':'.$transactionId.':'.$amount),
            new PaymentEvidence(
                ProviderOperationOutcome::Success,
                PaymentEvidenceAuthority::Authoritative,
                PaymentTransactionStatus::Settled,
                $transactionId,
                $eventId,
                Money::irr($amount),
                new DateTimeImmutable('2026-08-08T10:00:00+00:00'),
                new DateTimeImmutable('2026-08-08T10:00:01+00:00'),
                hash('sha256', 'evidence:'.$eventId.':'.$transactionId.':'.$amount),
                ['bank_reference' => 'SAFE-REPLAY-STABILITY'],
            ),
        );
    }

    private function wallet(int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.topup.replay-stability.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
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
        return substr(hash('sha256', 'wallet-top-up-replay-stability:'.$suffix), 0, 64);
    }
}
