<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class WalletFinancialFoundationSeeder extends Seeder
{
    public const CORRECTION_OFFSET_ACCOUNT_CODE = WalletSystemAccountCode::CORRECTION_OFFSET;

    public const EXTERNAL_TOP_UP_CLEARING_ACCOUNT_CODE = WalletSystemAccountCode::EXTERNAL_TOP_UP_CLEARING;

    public const REFERRAL_REWARD_EXPENSE_ACCOUNT_CODE = WalletSystemAccountCode::REFERRAL_REWARD_EXPENSE;

    /** @requirement WAL-001 WAL-002 WAL-005 DAT-002 DAT-003 DAT-004 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('ledger_accounts')->upsert([
            [
                'code' => self::CORRECTION_OFFSET_ACCOUNT_CODE,
                'account_class' => 'equity',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => self::EXTERNAL_TOP_UP_CLEARING_ACCOUNT_CODE,
                'account_class' => 'asset',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => self::REFERRAL_REWARD_EXPENSE_ACCOUNT_CODE,
                'account_class' => 'expense',
                'owner_user_id' => null,
                'wallet_bucket' => null,
                'currency' => 'IRR',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active', 'updated_at']);
    }
}
