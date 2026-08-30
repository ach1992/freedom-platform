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
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $triggerName) !== 1) {
            throw new RuntimeException('Database trigger snapshot requires a simple trusted identifier.');
        }

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

        $create = DB::selectOne('SHOW CREATE TRIGGER `'.$triggerName.'`');
        $createStatement = $create->{'SQL Original Statement'} ?? null;
        if (! is_string($createStatement) || trim($createStatement) === '') {
            throw new RuntimeException('Database trigger create statement is unavailable: '.$triggerName);
        }

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

    private function databaseTriggerExists(string $triggerName): bool
    {
        return DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TRIGGER_NAME', $triggerName)
            ->exists();
    }
}
