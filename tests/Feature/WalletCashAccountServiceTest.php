<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Wallet\Application\WalletCashAccountService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement WAL-002 DAT-002 DAT-003 SEC-002 QUA-001 */
final class WalletCashAccountServiceTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_self_resolves_exactly_one_active_irr_cash_account_without_exposing_another_user(): void
    {
        $userId = $this->benefitUser('customer');
        $walletId = $this->benefitCashWallet($userId);
        $service = $this->app->make(WalletCashAccountService::class);

        $receipt = $service->forSelf($userId, $userId);
        self::assertSame($walletId, $receipt->accountId);
        self::assertSame(0, $receipt->ledgerBalanceIrr);
        self::assertSame(0, $receipt->activeHoldsIrr);
        self::assertSame(0, $receipt->availableBalanceIrr);

        $otherUser = $this->benefitUser('customer');
        $this->benefitCashWallet($otherUser);
        try {
            $service->forSelf($userId, $otherUser);
            self::fail('Expected cross-user wallet access rejection.');
        } catch (AuthorizationException $exception) {
            self::assertSame('Wallet cash account self access denied.', $exception->getMessage());
        }
    }

    public function test_missing_or_duplicate_cash_account_fails_closed(): void
    {
        $userId = $this->benefitUser('customer');
        $service = $this->app->make(WalletCashAccountService::class);

        try {
            $service->forSelf($userId, $userId);
            self::fail('Expected missing cash account rejection.');
        } catch (DomainException $exception) {
            self::assertSame('Wallet cash account is unavailable.', $exception->getMessage());
        }

        $this->benefitCashWallet($userId);
        try {
            DB::table('ledger_accounts')->insert([
                'code' => 'wallet.user.'.$userId.'.cash.duplicate',
                'account_class' => 'liability',
                'owner_user_id' => $userId,
                'wallet_bucket' => 'cash',
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            self::fail('Expected duplicate cash account database rejection.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('ledger_accounts')
                ->where('owner_user_id', $userId)
                ->where('wallet_bucket', 'cash')
                ->count());
        }
        self::assertGreaterThan(0, $service->forSelf($userId, $userId)->accountId);
    }
}
