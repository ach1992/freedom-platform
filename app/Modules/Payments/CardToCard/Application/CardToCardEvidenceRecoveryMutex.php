<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class CardToCardEvidenceRecoveryMutex
{
    public const LOCK_NAME = 'freedom:c2c-evidence-recovery:v1';

    private const WAIT_SECONDS = 10;

    public function __construct(private DatabaseManager $database) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function synchronized(Closure $operation): mixed
    {
        $connection = $this->database->connection();
        $acquired = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::LOCK_NAME, self::WAIT_SECONDS],
        );
        if ($acquired === null || (int) $acquired->acquired !== 1) {
            throw new RuntimeException('C2C evidence recovery mutex could not be acquired.');
        }

        $result = null;
        $failure = null;
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $released = null;
        $releaseFailure = null;
        try {
            $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        } catch (Throwable $exception) {
            $releaseFailure = $exception;
        }
        if ($failure !== null) {
            throw $failure;
        }
        if ($releaseFailure !== null) {
            throw new RuntimeException('C2C evidence recovery mutex release failed.', previous: $releaseFailure);
        }
        if ($released === null || (int) $released->released !== 1) {
            throw new RuntimeException('C2C evidence recovery mutex could not be released.');
        }

        return $result;
    }

    public function assertHeldByCurrentConnection(Connection $connection): void
    {
        $owner = $connection->selectOne('SELECT IS_USED_LOCK(?) AS owner_connection_id', [self::LOCK_NAME]);
        $current = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id');
        if ($owner === null
            || $current === null
            || $owner->owner_connection_id === null
            || (int) $owner->owner_connection_id !== (int) $current->connection_id) {
            throw new RuntimeException('C2C evidence recovery requires the canonical recovery mutex.');
        }
    }
}
