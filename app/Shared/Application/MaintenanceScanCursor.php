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
     * Each scan cycle is bounded by a persisted high-water ID captured before selection.
     * Newer IDs cannot extend an active cycle indefinitely, so a claimed/unprocessed row
     * or an older row that becomes eligible after the cursor passed it is revisited after
     * a finite wrap. Existing per-effect idempotency remains responsible for safe retries.
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
                'cycle_max_id' => null,
                'updated_at' => now('UTC'),
            ]);

            /** @var object{last_scanned_id:int|string|null,cycle_max_id:int|string|null}|null $cursor */
            $cursor = $connection->table('maintenance_scan_cursors')
                ->where('cursor_name', $cursorName)
                ->lockForUpdate()
                ->first(['last_scanned_id', 'cycle_max_id']);
            if ($cursor === null) {
                throw new RuntimeException('Maintenance scan cursor is unavailable.');
            }

            $lastScannedId = $cursor->last_scanned_id === null
                ? null
                : $this->positiveDatabaseInt($cursor->last_scanned_id, 'Maintenance cursor ID');
            $cycleMaxId = $cursor->cycle_max_id === null
                ? null
                : $this->positiveDatabaseInt($cursor->cycle_max_id, 'Maintenance cycle maximum ID');
            if (($lastScannedId === null) !== ($cycleMaxId === null)) {
                throw new RuntimeException('Maintenance scan cursor cycle state is inconsistent.');
            }
            if ($lastScannedId !== null && $cycleMaxId !== null && $lastScannedId > $cycleMaxId) {
                throw new RuntimeException('Maintenance scan cursor exceeds its cycle boundary.');
            }

            $select = [...$columns, $cursorColumn.' as maintenance_cursor_id'];
            if ($cycleMaxId === null) {
                $cycleMaxId = $this->eligibleMaximum(
                    $connection,
                    $eligibleQuery,
                    $cursorColumn,
                );
                if ($cycleMaxId === null) {
                    return [];
                }
            }

            $rows = $this->cycleRows(
                $connection,
                $eligibleQuery,
                $cursorColumn,
                $select,
                $limit,
                $cycleMaxId,
                $lastScannedId,
            );

            if ($rows === [] && $lastScannedId !== null) {
                $cycleMaxId = $this->eligibleMaximum(
                    $connection,
                    $eligibleQuery,
                    $cursorColumn,
                );
                if ($cycleMaxId === null) {
                    $this->persistCursorState($connection, $cursorName, null, null);

                    return [];
                }

                $rows = $this->cycleRows(
                    $connection,
                    $eligibleQuery,
                    $cursorColumn,
                    $select,
                    $limit,
                    $cycleMaxId,
                    null,
                );
            }

            if ($rows === []) {
                return [];
            }

            $nextScannedId = $this->positiveDatabaseInt(
                $rows[array_key_last($rows)]->maintenance_cursor_id,
                'Maintenance next cursor ID',
            );
            if ($nextScannedId > $cycleMaxId) {
                throw new RuntimeException('Maintenance scan cursor selected beyond its cycle boundary.');
            }

            $this->persistCursorState(
                $connection,
                $cursorName,
                $nextScannedId,
                $cycleMaxId,
            );

            foreach ($rows as $row) {
                unset($row->maintenance_cursor_id);
            }

            return $rows;
        }, 3);
    }

    /**
     * @param  Closure(Connection): Builder  $eligibleQuery
     */
    private function eligibleMaximum(
        Connection $connection,
        Closure $eligibleQuery,
        string $cursorColumn,
    ): ?int {
        $query = $eligibleQuery($connection);
        if (! $query instanceof Builder) {
            throw new RuntimeException('Maintenance eligible query is invalid.');
        }

        $maximum = $query->max($cursorColumn);
        if ($maximum === null) {
            return null;
        }

        return $this->positiveDatabaseInt($maximum, 'Maintenance cycle maximum ID');
    }

    /**
     * @param  Closure(Connection): Builder  $eligibleQuery
     * @param  list<string>  $select
     * @return list<object{maintenance_cursor_id:int|string}>
     */
    private function cycleRows(
        Connection $connection,
        Closure $eligibleQuery,
        string $cursorColumn,
        array $select,
        int $limit,
        int $cycleMaxId,
        ?int $lastScannedId,
    ): array {
        $query = $eligibleQuery($connection);
        if (! $query instanceof Builder) {
            throw new RuntimeException('Maintenance eligible query is invalid.');
        }

        $query->where($cursorColumn, '<=', $cycleMaxId);
        if ($lastScannedId !== null) {
            $query->where($cursorColumn, '>', $lastScannedId);
        }

        /** @var list<object{maintenance_cursor_id:int|string}> $rows */
        $rows = $query
            ->orderBy($cursorColumn)
            ->limit($limit)
            ->get($select)
            ->all();

        return $rows;
    }

    private function persistCursorState(
        Connection $connection,
        string $cursorName,
        ?int $lastScannedId,
        ?int $cycleMaxId,
    ): void {
        $updated = $connection->table('maintenance_scan_cursors')
            ->where('cursor_name', $cursorName)
            ->update([
                'last_scanned_id' => $lastScannedId,
                'cycle_max_id' => $cycleMaxId,
                'updated_at' => now('UTC'),
            ]);
        if ($updated === 1) {
            return;
        }

        /** @var object{last_scanned_id:int|string|null,cycle_max_id:int|string|null}|null $current */
        $current = $connection->table('maintenance_scan_cursors')
            ->where('cursor_name', $cursorName)
            ->first(['last_scanned_id', 'cycle_max_id']);
        if ($updated !== 0
            || $current === null
            || ! $this->databaseIntMatches($current->last_scanned_id, $lastScannedId, 'Maintenance persisted cursor ID')
            || ! $this->databaseIntMatches($current->cycle_max_id, $cycleMaxId, 'Maintenance persisted cycle maximum ID')
        ) {
            throw new RuntimeException('Maintenance scan cursor lost its current authority.');
        }
    }

    private function databaseIntMatches(mixed $persisted, ?int $expected, string $label): bool
    {
        if ($expected === null) {
            return $persisted === null;
        }
        if ($persisted === null) {
            return false;
        }

        return $this->positiveDatabaseInt($persisted, $label) === $expected;
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
