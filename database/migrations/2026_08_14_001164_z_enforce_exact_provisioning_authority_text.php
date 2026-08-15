<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement BUY-001 PAY-002 PAY-003 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 SEC-002 SEC-008 QUA-004 */
    public function up(): void
    {
        // A deployment that already committed the pre-001162 bootstrap before this
        // hardening still converges to the same byte-exact authority semantics.
        DB::statement('ALTER TABLE service_subscriptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        DB::statement('ALTER TABLE provisioning_operations CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        DB::statement('ALTER TABLE provisioning_operation_histories CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');

        $this->addConstraintIfMissing(
            'payment_intents',
            'payment_intents_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (`purpose` <> 'purchase' OR BINARY `purpose` = BINARY 'purchase')
    AND (
        `state` NOT IN ('captured', 'refund_pending', 'partially_refunded', 'refunded')
        OR BINARY `state` IN (BINARY 'captured', BINARY 'refund_pending', BINARY 'partially_refunded', BINARY 'refunded')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );

        $this->addConstraintIfMissing(
            'orders',
            'orders_provisioning_exact_authority_chk',
            <<<'SQL'
CHECK (
    (`source_type` <> 'purchase' OR BINARY `source_type` = BINARY 'purchase')
    AND (
        `state` NOT IN ('paid', 'provisioning_queued')
        OR BINARY `state` IN (BINARY 'paid', BINARY 'provisioning_queued')
    )
    AND (`currency` <> 'IRR' OR BINARY `currency` = BINARY 'IRR')
)
SQL,
        );

        $outboxEnvelope = require __DIR__.'/2026_08_14_001161_z_harden_initial_provisioning_outbox_envelope.php';
        if (! is_object($outboxEnvelope) || ! method_exists($outboxEnvelope, 'up')) {
            throw new RuntimeException('Exact provisioning Outbox authority guard cannot be installed safely.');
        }
        $outboxEnvelope->up();
    }

    public function down(): void
    {
        foreach ([
            ['orders', 'orders_provisioning_exact_authority_chk'],
            ['payment_intents', 'payment_intents_provisioning_exact_authority_chk'],
        ] as [$table, $constraint]) {
            if ($this->constraintExists($table, $constraint)) {
                DB::statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            }
        }
    }

    private function addConstraintIfMissing(string $table, string $constraint, string $definition): void
    {
        if ($this->constraintExists($table, $constraint)) {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$definition}");
    }

    private function constraintExists(string $table, string $constraint): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'CHECK'],
        );

        return $row !== null && (int) $row->aggregate === 1;
    }
};
