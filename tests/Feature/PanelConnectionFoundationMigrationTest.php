<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** @requirement PRV-001 SEC-001 DAT-003 QUA-001 */
final class PanelConnectionFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_uses_encrypted_credentials_and_fk_backed_history(): void
    {
        foreach (['panel_mutation_receipts', 'panel_connections', 'panel_connection_histories'] as $table) {
            self::assertTrue(Schema::hasTable($table), "Expected {$table} table.");
        }

        self::assertTrue(Schema::hasColumn('panel_connections', 'encrypted_credentials'));
        self::assertFalse(Schema::hasColumn('panel_connections', 'credentials'));
        self::assertTrue(Schema::hasColumn('panel_connections', 'credential_key_version'));
        self::assertTrue(Schema::hasColumn('panel_connection_histories', 'panel_connection_id'));
        self::assertTrue(Schema::hasColumn('panel_connection_histories', 'actor_administrator_id'));
    }

    public function test_database_rejects_unknown_provider(): void
    {
        $row = $this->validConnectionRow('invalid-provider');
        $row['provider_type'] = 'unknown';

        $this->expectException(QueryException::class);
        DB::table('panel_connections')->insert($row);
    }

    public function test_database_rejects_mismatched_tls_material(): void
    {
        $row = $this->validConnectionRow('invalid-tls');
        $row['custom_ca_disk'] = 'private';
        $row['custom_ca_path'] = 'panel-cas/core.pem';

        $this->expectException(QueryException::class);
        DB::table('panel_connections')->insert($row);
    }

    public function test_database_rejects_active_connection_without_successful_test_evidence(): void
    {
        $row = $this->validConnectionRow('untested-active');
        $row['state'] = 'active';

        $this->expectException(QueryException::class);
        DB::table('panel_connections')->insert($row);
    }

    /** @return array<string, mixed> */
    private function validConnectionRow(string $code): array
    {
        $now = now('UTC');

        return [
            'code' => $code,
            'provider_type' => 'fake',
            'name_fa' => 'پنل',
            'name_en' => 'Panel',
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
        ];
    }
}
