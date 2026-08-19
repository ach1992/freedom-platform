<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class ServiceOperationalAuthorityGuard
{
    /** @var array<string, array{ready:string,bootstrap:string}> */
    private const TABLES = [
        'service_imports' => ['ready' => 'service_imports_authority_ready_v1_chk', 'bootstrap' => 'service_imports_bootstrap_block_chk'],
        'service_ownership_transfers' => ['ready' => 'service_transfers_authority_ready_v1_chk', 'bootstrap' => 'service_transfers_bootstrap_block_chk'],
        'service_reconciliation_cases' => ['ready' => 'service_reconciliation_authority_ready_v1_chk', 'bootstrap' => 'service_reconciliation_bootstrap_block_chk'],
        'service_reconciliation_changes' => ['ready' => 'service_reconciliation_changes_authority_ready_v1_chk', 'bootstrap' => 'service_reconciliation_changes_bootstrap_block_chk'],
        'service_batch_grants' => ['ready' => 'service_batch_grants_authority_ready_v1_chk', 'bootstrap' => 'service_batch_grants_bootstrap_block_chk'],
        'service_batch_grant_items' => ['ready' => 'service_batch_items_authority_ready_v1_chk', 'bootstrap' => 'service_batch_items_bootstrap_block_chk'],
    ];

    public function __construct(
        private DatabaseManager $database,
        private ServiceOperationalDatabaseCapability $databaseCapability,
    ) {}

    public function assertFinalized(): void
    {
        $connection = $this->database->connection();
        if (! $connection->getSchemaBuilder()->hasTable('service_operational_authority_capability')) {
            throw new RuntimeException('Service operational authority is not finalized.');
        }
        /** @var object{capability_hash:string}|null $capability */
        $capability = $connection->table('service_operational_authority_capability')->where('id', 1)->first(['capability_hash']);
        if ($capability === null || ! is_string($capability->capability_hash)
            || ! hash_equals($this->databaseCapability->expectedHash(), $capability->capability_hash)) {
            throw new RuntimeException('Service operational database capability is not finalized.');
        }
        /** @var object{guard_count:int|string}|null $capabilityGuards */
        $capabilityGuards = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS guard_count
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN (
      'service_operational_capability_insert_guard',
      'service_operational_capability_update_guard',
      'service_operational_capability_delete_guard'
  )
SQL);
        if ($capabilityGuards === null || (int) $capabilityGuards->guard_count !== 3) {
            throw new RuntimeException('Service operational database capability is not finalized.');
        }
        foreach (self::TABLES as $table => $constraints) {
            /** @var object{ready_count:int|string|null,blocked_count:int|string|null}|null $row */
            $row = $connection->selectOne(<<<'SQL'
SELECT
    SUM(CONSTRAINT_NAME = ?) AS ready_count,
    SUM(CONSTRAINT_NAME = ?) AS blocked_count
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND CONSTRAINT_TYPE = 'CHECK'
  AND CONSTRAINT_NAME IN (?, ?)
SQL, [
                $constraints['ready'],
                $constraints['bootstrap'],
                $table,
                $constraints['ready'],
                $constraints['bootstrap'],
            ]);
            if ($row === null || (int) $row->ready_count !== 1 || (int) $row->blocked_count !== 0) {
                throw new RuntimeException('Service operational authority is not finalized.');
            }
        }

        /** @var object{marker_count:int|string|null}|null $trigger */
        $trigger = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS marker_count
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME = 'service_subscriptions_update_guard'
  AND ACTION_STATEMENT LIKE '%service_import_attach_v1%'
  AND ACTION_STATEMENT LIKE '%service_ownership_transfer_v1%'
  AND ACTION_STATEMENT LIKE '%service_repair_v1%'
SQL);
        if ($trigger === null || (int) $trigger->marker_count !== 1) {
            throw new RuntimeException('Service operational Service-transition authority is not finalized.');
        }

        /** @var object{guard_count:int|string}|null $guards */
        $guards = $connection->selectOne(<<<'SQL'
SELECT COUNT(*) AS guard_count
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND TRIGGER_NAME IN (
      'service_imports_insert_guard','service_imports_update_guard','service_imports_delete_guard',
      'service_ownership_transfers_insert_guard','service_ownership_transfers_update_guard','service_ownership_transfers_delete_guard',
      'service_reconciliation_cases_insert_guard','service_reconciliation_cases_update_guard','service_reconciliation_cases_delete_guard',
      'service_reconciliation_changes_insert_guard','service_reconciliation_changes_update_guard','service_reconciliation_changes_delete_guard',
      'service_batch_grants_insert_guard','service_batch_grants_update_guard','service_batch_grants_delete_guard',
      'service_batch_grant_items_insert_guard','service_batch_grant_items_update_guard','service_batch_grant_items_delete_guard',
      'audit_logs_service_operational_insert_guard'
  )
SQL);
        if ($guards === null || (int) $guards->guard_count !== 19) {
            throw new RuntimeException('Service operational evidence authority is not finalized.');
        }
        foreach ([
            'service_subscriptions_update_guard' => 'operational_capability_count',
            'service_imports_update_guard' => 'source_row.authorization_key',
            'service_reconciliation_cases_update_guard' => 'service_reconciliation_changes change_row',
            'service_batch_grants_update_guard' => 'live_claims',
            'service_batch_grant_items_insert_guard' => 'items_committed_at IS NULL',
            'audit_logs_service_operational_insert_guard' => 'service_operational_authority_capability',
        ] as $triggerName => $marker) {
            /** @var object{action_statement:string}|null $row */
            $row = $connection->selectOne(
                'SELECT ACTION_STATEMENT AS action_statement FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                [$triggerName],
            );
            if ($row === null || ! is_string($row->action_statement) || ! str_contains($row->action_statement, $marker)) {
                throw new RuntimeException('Service operational evidence authority is not finalized.');
            }
        }
    }
}
