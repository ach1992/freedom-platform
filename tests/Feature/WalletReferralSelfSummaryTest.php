<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\Application\ReferralAttributionService;
use App\Modules\Promotions\Application\ReferralSelfSummaryService;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Modules\Wallet\Application\WalletSelfBalanceService;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement USR-001 WAL-002 REF-001 SEC-003 DAT-003 */
final class WalletReferralSelfSummaryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_wallet_self_summary_reuses_authoritative_hold_aware_balances_without_provisioning_or_mutation(): void
    {
        $userId = $this->user();
        $assetId = $this->account('self-summary.asset', 'asset');
        $cashId = $this->account('wallet.user.'.$userId.'.cash', 'liability', $userId, 'cash');
        $promotionalId = $this->account('wallet.user.'.$userId.'.promotional', 'liability', $userId, 'promotional');
        $ledger = $this->app->make(LedgerPostingService::class);
        $ledger->post(
            'self-summary-cash-credit',
            'wallet_topup_capture',
            'self-summary-cash-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(1_000_000)),
                new LedgerEntryDraft($cashId, LedgerDirection::Credit, IrrMoney::positive(1_000_000)),
            ],
        );
        $ledger->post(
            'self-summary-promo-credit',
            'wallet_promotion_credit',
            'self-summary-promo-correlation',
            [
                new LedgerEntryDraft($assetId, LedgerDirection::Debit, IrrMoney::positive(250_000)),
                new LedgerEntryDraft($promotionalId, LedgerDirection::Credit, IrrMoney::positive(250_000)),
            ],
        );
        $this->app->make(WalletHoldService::class)->place(
            'self-summary-cash-hold',
            $userId,
            $cashId,
            IrrMoney::positive(200_000),
            'self_summary',
            'cash-hold',
            now('UTC')->addHour()->toDateTimeImmutable(),
        );

        $before = $this->walletMutationCounts();
        $summary = $this->app->make(WalletSelfBalanceService::class)->forSelf($userId, $userId);

        self::assertTrue($summary->cashAccountExists);
        self::assertSame(1_000_000, $summary->cashLedgerBalanceIrr);
        self::assertSame(200_000, $summary->cashActiveHoldsIrr);
        self::assertSame(800_000, $summary->cashAvailableBalanceIrr);
        self::assertTrue($summary->promotionalAccountExists);
        self::assertSame(250_000, $summary->promotionalLedgerBalanceIrr);
        self::assertSame(0, $summary->promotionalActiveHoldsIrr);
        self::assertSame(250_000, $summary->promotionalAvailableBalanceIrr);
        self::assertSame($before, $this->walletMutationCounts());
    }

    public function test_wallet_self_summary_represents_absent_buckets_as_zero_without_creating_accounts_and_denies_cross_user_read(): void
    {
        $userId = $this->user();
        $otherUserId = $this->user();
        $before = $this->walletMutationCounts();

        $summary = $this->app->make(WalletSelfBalanceService::class)->forSelf($userId, $userId);
        self::assertFalse($summary->cashAccountExists);
        self::assertSame(0, $summary->cashLedgerBalanceIrr);
        self::assertSame(0, $summary->cashActiveHoldsIrr);
        self::assertSame(0, $summary->cashAvailableBalanceIrr);
        self::assertFalse($summary->promotionalAccountExists);
        self::assertSame(0, $summary->promotionalLedgerBalanceIrr);
        self::assertSame(0, $summary->promotionalActiveHoldsIrr);
        self::assertSame(0, $summary->promotionalAvailableBalanceIrr);
        self::assertSame($before, $this->walletMutationCounts());

        $this->expectException(AuthorizationException::class);
        $this->app->make(WalletSelfBalanceService::class)->forSelf($userId, $otherUserId);
    }

    public function test_referral_self_summary_exposes_only_own_public_identity_and_bounded_inviter_state_without_mutation(): void
    {
        $inviterId = $this->user();
        $userId = $this->user();
        $attribution = $this->app->make(ReferralAttributionService::class);
        $inviterToken = $attribution->identityForUser($inviterId);
        $ownToken = $attribution->identityForUser($userId);
        $attribution->bind($userId, $inviterToken);

        $before = $this->referralMutationCounts();
        $summary = $this->app->make(ReferralSelfSummaryService::class)->forSelf($userId, $userId);

        self::assertSame($ownToken, $summary->referralToken);
        self::assertNotSame($inviterToken, $summary->referralToken);
        self::assertTrue($summary->hasInviter);
        self::assertFalse($summary->locked);
        self::assertNotNull($summary->boundAt);
        self::assertNull($summary->lockedAt);
        self::assertSame(
            ['referralToken', 'hasInviter', 'locked', 'boundAt', 'lockedAt'],
            array_keys(get_object_vars($summary)),
        );
        self::assertSame($before, $this->referralMutationCounts());
    }

    public function test_referral_self_summary_without_inviter_is_stable_and_denies_cross_user_read(): void
    {
        $userId = $this->user();
        $otherUserId = $this->user();
        $ownToken = DB::table('referral_identities')->where('user_id', $userId)->value('token');
        self::assertIsString($ownToken);
        $before = $this->referralMutationCounts();

        $summary = $this->app->make(ReferralSelfSummaryService::class)->forSelf($userId, $userId);
        self::assertSame($ownToken, $summary->referralToken);
        self::assertFalse($summary->hasInviter);
        self::assertFalse($summary->locked);
        self::assertNull($summary->boundAt);
        self::assertNull($summary->lockedAt);
        self::assertSame($before, $this->referralMutationCounts());

        $this->expectException(AuthorizationException::class);
        $this->app->make(ReferralSelfSummaryService::class)->forSelf($userId, $otherUserId);
    }

    /** @return array<string,int> */
    private function walletMutationCounts(): array
    {
        return [
            'accounts' => DB::table('ledger_accounts')->count(),
            'transactions' => DB::table('ledger_transactions')->count(),
            'entries' => DB::table('ledger_entries')->count(),
            'holds' => DB::table('wallet_holds')->count(),
        ];
    }

    /** @return array<string,int> */
    private function referralMutationCounts(): array
    {
        return [
            'identities' => DB::table('referral_identities')->count(),
            'relationships' => DB::table('referral_relationships')->count(),
            'events' => DB::table('referral_attribution_events')->count(),
        ];
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
}
