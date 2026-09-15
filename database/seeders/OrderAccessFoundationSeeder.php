<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class OrderAccessFoundationSeeder extends Seeder
{
    /** @requirement ADM-002 ACL-001 ACL-002 SEC-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('permissions')->upsert([
            [
                'code' => 'services.grant_single',
                'module' => 'services',
                'risk_level' => 'high',
                'requires_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);
    }
}
