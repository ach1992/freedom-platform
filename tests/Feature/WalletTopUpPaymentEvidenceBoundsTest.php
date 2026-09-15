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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 WAL-001 DAT-003 DAT-004 SEC-002 QUA-001 */
final class WalletTopUpPaymentEvidenceBoundsTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_provider_safe_evidence_rejects_more_than_thirty_two_fields(): void
    {
        $safeEvidence = [];
        for ($index = 0; $index < 33; $index++) {
            $safeEvidence[sprintf('reference_%02d', $index)] = 'SAFE';
        }

        $this->assertCaptureRejectedByDatabaseBounds($safeEvidence, 'field-count');
    }

    public function test_provider_safe_evidence_rejects_payload_larger_than_eight_kibibytes(): void
    {
        $safeEvidence = [];
        for ($index = 0; $index < 20; $index++) {
            $safeEvidence[sprintf('reference_%02d', $index)] = str_repeat('A', 500);
        }

        self::assertGreaterThan(8192, strlen(json_encode($safeEvidence, JSON_THROW_ON_ERROR)));
        $this->assertCaptureRejectedByDatabaseBounds($safeEvidence, 'payload-size');
    }

    /** @param array<string, scalar|null> $safeEvidence */
    private function assertCaptureRejectedByDatabaseBounds(array $safeEvidence, string $suffix): void
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, $suffix);
        $service = $this->app->make(WalletTopUpPaymentService::class);
        $intent = $service->create(
            'topup.evidence.bounds.'.$suffix,
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(180_000),
            $this->correlation('create-'.$suffix),
        );

        try {
            $service->capture(
                $intent->intentPublicId,
                'fake_gateway',
                $this->event($suffix, $safeEvidence),
                $this->correlation('capture-'.$suffix),
            );
            self::fail('Expected bounded provider safe-evidence database rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(0, DB::table('payment_provider_events')->count());
        self::assertSame(0, DB::table('payment_provider_transactions')->count());
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
    }

    /** @param array<string, scalar|null> $safeEvidence */
    private function event(string $suffix, array $safeEvidence): VerifiedPaymentEvent
    {
        $eventId = 'evt-evidence-bounds-'.$suffix;
        $transactionId = 'txn-evidence-bounds-'.$suffix;
        $amount = 180_000;

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
                $safeEvidence,
            ),
        );
    }

    private function wallet(int $userId, string $suffix): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.topup.evidence-bounds.'.$suffix.'.'.$userId,
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
        return substr(hash('sha256', 'wallet-top-up-evidence-bounds:'.$suffix), 0, 64);
    }
}
