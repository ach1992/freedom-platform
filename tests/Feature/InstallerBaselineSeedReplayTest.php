<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InstallerBaselineSeedReplayTest extends TestCase
{
    use RefreshDatabase;

    /** @requirement INS-001 ACL-001 QUA-011 */
    public function test_installer_baseline_seed_is_replay_safe(): void
    {
        $this->seed(DatabaseSeeder::class);
        $first = $this->baselineCounts();

        $this->seed(DatabaseSeeder::class);
        $second = $this->baselineCounts();

        self::assertSame($first, $second);
        self::assertGreaterThan(0, $second['roles']);
        self::assertGreaterThan(0, $second['permissions']);
        self::assertGreaterThan(0, $second['role_permissions']);
        self::assertGreaterThan(0, $second['customer_tiers']);
        self::assertGreaterThan(0, $second['support_ticket_categories']);
        self::assertGreaterThan(0, $second['ledger_accounts']);
    }

    /** @return array<string,int> */
    private function baselineCounts(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'permissions' => DB::table('permissions')->count(),
            'role_permissions' => DB::table('role_permissions')->count(),
            'customer_tiers' => DB::table('customer_tiers')->count(),
            'support_ticket_categories' => DB::table('support_ticket_categories')->count(),
            'ledger_accounts' => DB::table('ledger_accounts')->count(),
        ];
    }
}
