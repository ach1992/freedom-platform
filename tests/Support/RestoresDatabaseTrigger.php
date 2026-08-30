<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait RestoresDatabaseTrigger
{
    /**
     * Run a callback with one MariaDB trigger disabled, then restore the exact
     * current trigger body and relative action order captured before the test.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withDatabaseTriggerDisabled(string $triggerName, callable $callback): mixed
    {
        $snapshot = $this->snapshotDatabaseTrigger($triggerName);

        DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS `'.$triggerName.'`');

        try {
            return $callback();
        } finally {
            $this->restoreDatabaseTrigger($snapshot);
        }
    }

    /**
     * @return array{
     *     name: string,
     *     create_statement: string,
     *     previous_trigger: ?string,
     *     next_trigger: ?string
     * }
     */
    private function snapshotDatabaseTrigger(string $triggerName): array
    {
        $this->assertSimpleDatabaseIdentifier($triggerName);

        $databaseName = DB::connection()->getDatabaseName();
        $trigger = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $databaseName)
            ->where('TRIGGER_NAME', $triggerName)
            ->first([
                'EVENT_OBJECT_TABLE as event_object_table',
                'EVENT_MANIPULATION as event_manipulation',
                'ACTION_TIMING as action_timing',
                'ACTION_ORDER as action_order',
            ]);
        if ($trigger === null) {
            throw new RuntimeException('Database trigger snapshot target is unavailable: '.$triggerName);
        }

        $createStatement = $this->databaseTriggerCreateStatement($triggerName);
        $siblings = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $databaseName)
            ->where('EVENT_OBJECT_TABLE', (string) $trigger->event_object_table)
            ->where('EVENT_MANIPULATION', (string) $trigger->event_manipulation)
            ->where('ACTION_TIMING', (string) $trigger->action_timing);

        $previousTrigger = (clone $siblings)
            ->where('ACTION_ORDER', '<', (int) $trigger->action_order)
            ->orderByDesc('ACTION_ORDER')
            ->value('TRIGGER_NAME');
        $nextTrigger = (clone $siblings)
            ->where('ACTION_ORDER', '>', (int) $trigger->action_order)
            ->orderBy('ACTION_ORDER')
            ->value('TRIGGER_NAME');

        return [
            'name' => $triggerName,
            'create_statement' => $createStatement,
            'previous_trigger' => is_string($previousTrigger) ? $previousTrigger : null,
            'next_trigger' => is_string($nextTrigger) ? $nextTrigger : null,
        ];
    }

    /**
     * @return list<array{
     *     name: string,
     *     create_statement: string,
     *     event_manipulation: string,
     *     action_timing: string,
     *     action_order: int
     * }>
     */
    private function snapshotDatabaseTriggersForTable(string $tableName): array
    {
        $this->assertSimpleDatabaseIdentifier($tableName);

        $rows = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', $tableName)
            ->orderBy('EVENT_MANIPULATION')
            ->orderBy('ACTION_TIMING')
            ->orderBy('ACTION_ORDER')
            ->get(['TRIGGER_NAME', 'EVENT_MANIPULATION', 'ACTION_TIMING', 'ACTION_ORDER']);

        $snapshots = [];
        foreach ($rows as $row) {
            $triggerName = (string) $row->TRIGGER_NAME;
            $this->assertSimpleDatabaseIdentifier($triggerName);
            $snapshots[] = [
                'name' => $triggerName,
                'create_statement' => $this->databaseTriggerCreateStatement($triggerName),
                'event_manipulation' => (string) $row->EVENT_MANIPULATION,
                'action_timing' => (string) $row->ACTION_TIMING,
                'action_order' => (int) $row->ACTION_ORDER,
            ];
        }

        return $snapshots;
    }

    /**
     * @param  array{
     *     name: string,
     *     create_statement: string,
     *     previous_trigger: ?string,
     *     next_trigger: ?string
     * }  $snapshot
     */
    private function restoreDatabaseTrigger(array $snapshot): void
    {
        $ordering = null;
        if ($snapshot['next_trigger'] !== null && $this->databaseTriggerExists($snapshot['next_trigger'])) {
            $ordering = 'PRECEDES `'.$snapshot['next_trigger'].'`';
        } elseif ($snapshot['previous_trigger'] !== null && $this->databaseTriggerExists($snapshot['previous_trigger'])) {
            $ordering = 'FOLLOWS `'.$snapshot['previous_trigger'].'`';
        }

        $statement = $snapshot['create_statement'];
        if ($ordering !== null) {
            $count = 0;
            $statement = preg_replace(
                '/\bFOR\s+EACH\s+ROW\b/i',
                'FOR EACH ROW'.PHP_EOL.$ordering,
                $statement,
                1,
                $count,
            );
            if (! is_string($statement) || $count !== 1) {
                throw new RuntimeException('Database trigger ordering cannot be restored: '.$snapshot['name']);
            }
        }

        DB::connection()->getPdo()->exec($statement);
    }

    /**
     * @param  list<array{
     *     name: string,
     *     create_statement: string,
     *     event_manipulation: string,
     *     action_timing: string,
     *     action_order: int
     * }>  $snapshots
     */
    private function restoreDatabaseTriggersForTable(string $tableName, array $snapshots): void
    {
        $this->assertSimpleDatabaseIdentifier($tableName);

        $currentTriggerNames = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', $tableName)
            ->pluck('TRIGGER_NAME');
        foreach ($currentTriggerNames as $triggerName) {
            if (! is_string($triggerName)) {
                throw new RuntimeException('Database trigger inventory contains an invalid trigger name.');
            }
            $this->assertSimpleDatabaseIdentifier($triggerName);
            DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS `'.$triggerName.'`');
        }

        usort($snapshots, static function (array $left, array $right): int {
            return [$left['event_manipulation'], $left['action_timing'], $left['action_order'], $left['name']]
                <=> [$right['event_manipulation'], $right['action_timing'], $right['action_order'], $right['name']];
        });

        foreach ($snapshots as $snapshot) {
            $this->assertSimpleDatabaseIdentifier($snapshot['name']);
            if (trim($snapshot['create_statement']) === '') {
                throw new RuntimeException('Database trigger create statement cannot be empty.');
            }
            DB::connection()->getPdo()->exec($snapshot['create_statement']);
        }
    }

    private function databaseTriggerCreateStatement(string $triggerName): string
    {
        $create = DB::selectOne('SHOW CREATE TRIGGER `'.$triggerName.'`');
        $createStatement = $create->{'SQL Original Statement'} ?? null;
        if (! is_string($createStatement) || trim($createStatement) === '') {
            throw new RuntimeException('Database trigger create statement is unavailable: '.$triggerName);
        }

        return $createStatement;
    }

    private function databaseTriggerExists(string $triggerName): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $triggerName)
            ->exists();
    }

    private function assertSimpleDatabaseIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $identifier) !== 1) {
            throw new RuntimeException('Database trigger snapshot requires a simple trusted identifier.');
        }
    }
}
