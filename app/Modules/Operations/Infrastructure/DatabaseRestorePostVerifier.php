<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\RestoreCriticalAuthorityIdentity;
use App\Modules\Operations\Application\Contracts\RestorePostRestoreVerifier;
use App\Modules\Operations\Application\Contracts\RestoreRuntimeHealthVerifier;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class DatabaseRestorePostVerifier implements RestorePostRestoreVerifier
{
    public function __construct(
        private DatabaseManager $database,
        private RestoreCriticalAuthorityIdentity $criticalAuthority,
        private RestoreRuntimeHealthVerifier $runtimeHealth,
        private string $migrationsDirectory,
    ) {}

    /** @return array<string, int|bool|string> */
    public function verify(): array
    {
        $connectionName = $this->database->getDefaultConnection();
        $this->database->purge($connectionName);
        $connection = $this->database->connection($connectionName);
        $connection->selectOne('SELECT 1 AS ready');

        $expectedMigrations = $this->expectedMigrations();
        $restoredMigrations = $connection->table('migrations')->pluck('migration')->all();
        $restoredMigrations = array_values(array_filter($restoredMigrations, 'is_string'));
        sort($restoredMigrations, SORT_STRING);

        if ($expectedMigrations !== $restoredMigrations) {
            throw new RuntimeException('The restored schema migration identity is inconsistent.');
        }

        $ledgerViolations = $this->ledgerViolations($connection);
        $orderPaymentViolations = $this->orderPaymentViolations($connection);
        $serviceViolations = $this->serviceViolations($connection);

        if ($ledgerViolations !== 0 || $orderPaymentViolations !== 0 || $serviceViolations !== 0) {
            throw new RuntimeException('The restored domain reconciliation checks failed.');
        }

        $expectedAuthorityFingerprint = $this->criticalAuthority->fingerprint();
        $this->runtimeHealth->verify($expectedAuthorityFingerprint);

        return [
            'schema_migrations' => count($restoredMigrations),
            'ledger_violations' => $ledgerViolations,
            'order_payment_violations' => $orderPaymentViolations,
            'service_violations' => $serviceViolations,
            'runtime_health' => true,
        ];
    }

    /** @return list<string> */
    private function expectedMigrations(): array
    {
        $root = realpath($this->migrationsDirectory);
        if ($root === false || ! is_dir($root) || is_link($this->migrationsDirectory)) {
            throw new RuntimeException('The migration verification directory is unavailable.');
        }

        $files = glob($root.'/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('Migration verification inputs are unavailable.');
        }

        $migrations = array_map(
            static fn (string $file): string => basename($file, '.php'),
            $files,
        );
        sort($migrations, SORT_STRING);

        return $migrations;
    }

    private function ledgerViolations(Connection $connection): int
    {
        $row = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS violations
FROM (
    SELECT transaction_row.id
    FROM ledger_transactions transaction_row
    LEFT JOIN (
        SELECT
            ledger_transaction_id,
            COUNT(*) AS entry_count,
            COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_irr ELSE 0 END), 0) AS debit_total,
            COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_irr ELSE 0 END), 0) AS credit_total
        FROM ledger_entries
        GROUP BY ledger_transaction_id
    ) entry_totals ON entry_totals.ledger_transaction_id = transaction_row.id
    WHERE transaction_row.finalized_at IS NOT NULL
      AND (
          COALESCE(entry_totals.entry_count, 0) < 2
          OR COALESCE(entry_totals.debit_total, 0) <> COALESCE(entry_totals.credit_total, 0)
          OR COALESCE(entry_totals.debit_total, 0) <> transaction_row.expected_total_irr
          OR COALESCE(entry_totals.debit_total, 0) <> transaction_row.posted_debit_irr
          OR COALESCE(entry_totals.credit_total, 0) <> transaction_row.posted_credit_irr
          OR COALESCE(entry_totals.entry_count, 0) <> transaction_row.entry_count
      )
) violation_rows
SQL);

        return $this->violationCount($row);
    }

    private function orderPaymentViolations(Connection $connection): int
    {
        $row = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS violations
FROM orders order_row
LEFT JOIN quotes quote_row ON quote_row.id = order_row.source_quote_id
LEFT JOIN purchase_settlements settlement_row ON settlement_row.id = order_row.purchase_settlement_id
LEFT JOIN payment_intents intent_row ON intent_row.id = order_row.payment_intent_id
LEFT JOIN payment_provider_transactions provider_row ON provider_row.id = settlement_row.provider_transaction_row_id
WHERE order_row.source_type = 'purchase'
  AND (
      (
          order_row.state = 'awaiting_payment'
          AND (
              order_row.state_version <> 0
              OR quote_row.id IS NULL
              OR quote_row.public_id <> order_row.source_quote_public_id
              OR quote_row.user_id <> order_row.user_id
              OR quote_row.configuration_snapshot_hash <> order_row.source_quote_configuration_hash
              OR quote_row.final_price_irr <> order_row.total_amount_irr
              OR quote_row.currency <> order_row.currency
              OR order_row.purchase_settlement_id IS NOT NULL
              OR order_row.purchase_settlement_public_id IS NOT NULL
              OR order_row.payment_intent_id IS NOT NULL
              OR order_row.payment_intent_public_id IS NOT NULL
              OR order_row.settled_amount_irr IS NOT NULL
              OR order_row.paid_at IS NOT NULL
          )
      )
      OR
      (
          order_row.state <> 'awaiting_payment'
          AND (
              quote_row.id IS NULL
              OR settlement_row.id IS NULL
              OR intent_row.id IS NULL
              OR provider_row.id IS NULL
              OR quote_row.public_id <> order_row.source_quote_public_id
              OR quote_row.user_id <> order_row.user_id
              OR quote_row.configuration_snapshot_hash <> order_row.source_quote_configuration_hash
              OR quote_row.final_price_irr <> order_row.total_amount_irr
              OR quote_row.currency <> order_row.currency
              OR settlement_row.payment_intent_id <> order_row.payment_intent_id
              OR settlement_row.user_id <> order_row.user_id
              OR settlement_row.source_quote_id <> order_row.source_quote_id
              OR settlement_row.source_quote_public_id <> order_row.source_quote_public_id
              OR settlement_row.public_id <> order_row.purchase_settlement_public_id
              OR settlement_row.settled_at <> order_row.paid_at
              OR intent_row.public_id <> order_row.payment_intent_public_id
              OR intent_row.purpose <> 'purchase'
              OR intent_row.user_id <> order_row.user_id
              OR intent_row.source_quote_id <> order_row.source_quote_id
              OR intent_row.source_quote_public_id <> order_row.source_quote_public_id
              OR intent_row.source_quote_configuration_hash <> order_row.source_quote_configuration_hash
              OR intent_row.state NOT IN ('captured','refund_pending','refunded','partially_refunded')
              OR intent_row.captured_at IS NULL
              OR intent_row.amount_irr <> order_row.total_amount_irr
              OR settlement_row.amount_irr <> order_row.settled_amount_irr
              OR settlement_row.currency <> order_row.currency
              OR intent_row.currency <> order_row.currency
              OR provider_row.payment_intent_id <> settlement_row.payment_intent_id
              OR provider_row.provider_code <> settlement_row.provider_code
              OR provider_row.provider_transaction_id <> settlement_row.provider_transaction_id
              OR provider_row.evidence_payload_hash <> settlement_row.evidence_payload_hash
              OR provider_row.transaction_status <> 'settled'
              OR provider_row.amount_irr <> settlement_row.amount_irr
              OR provider_row.currency <> settlement_row.currency
              OR provider_row.settled_at <> settlement_row.settled_at
          )
      )
  )
SQL);

        return $this->violationCount($row);
    }

    private function serviceViolations(Connection $connection): int
    {
        $row = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS violations
FROM service_subscriptions service_row
LEFT JOIN orders order_row ON order_row.id = service_row.order_id
LEFT JOIN order_items item_row ON item_row.id = service_row.order_item_id
LEFT JOIN provisioning_operations operation_row
    ON operation_row.service_subscription_id = service_row.id
   AND operation_row.operation_type = 'initial_provision'
LEFT JOIN service_imports import_row
    ON import_row.service_subscription_id = service_row.id
   AND import_row.state = 'attached'
WHERE order_row.id IS NULL
   OR item_row.id IS NULL
   OR item_row.order_id <> service_row.order_id
   OR order_row.user_id <> service_row.user_id
   OR (operation_row.id IS NULL AND import_row.id IS NULL)
   OR (
       operation_row.id IS NOT NULL
       AND (
           operation_row.order_id <> service_row.order_id
           OR operation_row.order_item_id <> service_row.order_item_id
           OR operation_row.user_id <> service_row.user_id
           OR (
               operation_row.state = 'succeeded'
               AND (
                   service_row.remote_service_id IS NULL
                   OR operation_row.remote_service_id IS NULL
                   OR service_row.remote_service_id <> operation_row.remote_service_id
                   OR service_row.provisioned_at IS NULL
               )
           )
       )
   )
   OR (
       import_row.id IS NOT NULL
       AND (
           import_row.order_id <> service_row.order_id
           OR import_row.user_id <> service_row.user_id
           OR import_row.service_target_id <> service_row.service_target_id
           OR import_row.remote_service_id <> service_row.remote_service_id
           OR service_row.provisioned_at IS NULL
       )
   )
SQL);

        return $this->violationCount($row);
    }

    private function violationCount(?object $row): int
    {
        $values = $row === null ? [] : (array) $row;
        $violations = $values['violations'] ?? null;

        if (! is_int($violations) && ! is_string($violations)) {
            throw new RuntimeException('A restore reconciliation query returned an invalid result.');
        }

        $count = filter_var($violations, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($count === false) {
            throw new RuntimeException('A restore reconciliation query returned an invalid count.');
        }

        return $count;
    }
}
