<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 BUY-002 DAT-002 DAT-003 SEC-002 QUA-001 QUA-004 */
    public function up(): void
    {
        // Keep known-but-unimplemented source identities schema-compatible. Creation remains
        // fail-closed in the Order insert guards until an authoritative implementation exists.
        $this->replaceConstraint(
            'orders',
            'orders_source_type_chk',
            "CHECK (`source_type` IN ('purchase','trial','gift','service_code','benefit_code','admin_grant'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (
        `source_type` NOT IN ('purchase','trial','gift','service_code','benefit_code','admin_grant')
        OR BINARY `source_type` IN (
            BINARY 'purchase', BINARY 'trial', BINARY 'gift', BINARY 'service_code',
            BINARY 'benefit_code', BINARY 'admin_grant'
        )
    )
    AND (
        `state` NOT IN ('authorized','paid','provisioning_queued')
        OR BINARY `state` IN (BINARY 'authorized', BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );
    }

    public function down(): void
    {
        $this->replaceConstraint(
            'orders',
            'orders_source_type_chk',
            "CHECK (`source_type` IN ('purchase','trial','benefit_code','admin_grant'))",
        );
        $this->replaceConstraint(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (
        `source_type` NOT IN ('purchase','trial','benefit_code','admin_grant')
        OR BINARY `source_type` IN (
            BINARY 'purchase', BINARY 'trial', BINARY 'benefit_code', BINARY 'admin_grant'
        )
    )
    AND (
        `state` NOT IN ('authorized','paid','provisioning_queued')
        OR BINARY `state` IN (BINARY 'authorized', BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );
    }

    private function replaceConstraint(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
