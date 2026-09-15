<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-002 DAT-002 DAT-003 DAT-004 QUA-001 */
final class FinancialLedgerFoundationTest extends TestCase
{
    use DatabaseTruncation;

    public function test_balanced_posting_is_finalized_and_exact_replay_is_idempotent(): void
    {
        foreach (['ledger_accounts', 'ledger_transactions', 'ledger_entries'] as $table) {
            self::assertTrue(Schema::hasTable($table));
        }

        $userId = $this->user();
        $cashAssetId = $this->account('system.cash.asset', 'asset');
        $walletCashId = $this->account('wallet.cash.'.$userId, 'liability', $userId, 'cash');
        $service = $this->app->make(LedgerPostingService::class);
        $entries = [
            new LedgerEntryDraft($cashAssetId, LedgerDirection::Debit, IrrMoney::positive(1_500_000)),
            new LedgerEntryDraft($walletCashId, LedgerDirection::Credit, IrrMoney::positive(1_500_000)),
        ];

        $receipt = $service->post(
            'ledger.topup.capture.000001',
            'wallet_topup_capture',
            'corr-ledger-topup-000001',
            $entries,
            'payment_intent',
            'pi-test-000001',
        );
        self::assertFalse($receipt->replayed);
        self::assertSame(1_500_000, $receipt->total->amount);
        self::assertSame(2, $receipt->entryCount);

        /** @var object{expected_total_irr: int|string, posted_debit_irr: int|string, posted_credit_irr: int|string, entry_count: int|string, finalized_at: string|null} $transaction */
        $transaction = DB::table('ledger_transactions')->where('id', $receipt->transactionId)->firstOrFail([
            'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at',
        ]);
        self::assertSame(1_500_000, (int) $transaction->expected_total_irr);
        self::assertSame(1_500_000, (int) $transaction->posted_debit_irr);
        self::assertSame(1_500_000, (int) $transaction->posted_credit_irr);
        self::assertSame(2, (int) $transaction->entry_count);
        self::assertNotNull($transaction->finalized_at);
        self::assertSame(2, DB::table('ledger_entries')->where('ledger_transaction_id', $receipt->transactionId)->count());

        $replay = $service->post(
            'ledger.topup.capture.000001',
            'wallet_topup_capture',
            'corr-ledger-topup-replay',
            array_reverse($entries),
            'payment_intent',
            'pi-test-000001',
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->transactionId, $replay->transactionId);
        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(2, DB::table('ledger_entries')->count());

        try {
            $service->post(
                'ledger.topup.capture.000001',
                'wallet_topup_capture',
                'corr-ledger-topup-conflict',
                [
                    new LedgerEntryDraft($cashAssetId, LedgerDirection::Debit, IrrMoney::positive(1_500_001)),
                    new LedgerEntryDraft($walletCashId, LedgerDirection::Credit, IrrMoney::positive(1_500_001)),
                ],
                'payment_intent',
                'pi-test-000001',
            );
            self::fail('Expected ledger command conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Ledger command key conflict.', $exception->getMessage());
        }

        self::assertSame(1, DB::table('ledger_transactions')->count());
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertSame(1_500_000, (int) DB::table('ledger_transactions')->value('expected_total_irr'));
    }

    public function test_unbalanced_or_inactive_account_posting_has_no_financial_effect(): void
    {
        $assetId = $this->account('system.unbalanced.asset', 'asset');
        $liabilityId = $this->account('system.unbalanced.liability', 'liability');
        $service = $this->app->make(LedgerPostingService::class);

        try {
            $service->post(
                'ledger.unbalanced.000001',
                'test_posting',
                'corr-ledger-unbalanced',
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(100)),
                    new LedgerEntryDraft($liabilityId, LedgerDirection::Credit, IrrMoney::positive(99)),
                ],
            );
            self::fail('Expected unbalanced posting rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Ledger transaction must be balanced.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame(0, DB::table('ledger_entries')->count());

        DB::table('ledger_accounts')->where('id', $liabilityId)->update([
            'is_active' => false,
            'updated_at' => now('UTC'),
        ]);
        try {
            $service->post(
                'ledger.inactive.000001',
                'test_posting',
                'corr-ledger-inactive1',
                [
                    new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(100)),
                    new LedgerEntryDraft($liabilityId, LedgerDirection::Credit, IrrMoney::positive(100)),
                ],
            );
            self::fail('Expected inactive account rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Ledger account is inactive.', $exception->getMessage());
        }
        self::assertSame(0, DB::table('ledger_transactions')->count());
    }

    public function test_database_refuses_unbalanced_finalization_even_when_bypassing_service(): void
    {
        $assetId = $this->account('system.raw.asset', 'asset');
        $transactionId = $this->rawTransaction('ledger.raw.unbalanced.000001', 100);
        DB::table('ledger_entries')->insert([
            'ledger_transaction_id' => $transactionId,
            'ledger_account_id' => $assetId,
            'sequence' => 1,
            'direction' => 'debit',
            'amount_irr' => 100,
            'created_at' => now('UTC'),
        ]);

        try {
            DB::table('ledger_transactions')->where('id', $transactionId)->update(['finalized_at' => now('UTC')]);
            self::fail('Expected unbalanced finalization rejection.');
        } catch (QueryException) {
            self::assertNull(DB::table('ledger_transactions')->where('id', $transactionId)->value('finalized_at'));
            self::assertSame(100, (int) DB::table('ledger_transactions')->where('id', $transactionId)->value('posted_debit_irr'));
            self::assertSame(0, (int) DB::table('ledger_transactions')->where('id', $transactionId)->value('posted_credit_irr'));
        }
    }

    public function test_finalized_transactions_entries_and_account_identity_are_immutable(): void
    {
        $assetId = $this->account('system.immutable.asset', 'asset');
        $liabilityId = $this->account('system.immutable.liability', 'liability');
        $receipt = $this->app->make(LedgerPostingService::class)->post(
            'ledger.immutable.000001',
            'test_posting',
            'corr-ledger-immutable',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(500)),
                new LedgerEntryDraft($liabilityId, LedgerDirection::Credit, IrrMoney::positive(500)),
            ],
        );
        $entryId = (int) DB::table('ledger_entries')->where('ledger_transaction_id', $receipt->transactionId)->min('id');

        $this->assertQueryRejected(static fn (): int => DB::table('ledger_entries')->where('id', $entryId)->update(['amount_irr' => 501]));
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_entries')->where('id', $entryId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_transactions')->where('id', $receipt->transactionId)->update(['correlation_id' => 'corr-ledger-mutated']));
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_transactions')->where('id', $receipt->transactionId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_accounts')->where('id', $assetId)->update(['code' => 'system.changed.asset']));
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_accounts')->where('id', $assetId)->delete());

        self::assertSame(500, (int) DB::table('ledger_entries')->where('id', $entryId)->value('amount_irr'));
        self::assertSame('system.immutable.asset', DB::table('ledger_accounts')->where('id', $assetId)->value('code'));
    }

    public function test_wallet_account_schema_enforces_cash_and_promotional_liability_buckets(): void
    {
        $userId = $this->user();
        $cashId = $this->account('wallet.cash.schema.'.$userId, 'liability', $userId, 'cash');
        $promotionalId = $this->account('wallet.promotional.schema.'.$userId, 'liability', $userId, 'promotional');
        self::assertNotSame($cashId, $promotionalId);

        $this->assertQueryRejected(fn (): bool => DB::table('ledger_accounts')->insert([
            'code' => 'wallet.invalid.asset.'.$userId,
            'account_class' => 'asset',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]));
        $this->assertQueryRejected(fn (): bool => DB::table('ledger_accounts')->insert([
            'code' => 'wallet.duplicate.cash.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]));
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

    private function account(
        string $code,
        string $class,
        ?int $userId = null,
        ?string $bucket = null,
        bool $active = true,
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => $active,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function rawTransaction(string $commandKey, int $expectedTotal): int
    {
        return (int) DB::table('ledger_transactions')->insertGetId([
            'command_key' => $commandKey,
            'payload_hash' => hash('sha256', $commandKey),
            'transaction_type' => 'raw_test',
            'expected_total_irr' => $expectedTotal,
            'posted_debit_irr' => 0,
            'posted_credit_irr' => 0,
            'entry_count' => 0,
            'source_type' => null,
            'source_id' => null,
            'correlation_id' => 'corr-'.substr(hash('sha256', $commandKey), 0, 24),
            'finalized_at' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
