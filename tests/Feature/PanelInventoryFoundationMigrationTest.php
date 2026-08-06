<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement CAT-002 CAT-004 PRV-001 SEC-001 DAT-003 QUA-001 */
final class PanelInventoryFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_separates_profiles_targets_capabilities_and_sales_servers(): void
    {
        foreach ([
            'panel_protocol_profiles',
            'panel_service_targets',
            'panel_target_capabilities',
            'panel_target_protocol_profiles',
            'sales_servers',
            'panel_protocol_profile_histories',
            'panel_service_target_histories',
            'sales_server_histories',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected {$table} table.");
        }

        self::assertTrue(Schema::hasColumn('panel_service_targets', 'encrypted_configuration'));
        self::assertFalse(Schema::hasColumn('panel_service_targets', 'configuration'));
        self::assertTrue(Schema::hasColumn('panel_target_protocol_profiles', 'customer_selectable'));
    }

    public function test_database_rejects_active_target_without_adapter_verification_workflow(): void
    {
        $connectionId = $this->connection('active-target-guard');
        $row = $this->targetRow($connectionId, 'target-active-guard');
        $row['state'] = 'active';
        $row['capability_status'] = 'verified';
        $row['capability_evidence_hash'] = hash('sha256', 'evidence');
        $row['capability_verified_at'] = now('UTC');
        $row['verified_connection_version'] = 1;

        $this->expectException(QueryException::class);
        DB::table('panel_service_targets')->insert($row);
    }

    public function test_database_rejects_listed_server_that_is_not_active(): void
    {
        $now = now('UTC');

        $this->expectException(QueryException::class);
        DB::table('sales_servers')->insert([
            'code' => 'disabled-listed',
            'name_fa' => 'سرور',
            'name_en' => null,
            'description_fa' => null,
            'description_en' => null,
            'state' => 'disabled',
            'visibility' => 'listed',
            'sort_order' => 0,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_connection_and_profile_archival_are_dependency_safe(): void
    {
        $connectionId = $this->connection('dependency-connection');
        $profileId = $this->profile('dependency-profile');
        $targetId = (int) DB::table('panel_service_targets')->insertGetId(
            $this->targetRow($connectionId, 'dependency-target'),
        );
        $now = now('UTC');
        DB::table('panel_target_protocol_profiles')->insert([
            'panel_service_target_id' => $targetId,
            'panel_protocol_profile_id' => $profileId,
            'customer_selectable' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('panel_connections')->where('id', $connectionId)->update(['state' => 'archived']);
            self::fail('Expected panel connection dependency guard.');
        } catch (QueryException) {
            self::assertSame('disabled', DB::table('panel_connections')->where('id', $connectionId)->value('state'));
        }

        $this->expectException(QueryException::class);
        DB::table('panel_protocol_profiles')->where('id', $profileId)->update(['state' => 'archived']);
    }

    private function connection(string $code): int
    {
        $now = now('UTC');

        return (int) DB::table('panel_connections')->insertGetId([
            'code' => $code,
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
    }

    private function profile(string $code): int
    {
        $now = now('UTC');

        return (int) DB::table('panel_protocol_profiles')->insertGetId([
            'code' => $code,
            'name_fa' => 'پروفایل',
            'name_en' => null,
            'protocol_family' => 'vless',
            'transport' => 'ws',
            'security_layer' => 'tls',
            'host' => null,
            'sni' => null,
            'path' => '/vpn',
            'port' => 443,
            'flow' => null,
            'state' => 'disabled',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function targetRow(int $connectionId, string $code): array
    {
        $now = now('UTC');

        return [
            'panel_connection_id' => $connectionId,
            'code' => $code,
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
        ];
    }
}
