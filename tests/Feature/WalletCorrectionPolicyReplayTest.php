<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletCorrectionService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001 */
final class WalletCorrectionPolicyReplayTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_execution_fails_closed_when_large_correction_policy_changes_after_preview(): void
    {
        $this->app['config']->set('wallet.corrections.dual_approval_threshold_irr', 500_000);
        $financeId = $this->administrator(false, 'finance');
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'policy-drift');
        $this->fundWallet($walletId, 1_000_000, 'policy-drift');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.policy.drift.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(100_000),
            'Preview before a stricter approval policy is activated.',
            $this->context($financeId, 'preview-policy-drift'),
        );
        self::assertFalse($preview->approvalRequired);

        $this->app['config']->set('wallet.corrections.dual_approval_threshold_irr', 50_000);

        $this->assertRuntimeMessage(
            'Wallet correction approval policy changed; create a new correction preview.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                $preview->confirmationToken,
                null,
                $this->context($financeId, 'execute-policy-drift'),
            ),
        );
        self::assertSame(0, DB::table('wallet_corrections')->count());
        self::assertSame(1_000_000, $this->walletLedgerBalance($walletId));
    }

    public function test_approved_correction_replay_requires_the_exact_committed_approval_id(): void
    {
        $financeId = $this->administrator(false, 'finance');
        $approverId = $this->administrator(true);
        $userId = $this->user();
        $walletId = $this->walletAccount($userId, 'cash', 'approval-replay');
        $this->fundWallet($walletId, 800_000, 'approval-replay');
        $service = $this->app->make(WalletCorrectionService::class);

        $preview = $service->preview(
            'correction.approval.replay.000001',
            $userId,
            $walletId,
            WalletCorrectionDirection::Credit,
            IrrMoney::positive(200_000),
            'Verify exact approval binding on replay.',
            $this->context($financeId, 'preview-approval-replay'),
        );
        self::assertTrue($preview->approvalRequired);

        $approval = $service->requestApproval(
            $preview->previewId,
            $this->context($financeId, 'request-approval-replay'),
        );
        $this->app->make(SensitiveActionApprovalService::class)->approve(
            $approval->approvalId,
            $this->context($approverId, 'approve-approval-replay'),
        );
        $receipt = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            $approval->approvalId,
            $this->context($financeId, 'execute-approval-replay'),
        );
        self::assertFalse($receipt->replayed);

        $this->assertDomainMessage(
            'Wallet correction requires independent approval.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                $preview->confirmationToken,
                null,
                $this->context($financeId, 'replay-approval-missing'),
            ),
        );
        $this->assertRuntimeMessage(
            'Wallet correction approval replay conflicts with the committed approval.',
            fn (): mixed => $service->execute(
                $preview->previewId,
                $preview->confirmationToken,
                '01ARZ3NDEKTSV4RRFFQ69G5FAV',
                $this->context($financeId, 'replay-approval-conflict'),
            ),
        );

        $replay = $service->execute(
            $preview->previewId,
            $preview->confirmationToken,
            $approval->approvalId,
            $this->context($financeId, 'replay-approval-exact'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($receipt->correctionId, $replay->correctionId);
        self::assertSame($receipt->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('wallet_corrections')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'wallet.correction.execute')->count());
    }

    private function fundWallet(int $walletId, int $amount, string $suffix): void
    {
        $offsetId = $this->systemAccount('system.wallet.correction.policy.funding.'.$suffix, 'equity');
        $this->app->make(LedgerPostingService::class)->post(
            'ledger.wallet.correction.policy.funding.'.$suffix,
            'wallet_test_funding',
            substr(hash('sha256', 'wallet-correction-policy-funding:'.$suffix), 0, 64),
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
            'code' => 'wallet.'.$bucket.'.correction.policy.'.$suffix.'.'.$userId,
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
            hash('sha256', 'wallet-correction-policy-request:'.$suffix),
            substr(hash('sha256', 'wallet-correction-policy-correlation:'.$suffix), 0, 64),
            'wallet_correction_policy_test',
            'Wallet correction policy and replay test reason.',
            $actorAdministratorId,
        );
    }

    private function walletLedgerBalance(int $walletId): int
    {
        $credits = (int) DB::table('ledger_entries as entries')
            ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
            ->where('entries.ledger_account_id', $walletId)
            ->where('entries.direction', LedgerDirection::Credit->value)
            ->whereNotNull('transactions.finalized_at')
            ->sum('entries.amount_irr');
        $debits = (int) DB::table('ledger_entries as entries')
            ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
            ->where('entries.ledger_account_id', $walletId)
            ->where('entries.direction', LedgerDirection::Debit->value)
            ->whereNotNull('transactions.finalized_at')
            ->sum('entries.amount_irr');

        return $credits - $debits;
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
}
