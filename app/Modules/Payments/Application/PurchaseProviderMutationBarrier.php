<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use Closure;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Excludes purchase-provider mutations from financial migration cutovers.
 *
 * Runtime mutations hold one slot from a bounded shared pool, starting from a
 * deterministic purchase-specific preference and probing other slots when it is
 * occupied. A migration activates its durable database fence first and then
 * acquires the whole pool before trusting financial state. That drains provider
 * effects already in flight while later entrants fail closed on the fence.
 */
final readonly class PurchaseProviderMutationBarrier
{
    private const SLOT_COUNT = 128;

    private const LOCK_TIMEOUT_SECONDS = 15;

    private const RUNTIME_LOCK_RETRY_MICROSECONDS = 10_000;

    public function __construct(private DatabaseManager $database) {}

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function runForPaymentIntent(int $paymentIntentId, Closure $operation): mixed
    {
        if ($paymentIntentId < 1) {
            throw new RuntimeException('Purchase provider mutation payment intent ID must be positive.');
        }

        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $operation();
        }

        $intent = $connection->table('payment_intents')
            ->where('id', $paymentIntentId)
            ->first(['purpose', 'user_id', 'source_quote_id', 'source_quote_public_id']);
        if ($intent === null) {
            throw new RuntimeException('Purchase provider mutation payment intent is unavailable.');
        }
        if ($intent->purpose !== 'purchase') {
            return $operation();
        }

        $userId = filter_var($intent->user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sourceQuoteId = filter_var($intent->source_quote_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false
            || $sourceQuoteId === false
            || ! is_string($intent->source_quote_public_id)
            || $intent->source_quote_public_id === '') {
            throw new RuntimeException('Purchase provider mutation identity is incomplete.');
        }

        $this->assertUpgradeFenceInactive($connection);
        $slot = $this->slotFor((int) $userId, $intent->source_quote_public_id);

        return $this->withRuntimeSlot(
            $connection,
            $slot,
            function () use ($connection, $paymentIntentId, $sourceQuoteId, $userId, $operation): mixed {
                // The fence is re-read after slot acquisition to close the race where
                // migration activates it between the optimistic fast-path read above
                // and this runtime invocation entering the shared-capacity barrier.
                $this->assertUpgradeFenceInactive($connection);
                $this->assertNoOtherPendingManualReview(
                    $connection,
                    $paymentIntentId,
                    (int) $sourceQuoteId,
                    (int) $userId,
                );

                return $operation();
            },
        );
    }

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    public function blockAll(Closure $operation): mixed
    {
        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $operation();
        }

        $locks = [];
        for ($slot = 0; $slot < self::SLOT_COUNT; $slot++) {
            $locks[] = $this->lockName($connection, $slot);
        }

        return $this->withLocks($connection, $locks, $operation);
    }

    private function slotFor(int $userId, string $sourceQuotePublicId): int
    {
        $prefix = substr(hash('sha256', $userId."\0".$sourceQuotePublicId), 0, 8);

        return (int) (hexdec($prefix) % self::SLOT_COUNT);
    }

    private function lockName(Connection $connection, int $slot): string
    {
        $database = $connection->getDatabaseName();
        if ($database === '') {
            throw new RuntimeException('Purchase provider mutation barrier requires an exact database name.');
        }

        return sprintf(
            'purchase-provider:%s:%03d',
            substr(hash('sha256', $database), 0, 16),
            $slot,
        );
    }

    private function assertUpgradeFenceInactive(Connection $connection): void
    {
        if (! $connection->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence')) {
            return;
        }

        if ($connection->table('nowpayments_terminal_conflict_upgrade_fence')
            ->where('id', 1)
            ->where('active', 1)
            ->exists()) {
            throw new RuntimeException('Purchase provider mutation is blocked by an active financial migration.');
        }
    }

    private function assertNoOtherPendingManualReview(
        Connection $connection,
        int $paymentIntentId,
        int $sourceQuoteId,
        int $userId,
    ): void {
        if ($connection->table('payment_intents')
            ->where('purpose', 'purchase')
            ->where('source_quote_id', $sourceQuoteId)
            ->where('user_id', $userId)
            ->where('state', 'pending_manual_review')
            ->where('id', '<>', $paymentIntentId)
            ->exists()) {
            throw new DomainException('Purchase payment is locked by unresolved payment reconciliation.');
        }
    }

    /**
     * @template T
     *
     * @param  Closure():T  $operation
     * @return T
     */
    private function withRuntimeSlot(Connection $connection, int $preferredSlot, Closure $operation): mixed
    {
        $reconnector = $this->currentReconnector($connection);
        $this->disableReconnectWhileLocksHeld($connection);
        $lockName = null;

        try {
            $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
            while (true) {
                for ($offset = 0; $offset < self::SLOT_COUNT; $offset++) {
                    $slot = ($preferredSlot + $offset) % self::SLOT_COUNT;
                    $candidate = $this->lockName($connection, $slot);
                    $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$candidate], false);
                    if ($row !== null && (int) ($row->acquired ?? 0) === 1) {
                        $lockName = $candidate;
                        break;
                    }
                }

                if ($lockName !== null) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Purchase provider mutation barrier slot could not be acquired.');
                }

                usleep(self::RUNTIME_LOCK_RETRY_MICROSECONDS);
            }

            return $operation();
        } finally {
            $cleanupFailure = $this->releaseLocks($connection, $lockName === null ? [] : [$lockName]);
            $connection->setReconnector($reconnector);

            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    /**
     * @template T
     *
     * @param  list<string>  $lockNames
     * @param  Closure():T  $operation
     * @return T
     */
    private function withLocks(Connection $connection, array $lockNames, Closure $operation): mixed
    {
        $acquired = [];
        $reconnector = $this->currentReconnector($connection);
        $this->disableReconnectWhileLocksHeld($connection);

        try {
            foreach ($lockNames as $lockName) {
                $row = $connection->selectOne(
                    'SELECT GET_LOCK(?, ?) AS acquired',
                    [$lockName, self::LOCK_TIMEOUT_SECONDS],
                    false,
                );
                if ($row === null || (int) ($row->acquired ?? 0) !== 1) {
                    throw new RuntimeException('Purchase provider mutation barrier lock could not be acquired.');
                }
                $acquired[] = $lockName;
            }

            return $operation();
        } finally {
            $cleanupFailure = $this->releaseLocks($connection, array_reverse($acquired));
            $connection->setReconnector($reconnector);

            if ($cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    /** @param list<string> $lockNames */
    private function releaseLocks(Connection $connection, array $lockNames): ?RuntimeException
    {
        $cleanupFailure = null;
        foreach ($lockNames as $lockName) {
            try {
                $released = $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName], false);
                if ($released === null || (int) ($released->released ?? 0) !== 1) {
                    throw new RuntimeException('Purchase provider mutation barrier lock release was not exact.');
                }
            } catch (Throwable $exception) {
                $connection->disconnect();
                $cleanupFailure ??= new RuntimeException(
                    'Purchase provider mutation barrier lock cleanup failed.',
                    0,
                    $exception,
                );
            }
        }

        return $cleanupFailure;
    }

    private function disableReconnectWhileLocksHeld(Connection $connection): void
    {
        $connection->setReconnector(static function (Connection $connection): never {
            throw new RuntimeException('Purchase provider mutation barrier database session was lost while locks were held.');
        });
    }

    private function currentReconnector(Connection $connection): callable
    {
        // Laravel exposes a setter but no getter. Preserve the framework callback
        // exactly so the temporary fail-closed session policy does not leak beyond
        // the advisory-lock critical section.
        $property = new ReflectionProperty(Connection::class, 'reconnector');
        $reconnector = $property->getValue($connection);
        if (! is_callable($reconnector)) {
            throw new RuntimeException('Purchase provider mutation barrier database reconnector is unavailable.');
        }

        return $reconnector;
    }
}
