<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Closure;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;

final readonly class MaintenanceScanCursor
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * Claim the next bounded round-robin batch before processing it.
     *
     * Advancing the durable cursor before callers process the returned rows prevents a
     * permanently failing front row from starving the remaining eligible population.
     * Existing per-effect idempotency remains responsible for safe retries after wrap.
     *
     * @param  Closure(Connection): Builder  $eligibleQuery
     * @param  list<string>  $columns
     * @return list<object>
     */
    public function claim(
        string $cursorName,
        int $limit,
        Closure $eligibleQuery,
        string $cursorColumn,
        array $columns,
    ): array {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,127}\z/', $cursorName) !== 1) {
            throw new DomainException('Maintenance cursor name is invalid.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Maintenance cursor limit must be between 1 and 500.');
        }
        if (preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*\z/', $cursorColumn) !== 1) {
            throw new DomainException('Maintenance cursor column is invalid.');
        }
        if ($columns === []) {
            throw new DomainException('Maintenance cursor columns are required.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $cursorName,
            $limit,
            $eligibleQuery,
            $cursorColumn,
            $columns,
        ): array {
            $connection->table('maintenance_scan_cursors')->insertOrIgnore([
                'cursor_name' => $cursorName,
                'last_scanned_id' => null,
                'updated_at' => now('UTC'),
            ]);

            /** @var object{last_scanned_id:int|string|null}|null $cursor */
            $cursor = $connection->table('maintenance_scan_cursors')
                ->where('cursor_name', $cursorName)
                ->lockForUpdate()
                ->first(['last_scanned_id']);
            if ($cursor === null) {
                throw new RuntimeException('Maintenance scan cursor is unavailable.');
            }

            $lastScannedId = $cursor->last_scanned_id === null
                ? null
                : $this->positiveDatabaseInt($cursor->last_scanned_id, 'Maintenance cursor ID');
            $select = [...$columns, $cursorColumn.' as maintenance_cursor_id'];

            $firstQuery = $eligibleQuery($connection);
            if (! $firstQuery instanceof Builder) {
                throw new RuntimeException('Maintenance eligible query is invalid.');
            }
            if ($lastScannedId !== null) {
                $firstQuery->where($cursorColumn, '>', $lastScannedId);
            }

            /** @var list<object{maintenance_cursor_id:int|string}> $rows */
            $rows = $firstQuery
                ->orderBy($cursorColumn)
                ->limit($limit)
                ->get($select)
                ->all();

            if ($lastScannedId !== null && count($rows) < $limit) {
                $remaining = $limit - count($rows);
                $wrappedQuery = $eligibleQuery($connection);
                if (! $wrappedQuery instanceof Builder) {
                    throw new RuntimeException('Maintenance wrapped eligible query is invalid.');
                }
                /** @var list<object{maintenance_cursor_id:int|string}> $wrapped */
                $wrapped = $wrappedQuery
                    ->where($cursorColumn, '<=', $lastScannedId)
                    ->orderBy($cursorColumn)
                    ->limit($remaining)
                    ->get($select)
                    ->all();
                $rows = [...$rows, ...$wrapped];
            }

            if ($rows === []) {
                return [];
            }

            $nextScannedId = $this->positiveDatabaseInt(
                $rows[array_key_last($rows)]->maintenance_cursor_id,
                'Maintenance next cursor ID',
            );
            $cursorUpdate = [
                'last_scanned_id' => $nextScannedId,
                'updated_at' => now('UTC'),
            ];
            if ($lastScannedId === null) {
                $updated = $connection->table('maintenance_scan_cursors')
                    ->where('cursor_name', $cursorName)
                    ->whereNull('last_scanned_id')
                    ->update($cursorUpdate);
            } else {
                $updated = $connection->table('maintenance_scan_cursors')
                    ->where('cursor_name', $cursorName)
                    ->where('last_scanned_id', $lastScannedId)
                    ->update($cursorUpdate);
            }

            if ($updated !== 1) {
                $current = $connection->table('maintenance_scan_cursors')
                    ->where('cursor_name', $cursorName)
                    ->value('last_scanned_id');
                if ($updated !== 0
                    || $current === null
                    || $this->positiveDatabaseInt($current, 'Maintenance persisted cursor ID') !== $nextScannedId
                ) {
                    throw new RuntimeException('Maintenance scan cursor lost its current authority.');
                }
            }

            foreach ($rows as $row) {
                unset($row->maintenance_cursor_id);
            }

            return $rows;
        }, 3);
    }

    private function positiveDatabaseInt(mixed $value, string $label): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new RuntimeException($label.' must be positive.');
            }

            return $value;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new RuntimeException($label.' is malformed.');
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new RuntimeException($label.' exceeds the supported integer range.');
        }

        return (int) $value;
    }
}
