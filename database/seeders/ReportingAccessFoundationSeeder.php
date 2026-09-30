<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ReportingAccessFoundationSeeder extends Seeder
{
    /** @requirement REP-001 REP-002 REP-003 ACL-001 ACL-002 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');
        $rows = [];
        foreach (['reports.view', 'reports.export', 'reports.deliver', 'reports.schedule'] as $code) {
            $rows[] = [
                'code' => $code,
                'module' => 'reporting',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('permissions')->upsert(
            $rows,
            ['code'],
            ['module', 'risk_level', 'requires_approval', 'updated_at'],
        );
    }
}
