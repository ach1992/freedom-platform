<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\LedgerRefundabilitySnapshot;
use App\Modules\Wallet\Application\RefundEntryAllocation;
use App\Modules\Wallet\Application\WalletRefundService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\RefundDestination;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-004 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001 */
final class WalletRefundFoundationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_wallet_partial_refund_returns_exactly_to_original_wallet_buckets_and_replays(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $cashWalletId = $this->account('wallet.cash.refund.multi.'.$userId, 'liability', $userId, 'cash');
        $promoWalletId = $this->account('wallet.promotional.refund.multi.'.$userId, 'liability', $userId, 'promotional');
        $revenueId = $this->account('system.refund.multi.revenue', 'revenue');
        $sourceId = $this->refundableSource(
            'wallet-multi',
            [
                new LedgerEntryDraft($cashWalletId, LedgerDirection::Debit, IrrMoney::positive(700_000)),
                new LedgerEntryDraft($promoWalletId, LedgerDirection::Debit, IrrMoney::positive(300_000)),
                new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(1_000_000)),
            ],
            1_000_000,
            RefundDestination::Wallet,
        );
        $cashEntryId = $this->entryId($sourceId, $cashWalletId, LedgerDirection::Debit);
        $promoEntryId = $this->entryId($sourceId, $promoWalletId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($sourceId, $revenueId, LedgerDirection::Credit);
        $allocations = [
            new RefundEntryAllocation($cashEntryId, IrrMoney::positive(200_000)),
            new RefundEntryAllocation($promoEntryId, IrrMoney::positive(100_000)),
            new RefundEntryAllocation($revenueEntryId, IrrMoney::positive(300_000)),
        ];

        $service = $this->app->make(WalletRefundService::class);
        $refund = $service->refund(
            'refund.wallet.multi.000001',
            $sourceId,
            RefundDestination::Wallet,
            $allocations,
            $this->context($ownerId, 'wallet-multi-0001'),
        );

        self::assertSame(300_000, $refund->amount->amount);
        self::assertSame(RefundDestination::Wallet, $refund->defaultDestination);
        self::assertSame(RefundDestination::Wallet, $refund->destination);
        self::assertFalse($refund->destinationOverridden);
        self::assertFalse($refund->replayed);

        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $refund->ledgerTransactionId)
            ->orderBy('ledger_account_id')
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->map(static fn (object $row): array => [(int) $row->ledger_account_id, $row->direction, (int) $row->amount_irr])
            ->all();
        $expected = [
            [$cashWalletId, 'credit', 200_000],
            [$promoWalletId, 'credit', 100_000],
            [$revenueId, 'debit', 300_000],
        ];
        sort($entries);
        sort($expected);
        self::assertSame($expected, $entries);

        $replay = $service->refund(
            'refund.wallet.multi.000001',
            $sourceId,
            RefundDestination::Wallet,
            $allocations,
            $this->context($ownerId, 'wallet-multi-0002'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($refund->refundId, $replay->refundId);
        self::assertSame($refund->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('refunds')->count());
        self::assertSame(3, DB::table('refund_allocations')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.refund.complete')->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());
    }

    public function test_cumulative_partial_refunds_stop_at_immutable_refundable_total_not_captured_total(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->account('wallet.cash.refund.cap.'.$userId, 'liability', $userId, 'cash');
        $revenueId = $this->account('system.refund.cap.revenue', 'revenue');
        $sourceId = $this->refundableSource(
            'cap',
            [
                new LedgerEntryDraft($walletId, LedgerDirection::Debit, IrrMoney::positive(1_000_100)),
                new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(1_000_100)),
            ],
            1_000_000,
            RefundDestination::Wallet,
        );
        $walletEntryId = $this->entryId($sourceId, $walletId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($sourceId, $revenueId, LedgerDirection::Credit);
        $service = $this->app->make(WalletRefundService::class);

        $service->refund(
            'refund.cap.000001',
            $sourceId,
            RefundDestination::Wallet,
            $this->pair($walletEntryId, $revenueEntryId, 600_000),
            $this->context($ownerId, 'cap-0001'),
        );
        $service->refund(
            'refund.cap.000002',
            $sourceId,
            RefundDestination::Wallet,
            $this->pair($walletEntryId, $revenueEntryId, 400_000),
            $this->context($ownerId, 'cap-0002'),
        );

        $this->assertDomainMessage(
            'Refund would exceed the source refundable amount.',
            fn (): mixed => $service->refund(
                'refund.cap.000003',
                $sourceId,
                RefundDestination::Wallet,
                $this->pair($walletEntryId, $revenueEntryId, 1),
                $this->context($ownerId, 'cap-0003'),
            ),
        );

        self::assertSame(2, DB::table('refunds')->count());
        self::assertSame(1_000_000, (int) DB::table('refunds')->sum('amount_irr'));
        self::assertSame(3, DB::table('ledger_transactions')->count());
        self::assertSame(1_000_000, (int) DB::table('refund_allocations')->where('source_ledger_entry_id', $walletEntryId)->sum('amount_irr'));
    }

    public function test_manual_external_refund_requires_evidence_and_never_credits_a_wallet(): void
    {
        $ownerId = $this->administrator(true);
        $externalAssetId = $this->account('system.refund.external.cash', 'asset');
        $revenueId = $this->account('system.refund.external.revenue', 'revenue');
        $sourceId = $this->refundableSource(
            'manual-external',
            [
                new LedgerEntryDraft($externalAssetId, LedgerDirection::Debit, IrrMoney::positive(500_000)),
                new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(500_000)),
            ],
            500_000,
            RefundDestination::ManualExternal,
        );
        $assetEntryId = $this->entryId($sourceId, $externalAssetId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($sourceId, $revenueId, LedgerDirection::Credit);
        $allocations = $this->pair($assetEntryId, $revenueEntryId, 200_000);
        $service = $this->app->make(WalletRefundService::class);

        $this->assertDomainMessage(
            'Manual external refund requires payment reference and evidence reference.',
            fn (): mixed => $service->refund(
                'refund.external.000001',
                $sourceId,
                RefundDestination::ManualExternal,
                $allocations,
                $this->context($ownerId, 'external-0001'),
            ),
        );
        self::assertSame(0, DB::table('refunds')->count());

        $refund = $service->refund(
            'refund.external.000001',
            $sourceId,
            RefundDestination::ManualExternal,
            $allocations,
            $this->context($ownerId, 'external-0002'),
            'manual-ref-000001',
            'evidence/manual-ref-000001',
        );

        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $refund->ledgerTransactionId)
            ->orderBy('ledger_account_id')
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->map(static fn (object $row): array => [(int) $row->ledger_account_id, $row->direction, (int) $row->amount_irr])
            ->all();
        $expected = [
            [$externalAssetId, 'credit', 200_000],
            [$revenueId, 'debit', 200_000],
        ];
        sort($entries);
        sort($expected);
        self::assertSame($expected, $entries);
        self::assertSame(0, DB::table('ledger_entries as entries')
            ->join('ledger_accounts as accounts', 'accounts.id', '=', 'entries.ledger_account_id')
            ->where('entries.ledger_transaction_id', $refund->ledgerTransactionId)
            ->whereNotNull('accounts.owner_user_id')
            ->count());

        $audit = (string) DB::table('audit_logs')
            ->where('action', 'wallet.refund.complete')
            ->where('target_id', (string) $refund->refundId)
            ->value('after_safe_data');
        self::assertStringNotContainsString('manual-ref-000001', $audit);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($audit, true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['manual_reference_present']);
        self::assertTrue($decoded['manual_evidence_present']);
        self::assertSame('manual_external', $decoded['destination']);
    }

    public function test_destination_override_requires_owner_permission_and_uses_only_compatible_original_method_entries(): void
    {
        $ownerId = $this->administrator(true);
        $financeId = $this->administrator(false, 'finance');
        $userId = $this->user();
        $walletId = $this->account('wallet.cash.refund.override.'.$userId, 'liability', $userId, 'cash');
        $externalAssetId = $this->account('system.refund.override.external', 'asset');
        $revenueId = $this->account('system.refund.override.revenue', 'revenue');
        $sourceId = $this->refundableSource(
            'override',
            [
                new LedgerEntryDraft($walletId, LedgerDirection::Debit, IrrMoney::positive(400_000)),
                new LedgerEntryDraft($externalAssetId, LedgerDirection::Debit, IrrMoney::positive(600_000)),
                new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(1_000_000)),
            ],
            1_000_000,
            RefundDestination::Wallet,
        );
        $externalEntryId = $this->entryId($sourceId, $externalAssetId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($sourceId, $revenueId, LedgerDirection::Credit);
        $allocations = $this->pair($externalEntryId, $revenueEntryId, 300_000);
        $service = $this->app->make(WalletRefundService::class);

        try {
            $service->refund(
                'refund.override.000001',
                $sourceId,
                RefundDestination::ManualExternal,
                $allocations,
                $this->context($financeId, 'override-finance'),
                'manual-override-000001',
                'evidence/manual-override-000001',
            );
            self::fail('Expected destination override authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('refunds')->count());
            self::assertSame(1, DB::table('ledger_transactions')->count());
        }

        $refund = $service->refund(
            'refund.override.000001',
            $sourceId,
            RefundDestination::ManualExternal,
            $allocations,
            $this->context($ownerId, 'override-owner'),
            'manual-override-000001',
            'evidence/manual-override-000001',
        );

        self::assertTrue($refund->destinationOverridden);
        self::assertSame(RefundDestination::Wallet, $refund->defaultDestination);
        self::assertSame(RefundDestination::ManualExternal, $refund->destination);
        self::assertSame(300_000, $refund->amount->amount);
        self::assertSame(0, DB::table('ledger_entries')
            ->where('ledger_transaction_id', $refund->ledgerTransactionId)
            ->where('ledger_account_id', $walletId)
            ->count());
        $audit = json_decode((string) DB::table('audit_logs')->where('target_id', (string) $refund->refundId)->value('after_safe_data'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($audit['destination_overridden']);
        self::assertSame('wallet', $audit['default_destination']);
        self::assertSame('manual_external', $audit['destination']);
    }

    public function test_changed_replay_conflicts_and_database_guards_keep_refund_history_immutable(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->account('wallet.cash.refund.guard.'.$userId, 'liability', $userId, 'cash');
        $revenueId = $this->account('system.refund.guard.revenue', 'revenue');
        $sourceId = $this->refundableSource(
            'guard',
            [
                new LedgerEntryDraft($walletId, LedgerDirection::Debit, IrrMoney::positive(500_000)),
                new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(500_000)),
            ],
            500_000,
            RefundDestination::Wallet,
        );
        $walletEntryId = $this->entryId($sourceId, $walletId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($sourceId, $revenueId, LedgerDirection::Credit);
        $service = $this->app->make(WalletRefundService::class);
        $refund = $service->refund(
            'refund.guard.000001',
            $sourceId,
            RefundDestination::Wallet,
            $this->pair($walletEntryId, $revenueEntryId, 200_000),
            $this->context($ownerId, 'guard-0001'),
        );

        $this->assertRuntimeMessage(
            'Refund key conflict.',
            fn (): mixed => $service->refund(
                'refund.guard.000001',
                $sourceId,
                RefundDestination::Wallet,
                $this->pair($walletEntryId, $revenueEntryId, 200_001),
                $this->context($ownerId, 'guard-0002'),
            ),
        );

        $allocationId = (int) DB::table('refund_allocations')->where('refund_id', $refund->refundId)->value('id');
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_refundability')->where('ledger_transaction_id', $sourceId)->update(['refundable_total_irr' => 499_999]));
        $this->assertQueryRejected(static fn (): int => DB::table('ledger_refundability')->where('ledger_transaction_id', $sourceId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('refunds')->where('id', $refund->refundId)->update(['amount_irr' => 199_999]));
        $this->assertQueryRejected(static fn (): int => DB::table('refunds')->where('id', $refund->refundId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('refund_allocations')->where('id', $allocationId)->update(['amount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('refund_allocations')->where('id', $allocationId)->delete());

        self::assertSame(500_000, (int) DB::table('ledger_refundability')->where('ledger_transaction_id', $sourceId)->value('refundable_total_irr'));
        self::assertSame(200_000, (int) DB::table('refunds')->where('id', $refund->refundId)->value('amount_irr'));
        self::assertSame(2, DB::table('refund_allocations')->where('refund_id', $refund->refundId)->count());
        self::assertSame(2, DB::table('ledger_transactions')->count());
    }

    public function test_refundability_is_part_of_ledger_command_identity_and_cannot_be_retrofitted(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->account('wallet.cash.refund.identity.'.$userId, 'liability', $userId, 'cash');
        $revenueId = $this->account('system.refund.identity.revenue', 'revenue');
        $entries = [
            new LedgerEntryDraft($walletId, LedgerDirection::Debit, IrrMoney::positive(300_000)),
            new LedgerEntryDraft($revenueId, LedgerDirection::Credit, IrrMoney::positive(300_000)),
        ];
        $snapshot = new LedgerRefundabilitySnapshot(IrrMoney::positive(300_000), RefundDestination::Wallet);
        $ledger = $this->app->make(LedgerPostingService::class);
        $first = $ledger->post(
            'ledger.refund.identity.000001',
            'order_capture',
            'corr-refund-identity-000001',
            $entries,
            'order',
            'identity-order',
            $snapshot,
        );
        $replay = $ledger->post(
            'ledger.refund.identity.000001',
            'order_capture',
            'corr-refund-identity-replay',
            $entries,
            'order',
            'identity-order',
            $snapshot,
        );
        self::assertTrue($replay->replayed);
        self::assertSame($first->transactionId, $replay->transactionId);

        $this->assertRuntimeMessage(
            'Ledger command key conflict.',
            fn (): mixed => $ledger->post(
                'ledger.refund.identity.000001',
                'order_capture',
                'corr-refund-identity-conflict',
                $entries,
                'order',
                'identity-order',
            ),
        );

        $nonRefundable = $ledger->post(
            'ledger.refund.identity.000002',
            'order_capture',
            'corr-refund-identity-000002',
            $entries,
            'order',
            'non-refundable-order',
        );
        $walletEntryId = $this->entryId($nonRefundable->transactionId, $walletId, LedgerDirection::Debit);
        $revenueEntryId = $this->entryId($nonRefundable->transactionId, $revenueId, LedgerDirection::Credit);
        $this->assertDomainMessage(
            'Refund source ledger transaction is not declared refundable.',
            fn (): mixed => $this->app->make(WalletRefundService::class)->refund(
                'refund.identity.000002',
                $nonRefundable->transactionId,
                RefundDestination::Wallet,
                $this->pair($walletEntryId, $revenueEntryId, 100_000),
                $this->context($ownerId, 'identity-0002'),
            ),
        );
        self::assertSame(0, DB::table('refunds')->count());
    }

    /**
     * @param  list<LedgerEntryDraft>  $entries
     */
    private function refundableSource(
        string $suffix,
        array $entries,
        int $refundableTotal,
        RefundDestination $defaultDestination,
    ): int {
        return $this->app->make(LedgerPostingService::class)->post(
            'ledger.refund.source.'.$suffix,
            'order_capture',
            'corr-refund-source-'.$suffix,
            $entries,
            'order',
            'refund-source-'.$suffix,
            new LedgerRefundabilitySnapshot(IrrMoney::positive($refundableTotal), $defaultDestination),
        )->transactionId;
    }

    /** @return list<RefundEntryAllocation> */
    private function pair(int $debitEntryId, int $creditEntryId, int $amount): array
    {
        return [
            new RefundEntryAllocation($debitEntryId, IrrMoney::positive($amount)),
            new RefundEntryAllocation($creditEntryId, IrrMoney::positive($amount)),
        ];
    }

    private function entryId(int $transactionId, int $accountId, LedgerDirection $direction): int
    {
        $entryId = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $transactionId)
            ->where('ledger_account_id', $accountId)
            ->where('direction', $direction->value)
            ->value('id');
        if (! is_int($entryId) && ! is_string($entryId)) {
            throw new RuntimeException('Expected source ledger entry was not found.');
        }

        return (int) $entryId;
    }

    private function administrator(bool $owner = false, ?string $roleCode = null): int
    {
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->user(),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($roleCode !== null) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if (! is_int($roleId) && ! is_string($roleId)) {
                throw new RuntimeException('Expected administrator role was not seeded.');
            }
            DB::table('administrator_role_assignments')->insert([
                'administrator_id' => $administratorId,
                'role_id' => (int) $roleId,
                'granted_by_administrator_id' => null,
                'granted_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $administratorId;
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
    ): int {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $actorAdministratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            'refund-request-'.$suffix,
            'refund-correlation-'.$suffix,
            'refund_test',
            'Refund test reason.',
            $actorAdministratorId,
        );
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
