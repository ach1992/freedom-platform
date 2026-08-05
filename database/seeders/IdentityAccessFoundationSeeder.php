<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Customers\Domain\CustomerTierCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class IdentityAccessFoundationSeeder extends Seeder
{
    /** @requirement USR-002 USR-003 ACL-001 ACL-002 AGT-002 */
    public function run(): void
    {
        $now = now('UTC');

        DB::table('customer_tiers')->upsert([
            $this->tier(
                CustomerTierCode::New,
                'customer_tiers.new',
                10,
                0,
                0,
                $now,
            ),
            $this->tier(
                CustomerTierCode::Normal,
                'customer_tiers.normal',
                20,
                1,
                0,
                $now,
            ),
            $this->tier(
                CustomerTierCode::Loyal,
                'customer_tiers.loyal',
                30,
                3,
                30,
                $now,
            ),
            $this->tier(
                CustomerTierCode::Vip,
                'customer_tiers.vip',
                40,
                10,
                90,
                $now,
            ),
        ], ['code'], ['name_translation_key', 'sort_order', 'is_active', 'policy', 'updated_at']);

        DB::table('roles')->upsert([
            $this->role('finance', 'roles.finance', $now),
            $this->role('support', 'roles.support', $now),
            $this->role('technical', 'roles.technical', $now),
            $this->role('sales_content', 'roles.sales_content', $now),
        ], ['code'], ['name_translation_key', 'is_system', 'is_active', 'updated_at']);

        DB::table('permissions')->upsert([
            $this->permission('identity.customers.view', 'identity', 'standard', false, $now),
            $this->permission('identity.customers.manage_status', 'identity', 'high', true, $now),
            $this->permission('identity.customers.manage_tier', 'identity', 'high', true, $now),
            $this->permission('identity.customers.manage_tags', 'identity', 'high', true, $now),
            $this->permission('agents.applications.review', 'agents', 'high', true, $now),
            $this->permission('agents.accounts.manage', 'agents', 'high', true, $now),
            $this->permission('access.roles.manage', 'access_control', 'critical', true, $now),
            $this->permission('access.permissions.override', 'access_control', 'critical', true, $now),
            $this->permission('access.sensitive_actions.approve', 'access_control', 'critical', true, $now),
        ], ['code'], ['module', 'risk_level', 'requires_approval', 'updated_at']);
    }

    /** @return array{code: string, name_translation_key: string, sort_order: int, is_active: bool, policy: string, created_at: mixed, updated_at: mixed} */
    private function tier(
        CustomerTierCode $code,
        string $translationKey,
        int $sortOrder,
        int $minimumSuccessfulPurchases,
        int $minimumMembershipDays,
        mixed $now,
    ): array {
        return [
            'code' => $code->value,
            'name_translation_key' => $translationKey,
            'sort_order' => $sortOrder,
            'is_active' => true,
            'policy' => json_encode([
                'automatic' => true,
                'min_successful_purchases' => $minimumSuccessfulPurchases,
                'min_membership_days' => $minimumMembershipDays,
                'total_spend_enabled' => false,
                'min_total_spend_irr' => 0,
                'manual_lock_supported' => true,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array{code: string, name_translation_key: string, is_system: bool, is_active: bool, created_at: mixed, updated_at: mixed} */
    private function role(string $code, string $translationKey, mixed $now): array
    {
        return [
            'code' => $code,
            'name_translation_key' => $translationKey,
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** @return array{code: string, module: string, risk_level: string, requires_approval: bool, created_at: mixed, updated_at: mixed} */
    private function permission(
        string $code,
        string $module,
        string $riskLevel,
        bool $requiresApproval,
        mixed $now,
    ): array {
        return [
            'code' => $code,
            'module' => $module,
            'risk_level' => $riskLevel,
            'requires_approval' => $requiresApproval,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
