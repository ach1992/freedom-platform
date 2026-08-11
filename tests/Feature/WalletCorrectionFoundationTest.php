<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletCorrectionService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001 */
final class WalletCorrectionFoundationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_owner_credit_preview_confirmation_execution_and_exact_replay(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'credit');
        $this->fundWallet($walletId, 1_000_000, 'credit');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.owner.credit.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(250_000),
            'Correct verified opening wallet balance.',
            $this->context($ownerId, 'preview-owner-credit'),
            'ticket',
            'TKT-1001',
        );

        self::assertSame(1_000_000, $preview->ledgerBalance->amount);
        self::assertSame(0, $preview->activeHolds->amount);
        self::assertSame(1_000_000, $preview->availableBalance->amount);
        self::assertSame(1_250_000, $preview->resultingLedgerBalance->amount);
        self::assertSame(1_250_000, $preview->resultingAvailableBalance->amount);
        self::assertFalse($preview->approvalRequired);
        self::assertFalse($preview->replayed);
        self::assertSame(64, strlen($preview->confirmationToken));

        $receipt = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            null,
            $this->context($ownerId, 'execute-owner-credit'),
        );
        self::assertSame(250_000, $receipt->amount->amount);
        self::assertSame(1_000_000, $receipt->ledgerBalanceBefore->amount);
        self::assertSame(1_250_000, $receipt->ledgerBalanceAfter->amount);
        self::assertNull($receipt->approvalId);
        self::assertFalse($receipt->replayed);

        $balance = $this->app->make(WalletHoldService::class)->balance($userId, $walletId);
        self::assertSame(1_250_000, $balance->ledgerBalance->amount);
        self::assertSame(1_250_000, $balance->availableBalance->amount);

        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $receipt->ledgerTransactionId)
            ->orderBy('ledger_account_id')
            ->get(['ledger_account_id', 'direction', 'amount_irr'])
            ->map(static fn (object $row): array => [(int) $row->ledger_account_id, $row->direction, (int) $row->amount_irr])
            ->all();
        self::assertContains([$walletId, 'credit', 250_000], $entries);
        self::assertCount(2, $entries);

        $replay = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            null,
            $this->context($ownerId, 'replay-owner-credit'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->correctionId, $replay->correctionId);
        self::assertSame($receipt->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('wallet_corrections')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.correction.execute')->count());
    }

    public function test_debit_correction_is_compensating_and_cannot_make_available_balance_negative(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'promotional', 'debit');
        $this->fundWallet($walletId, 1_000_000, 'debit');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.owner.debit.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Debit,
            IrrMoney::positive(300_000),
            'Reverse an administrator credit entered in error.',
            $this->context($ownerId, 'preview-owner-debit'),
            'payment',
            'PAY-2001',
        );
        self::assertSame('promotional', $preview->walletBucket);
        self::assertSame(700_000, $preview->resultingLedgerBalance->amount);
        self::assertSame(700_000, $preview->resultingAvailableBalance->amount);

        $receipt = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            null,
            $this->context($ownerId, 'execute-owner-debit'),
        );
        self::assertSame(WalletCorrectionDirection::Debit, $receipt->direction);
        self::assertSame(700_000, $receipt->ledgerBalanceAfter->amount);
        self::assertSame(700_000, $receipt->availableBalanceAfter->amount);

        $this->assertDomainMessage(
            'Wallet correction debit exceeds current available balance.',
            fn (): mixed => $service->preview(
                'correction.owner.debit.000002',
                $userId,
                $walletId,
                WalletCorrectionDirection::Debit,
                IrrMoney::positive(700_001),
                'Attempt to over-correct the wallet.',
                $this->context($ownerId, 'preview-owner-overdebit'),
            ),
        );
        self::assertSame(1, DB::table('wallet_corrections')->count());
    }

    public function test_preview_is_immutable_idempotent_and_fails_closed_when_stale_or_confirmation_changes(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'preview');
        $this->fundWallet($walletId, 500_000, 'preview-initial');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.preview.guard.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(100_000),
            'Preview immutability verification.',
            $this->context($ownerId, 'preview-guard-1'),
        );
        $replay = $service->preview(
            'correction.preview.guard.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(100_000),
            'Preview immutability verification.',
            $this->context($ownerId, 'preview-guard-2'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($preview->previewId, $replay->previewId);
        self::assertSame($preview->confirmationToken, $replay->confirmationToken);

        $this->assertRuntimeMessage(
            'Wallet correction key conflict.',
            fn (): mixed => $service->preview(
                'correction.preview.guard.000001',
                $userId,
                $walletId,
                WalletCorrectionDirection::Credit,
                IrrMoney::positive(100_001),
                'Preview immutability verification.',
                $this->context($ownerId, 'preview-guard-conflict'),
            ),
        );
        $this->assertRuntimeMessage(
            'Wallet correction confirmation does not match the immutable preview.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                str_repeat('0', 64),
                null,
                $this->context($ownerId, 'execute-guard-wrong-token'),
            ),
        );

        $this->fundWallet($walletId, 1, 'preview-stale');
        $this->assertRuntimeMessage(
            'Wallet correction preview is stale; create a new correction preview.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                $preview->confirmationToken,
                null,
                $this->context($ownerId, 'execute-guard-stale'),
            ),
        );
        self::assertSame(0, DB::table('wallet_corrections')->count());
        self::assertSame(1, DB::table('wallet_correction_previews')->count());
    }

    public function test_non_owner_correction_uses_existing_independent_sensitive_approval_and_consumes_it_atomically(): void
    {
        $financeId = $this->administrator(false, 'finance');
        $approverId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'approval');
        $this->fundWallet($walletId, 900_000, 'approval');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.finance.approval.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(200_000),
            'Finance correction requiring independent approval.',
            $this->context($financeId, 'preview-finance-approval'),
            'ticket',
            'TKT-3001',
        );
        self::assertTrue($preview->approvalRequired);

        $this->assertDomainMessage(
            'Wallet correction requires independent approval.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                $preview->confirmationToken,
                null,
                $this->context($financeId, 'execute-finance-no-approval'),
            ),
        );

        $approval = $service->requestApproval(
            $preview->previewId,
            $this->context($financeId, 'request-finance-approval'),
        );
        self::assertFalse($approval->consumed);

        try {
            $this->app->make(SensitiveActionApprovalService::class)->approve(
                $approval->approvalId,
                $this->context($financeId, 'self-approve-finance'),
            );
            self::fail('Expected independent-approval self-approval rejection.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('wallet_corrections')->count());
        }

        $this->app->make(SensitiveActionApprovalService::class)->approve(
            $approval->approvalId,
            $this->context($approverId, 'owner-approve-finance'),
        );
        $receipt = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            $approval->approvalId,
            $this->context($financeId, 'execute-finance-approved'),
        );
        self::assertSame($approval->approvalId, $receipt->approvalId);
        self::assertSame(1_100_000, $receipt->ledgerBalanceAfter->amount);
        self::assertSame('approved', DB::table('sensitive_action_approvals')->where('id', $approval->approvalId)->value('state'));
        self::assertNotNull(DB::table('sensitive_action_approvals')->where('id', $approval->approvalId)->value('consumed_at'));
        self::assertSame($financeId, (int) DB::table('sensitive_action_approvals')->where('id', $approval->approvalId)->value('consumed_by_administrator_id'));
        self::assertSame(1, DB::table('wallet_corrections')->count());
    }

    public function test_policy_threshold_can_allow_small_finance_correction_but_requires_approval_at_large_boundary(): void
    {
        $this->app['config']->set('wallet.corrections.dual_approval_threshold_irr', 500_000);
        $financeId = $this->administrator(false, 'finance');
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'threshold');
        $this->fundWallet($walletId, 1_000_000, 'threshold');
        $service = $this->app->make(WalletCorrectionService::class);

        $small = $service->preview(
            'correction.finance.small.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(100_000),
            'Below configured dual-approval threshold.',
            $this->context($financeId, 'preview-finance-small'),
        );
        self::assertFalse($small->approvalRequired);
        $service->execute(
            $small->previewId,
            $small->confirmationToken,
            null,
            $this->context($financeId, 'execute-finance-small'),
        );

        $large = $service->preview(
            'correction.finance.large.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(500_000),
            'At configured dual-approval threshold.',
            $this->context($financeId, 'preview-finance-large'),
        );
        self::assertTrue($large->approvalRequired);
    }

    public function test_related_reference_validation_and_database_guards_preserve_preview_and_execution_history(): void
    {
        $ownerId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'immutability');
        $this->fundWallet($walletId, 700_000, 'immutability');
        $service = $this->app->make(WalletCorrectionService::class);

        $this->assertDomainMessage(
            'Wallet correction related reference type is invalid.',
            fn (): mixed => $service->preview(
                'correction.invalid.related.000001',
                $userId,
                $walletId,
                WalletCorrectionDirection::Credit,
                IrrMoney::positive(10_000),
                'Invalid related-reference type.',
                $this->context($ownerId, 'preview-invalid-related'),
                'free_text',
                'unsafe-ref',
            ),
        );

        $preview = $service->preview(
            'correction.guard.immutable.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(50_000),
            'Immutable correction record verification.',
            $this->context($ownerId, 'preview-immutable'),
            'order',
            'ORD-4001',
        );
        $receipt = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            null,
            $this->context($ownerId, 'execute-immutable'),
        );

        $this->assertQueryRejected(static fn (): int => DB::table('wallet_correction_previews')->where('id', $preview->previewId)->update(['amount_irr' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_correction_previews')->where('id', $preview->previewId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_corrections')->where('id', $receipt->correctionId)->update(['ledger_transaction_id' => 1]));
        $this->assertQueryRejected(static fn (): int => DB::table('wallet_corrections')->where('id', $receipt->correctionId)->delete());
        self::assertSame(50_000, (int) DB::table('wallet_correction_previews')->where('id', $preview->previewId)->value('amount_irr'));
        self::assertSame($receipt->ledgerTransactionId, (int) DB::table('wallet_corrections')->where('id', $receipt->correctionId)->value('ledger_transaction_id'));
    }

    private function fundWallet(int $walletId, int $amount, string $suffix): void
    {
        $offsetId = $this->systemAccount('system.wallet.test.funding.'.$suffix, 'equity');
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.test.funding.'.$suffix,
            'wallet_test_funding',
            'corr-wallet-test-funding-'.$suffix,
            [
                new LedgerEntryDraft($offsetId, LedgerDirection::Debit, IrrMoney::positive($amount)),
                new LedgerEntryDraft($walletId, LedgerDirection::Credit, IrrMoney::positive($amount)),
            ],
            'test_fixture',
            $suffix,
        );
    }

    private function walletAccount(int $userId, string $bucket, string $suffix): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.'.$bucket.'.correction.'.$suffix.'.'.$userId,
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => $bucket,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function systemAccount(string $code, string $class): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => $code,
            'account_class' => $class,
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function context(int $actorAdministratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            hash('sha256', 'wallet-correction-request:'.$suffix),
            substr(hash('sha256', 'wallet-correction-correlation:'.$suffix), 0, 64),
            'wallet_correction_test',
            'Wallet correction test reason.',
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
