<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement ONB-001 USR-001 AGT-001 ACL-001 QUA-011 */
final class IdentityAccessTemporalMigrationCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_access_domain_instants_use_explicit_microsecond_datetime_columns(): void
    {
        $expectedColumns = [
            'telegram_accounts' => ['first_seen_at', 'last_seen_at'],
            'administrators' => ['last_authenticated_at'],
            'customer_tag_assignments' => ['assigned_at', 'removed_at'],
            'phone_numbers' => ['verified_at', 'released_at'],
            'otp_challenges' => ['expires_at', 'consumed_at', 'invalidated_at'],
            'administrator_role_assignments' => ['granted_at', 'revoked_at'],
            'sensitive_action_approvals' => ['expires_at', 'decided_at'],
            'agent_applications' => ['submitted_at', 'claimed_at', 'decided_at'],
            'agent_profiles' => ['approved_at', 'suspended_at'],
        ];

        $database = DB::connection()->getDatabaseName();

        foreach ($expectedColumns as $table => $columns) {
            foreach ($columns as $column) {
                $metadata = DB::selectOne(
                    <<<'SQL'
                    SELECT DATA_TYPE AS data_type,
                           DATETIME_PRECISION AS datetime_precision
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = ?
                      AND TABLE_NAME = ?
                      AND COLUMN_NAME = ?
                    SQL,
                    [$database, $table, $column],
                );

                $this->assertNotNull($metadata, $table.'.'.$column.' must exist.');
                $this->assertSame('datetime', $metadata->data_type, $table.'.'.$column.' must avoid implicit TIMESTAMP defaults.');
                $this->assertSame(6, (int) $metadata->datetime_precision, $table.'.'.$column.' must retain microsecond precision.');
            }
        }
    }
}
