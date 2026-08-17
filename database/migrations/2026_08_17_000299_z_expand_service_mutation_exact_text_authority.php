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
        if (! $this->constraintExists(self::CONSTRAINT)) {
            throw new RuntimeException('Initial provisioning exact-text authority must exist before Service mutation exact-text authority is expanded.');
        }

        $this->replaceConstraint(<<<'SQL'
CHECK (
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
        $this->replaceConstraint(<<<'SQL'
CHECK (
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

    private function replaceConstraint(string $definition): void
    {
        if ($this->constraintExists(self::CONSTRAINT)) {
            DB::statement('ALTER TABLE provisioning_operations DROP CONSTRAINT provisioning_operations_provisioning_exact_text_chk');
        }

        DB::statement('ALTER TABLE provisioning_operations ADD CONSTRAINT provisioning_operations_provisioning_exact_text_chk '.$definition);
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
