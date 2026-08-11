<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @requirement CAT-002 CAT-003 CAT-004 SEC-002 DAT-003 QUA-001 */
final class PlanOfferingFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_separates_offering_policies_packages_and_history(): void
    {
        foreach ([
            'plan_offerings',
            'plan_offering_tiers',
            'plan_offering_tags',
            'plan_offering_protocol_profiles',
            'plan_offering_required_capabilities',
            'plan_offering_operations',
            'plan_offering_packages',
            'plan_offering_histories',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected {$table} table.");
        }

        self::assertTrue(Schema::hasColumn('plan_offerings', 'base_price_irr'));
        self::assertTrue(Schema::hasColumn('plan_offerings', 'data_allowance_bytes'));
        self::assertFalse(Schema::hasColumn('products', 'price_irr'));
    }

    public function test_database_rejects_invalid_money_limits_and_direct_active_insert(): void
    {
        $dependencies = $this->dependencies();
        $invalid = $this->offeringRow($dependencies);
        $invalid['base_price_irr'] = -1;

        try {
            DB::table('plan_offerings')->insert($invalid);
            self::fail('Expected negative IRR rejection.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('plan_offerings')->count());
        }

        $active = $this->offeringRow($dependencies);
        $active['code'] = 'direct-active';
        $active['state'] = 'active';

        $this->expectException(QueryException::class);
        DB::table('plan_offerings')->insert($active);
    }

    public function test_non_archived_offering_blocks_product_archival(): void
    {
        $dependencies = $this->dependencies();
        DB::table('plan_offerings')->insert($this->offeringRow($dependencies));

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $dependencies['product_id'])->update([
            'state' => 'archived',
            'visibility' => 'hidden',
        ]);
    }

    public function test_children_require_draft_state_and_history_is_append_only(): void
    {
        $dependencies = $this->dependencies();
        $offeringId = (int) DB::table('plan_offerings')->insertGetId($this->offeringRow($dependencies));
        DB::table('plan_offering_tiers')->insert([
            'plan_offering_id' => $offeringId,
            'tier_code' => 'vip',
            'created_at' => now('UTC'),
        ]);
        DB::table('plan_offerings')->where('id', $offeringId)->update([
            'state' => 'archived',
            'version' => 2,
        ]);

        try {
            DB::table('plan_offering_packages')->insert([
                'plan_offering_id' => $offeringId,
                'code' => 'late-package',
                'package_type' => 'add_data',
                'name_fa' => 'حجم',
                'name_en' => null,
                'price_irr' => 1000,
                'duration_days' => null,
                'data_bytes' => 1024,
                'discount_eligible' => true,
                'sort_order' => 0,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            self::fail('Expected archived child insertion rejection.');
        } catch (QueryException) {
            self::assertSame(0, DB::table('plan_offering_packages')->count());
        }

        $administratorId = $this->administrator();
        $historyId = (int) DB::table('plan_offering_histories')->insertGetId([
            'plan_offering_id' => $offeringId,
            'version' => 1,
            'action' => 'test',
            'from_state' => null,
            'to_state' => 'draft',
            'from_visibility' => null,
            'to_visibility' => 'hidden',
            'from_configuration_hash' => null,
            'to_configuration_hash' => hash('sha256', 'configuration'),
            'before_safe_data' => null,
            'after_safe_data' => json_encode(['state' => 'draft'], JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $administratorId,
            'reason_code' => 'test',
            'reason' => 'test history',
            'correlation_id' => 'correlation-plan-offering-history',
            'created_at' => now('UTC'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('plan_offering_histories')->where('id', $historyId)->update(['action' => 'changed']);
    }

    /** @return array{product_id: int, server_id: int, target_id: int} */
    private function dependencies(): array
    {
        $now = now('UTC');
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'parent_id' => null,
            'code' => 'offering-category-'.Str::lower(Str::random(6)),
            'name_fa' => 'دسته',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'category_id' => $categoryId,
            'code' => 'offering-product-'.Str::lower(Str::random(6)),
            'name_fa' => 'محصول',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'active',
            'visibility' => 'visible',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $serverId = (int) DB::table('sales_servers')->insertGetId([
            'code' => 'offering-server-'.Str::lower(Str::random(6)),
            'name_fa' => 'سرور',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'disabled',
            'visibility' => 'hidden',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $connectionId = (int) DB::table('panel_connections')->insertGetId([
            'code' => 'offering-connection-'.Str::lower(Str::random(6)),
            'provider_type' => 'fake',
            'name_fa' => 'پنل',
            'name_en' => null,
            'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => 'ciphertext',
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'disabled',
            'last_test_status' => null,
            'last_panel_version' => null,
            'last_capabilities_hash' => null,
            'last_tested_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetId = (int) DB::table('panel_service_targets')->insertGetId([
            'panel_connection_id' => $connectionId,
            'code' => 'offering-target-'.Str::lower(Str::random(6)),
            'kind' => 'inbound',
            'name_fa' => 'هدف',
            'name_en' => null,
            'encrypted_configuration' => 'ciphertext',
            'configuration_hash' => hash('sha256', 'configuration'),
            'configuration_key_version' => 1,
            'state' => 'disabled',
            'capability_status' => 'declared',
            'capability_evidence_hash' => null,
            'capability_verified_at' => null,
            'verified_connection_version' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['product_id' => $productId, 'server_id' => $serverId, 'target_id' => $targetId];
    }

    /**
     * @param  array{product_id: int, server_id: int, target_id: int}  $dependencies
     * @return array<string, mixed>
     */
    private function offeringRow(array $dependencies): array
    {
        $now = now('UTC');

        return [
            'code' => 'offering-row-'.Str::lower(Str::random(6)),
            'product_id' => $dependencies['product_id'],
            'variant_id' => null,
            'sales_server_id' => $dependencies['server_id'],
            'panel_service_target_id' => $dependencies['target_id'],
            'service_mode_code' => 'shared',
            'service_mode_label_fa' => 'اشتراکی',
            'service_mode_label_en' => 'Shared',
            'audience' => 'both',
            'server_selection_mode' => 'customer_selects',
            'protocol_selection_mode' => 'fixed',
            'tag_match_mode' => 'all',
            'base_price_irr' => 1000,
            'duration_days' => 30,
            'data_allowance_bytes' => null,
            'device_limit' => null,
            'sort_order' => 0,
            'min_purchase_quantity' => 1,
            'max_purchase_quantity' => 1,
            'discount_eligible' => true,
            'auto_renew_allowed' => false,
            'custom_plan_allowed' => false,
            'trial_allowed' => false,
            'state' => 'draft',
            'visibility' => 'hidden',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function administrator(): int
    {
        $now = now('UTC');
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => 'fa',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => true,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
