<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SupportTicketCategorySeeder extends Seeder
{
    /** @requirement SUP-001 CNT-001 */
    public function run(): void
    {
        $now = now('UTC');
        DB::table('support_ticket_categories')->insertOrIgnore([
            $this->category('purchase_payment', 'خرید و پرداخت', 'Purchase and payment', 10, $now),
            $this->category('technical_service', 'مشکل فنی سرویس', 'Technical service issue', 20, $now),
            $this->category('renewal_data', 'تمدید و حجم', 'Renewal and data', 30, $now),
            $this->category('account_wallet', 'حساب و کیف پول', 'Account and wallet', 40, $now),
            $this->category('agent_cooperation', 'نمایندگی و همکاری', 'Agent and cooperation', 50, $now),
            $this->category('other', 'سایر موارد', 'Other', 60, $now),
        ]);
    }

    /** @return array<string, bool|int|string|Carbon> */
    private function category(string $code, string $nameFa, string $nameEn, int $sortOrder, Carbon $now): array
    {
        return [
            'code' => $code,
            'name_fa' => $nameFa,
            'name_en' => $nameEn,
            'route_role_code' => null,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
