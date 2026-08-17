<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'provisioning_operations_provisioning_exact_text_chk';

    /** @requirement SVC-004 PRV-002 PRV-003 DAT-003 SEC-008 QUA-004 */
    public function up(): void
    {
        if (! $this->exactTextSchemaReady()) {
            throw new RuntimeException('Initial provisioning exact-text schema must be active before Service mutation exact-text authority is expanded.');
        }

        // MariaDB DDL commits per statement. Re-entry deliberately repairs a crash between
        // DROP and ADD by treating a missing constraint as a safe state to converge from.
        $this->dropConstraintIfPresent();
        DB::statement(<<<'SQL'
ALTER TABLE provisioning_operations
ADD CONSTRAINT provisioning_operations_provisioning_exact_text_chk CHECK (
    COLLATION(`operation_key`) <> 'utf8mb4_bin'
    OR (
        BINARY `operation_type` IN (
            BINARY 'initial_provision', BINARY 'reset_usage', BINARY 'suspend', BINARY 'activate',
            BINARY 'delete', BINARY 'rotate_subscription_link'
        )
        AND BINARY `operation_key` = BINARY RTRIM(`operation_key`)
        AND BINARY `state` IN (
            BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
            BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
        )
        AND BINARY `correlation_id` = BINARY RTRIM(`correlation_id`)
    )
)
SQL);
    }

    public function down(): void
    {
        $this->dropConstraintIfPresent();
        DB::statement(<<<'SQL'
ALTER TABLE provisioning_operations
ADD CONSTRAINT provisioning_operations_provisioning_exact_text_chk CHECK (
    COLLATION(`operation_key`) <> 'utf8mb4_bin'
    OR (
        BINARY `operation_type` = BINARY 'initial_provision'
        AND BINARY `operation_key` = BINARY RTRIM(`operation_key`)
        AND BINARY `state` IN (
            BINARY 'queued', BINARY 'running', BINARY 'uncertain_remote_result', BINARY 'retry_scheduled',
            BINARY 'succeeded', BINARY 'failed_final', BINARY 'needs_review', BINARY 'compensating', BINARY 'compensated'
        )
        AND BINARY `correlation_id` = BINARY RTRIM(`correlation_id`)
    )
)
SQL);
    }

    private function exactTextSchemaReady(): bool
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'provisioning_operations'
  AND COLLATION_NAME = 'utf8mb4_bin'
  AND COLUMN_NAME IN ('operation_key', 'operation_type', 'state', 'correlation_id')
SQL);

        return $row !== null && (int) $row->aggregate === 4;
    }

    private function dropConstraintIfPresent(): void
    {
        if ($this->constraintExists(self::CONSTRAINT)) {
            DB::statement('ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_provisioning_exact_text_chk');
        }
    }

    private function constraintExists(string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['provisioning_operations', $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
