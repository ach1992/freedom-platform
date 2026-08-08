<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class WalletFinancialFoundationSeeder extends Seeder
{
    public const CORRECTION_OFFSET_ACCOUNT_CODE = 'system.wallet.correction.offset';

    /** @requirement WAL-005 DAT-002 DAT-003 DAT-004 */
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
        ], ['code'], ['account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active', 'updated_at']);
    }
}
