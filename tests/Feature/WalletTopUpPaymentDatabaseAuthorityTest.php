<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Payments\Application\WalletTopUpPaymentService;
use App\Shared\Domain\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement PAY-002 PAY-003 WAL-001 DAT-003 DAT-004 SEC-002 QUA-001 */
final class WalletTopUpPaymentDatabaseAuthorityTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_pre_capture_intent_cannot_receive_a_capture_timestamp_directly(): void
    {
        [$intentId] = $this->intentFixture('capture-timestamp');

        $this->assertQueryRejected(static fn (): int => DB::table('payment_intents')
            ->where('id', $intentId)
            ->update([
                'captured_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]));

        self::assertNull(DB::table('payment_intents')->where('id', $intentId)->value('captured_at'));
    }

    public function test_provider_transaction_cannot_be_forged_from_non_authoritative_event(): void
    {
        [$intentId] = $this->intentFixture('non-authoritative-provider-event');
        $now = now('UTC');
        $providerEventRowId = (int) DB::table('payment_provider_events')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_code' => 'fake_gateway',
            'provider_event_id' => 'evt-db-nonauthoritative',
            'event_payload_hash' => hash('sha256', 'db-nonauthoritative-event'),
            'provider_transaction_id' => 'txn-db-nonauthoritative',
            'evidence_payload_hash' => hash('sha256', 'db-nonauthoritative-evidence'),
            'evidence_authority' => 'non_authoritative',
            'transaction_status' => 'settled',
            'amount_irr' => 190_000,
            'currency' => 'IRR',
            'occurred_at' => $now,
            'settled_at' => $now,
            'safe_evidence' => json_encode(['bank_reference' => 'SAFE-DB-AUTHORITY'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        $this->assertQueryRejected(static fn (): int => DB::table('payment_provider_transactions')->insertGetId([
            'payment_intent_id' => $intentId,
            'provider_event_row_id' => $providerEventRowId,
            'provider_code' => 'fake_gateway',
            'provider_transaction_id' => 'txn-db-nonauthoritative',
            'evidence_payload_hash' => hash('sha256', 'db-nonauthoritative-evidence'),
            'transaction_status' => 'settled',
            'amount_irr' => 190_000,
            'currency' => 'IRR',
            'occurred_at' => now('UTC'),
            'settled_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]));

        self::assertSame(0, DB::table('payment_provider_transactions')->count());
        self::assertSame(0, DB::table('wallet_top_up_settlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'wallet_external_top_up')->count());
    }

    /** @return array{0:int,1:int} */
    private function intentFixture(string $suffix): array
    {
        $userId = $this->user();
        $walletId = $this->wallet($userId, $suffix);
        $intent = $this->app->make(WalletTopUpPaymentService::class)->create(
            'topup.db-authority.'.$suffix,
            $userId,
            $walletId,
            'fake_gateway',
            Money::irr(190_000),
            $this->correlation($suffix),
        );
        $intentId = DB::table('payment_intents')->where('public_id', $intent->intentPublicId)->value('id');
        self::assertIsInt($intentId);

        return [$intentId, $walletId];
    }

    private function wallet(int $userId, string $suffix): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.cash.topup.db-authority.'.$suffix.'.'.$userId,
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
        return substr(hash('sha256', 'wallet-top-up-db-authority:'.$suffix), 0, 64);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected database authority guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
