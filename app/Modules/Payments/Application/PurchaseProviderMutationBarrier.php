<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use Closure;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Excludes purchase-provider mutations from financial migration cutovers.
 *
 * Runtime mutations hold one slot from a bounded shared MariaDB named-lock pool.
 * Each entered mutation also owns a separately committed durable attempt record
 * before an external value effect can start. The durable record survives loss of
 * the named-lock session, while the named-lock pool still provides cheap runtime
 * drain/concurrency semantics for a migration.
 */
final readonly class PurchaseProviderMutationBarrier
{
    private const SLOT_COUNT = 128;

    private const LOCK_TIMEOUT_SECONDS = 15;

    private const RUNTIME_LOCK_RETRY_MICROSECONDS = 10_000;

    private const CONTROL_CONNECTION = 'purchase_provider_mutation_control';

    public function __construct(private DatabaseManager $database) {}

    /**
     * @template T
     *
     * @param  Closure(PurchaseProviderMutationAttempt):T  $operation
     * @return T
     */
    public function runForPaymentIntent(int $paymentIntentId, string $mutationKey, Closure $operation): mixed
    {
        if ($paymentIntentId < 1) {
            throw new RuntimeException('Purchase provider mutation payment intent ID must be positive.');
        }
        $this->assertMutationKey($mutationKey);

        $connection = $this->database->connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $operation(PurchaseProviderMutationAttempt::noOp());
        }

        if (! $connection->getSchemaBuilder()->hasTable('purchase_provider_mutation_attempts')) {
            throw new RuntimeException('Purchase provider mutation attempt authority is not installed.');
        }

        $intent = $connection->table('payment_intents')
            ->where('id', $paymentIntentId)
            ->first([
                'public_id',
                'purpose',
                'user_id',
                'source_quote_id',
                'source_quote_public_id',
                'provider_code',
            ]);
        if ($intent === null) {
            throw new RuntimeException('Purchase provider mutation payment intent is unavailable.');
        }
        if ($intent->purpose !== 'purchase') {
            return $operation(PurchaseProviderMutationAttempt::noOp());
        }

        $userId = filter_var($intent->user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $sourceQuoteId = filter_var($intent->source_quote_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false
            || $sourceQuoteId === false
            || ! is_string($intent->public_id)
            || $intent->public_id === ''
            || ! is_string($intent->source_quote_public_id)
            || $intent->source_quote_public_id === ''
            || ! is_string($intent->provider_code)
            || $intent->provider_code === '') {
            throw new RuntimeException('Purchase provider mutation identity is incomplete.');
        }

        $this->assertUpgradeFenceInactive($connection);
        $preferredSlot = $this->slotFor((int) $userId, $intent->source_quote_public_id);

        return $this->withRuntimeSlot(
            $connection,
            $preferredSlot,
            function (string $lockName, int $acquiredSlot) use (
                $connection,
                $paymentIntentId,
                $mutationKey,
                $intent,
                $sourceQuoteId,
                $userId,
                $operation,
            ): mixed {
                // Re-read after slot acquisition to close the race where migration
                // activates the persistent cut after the optimistic fast-path read.
                $this->assertUpgradeFenceInactive($connection);
                $this->assertNoOtherPendingManualReview(
                    $connection,
                    $paymentIntentId,
                    (int) $sourceQuoteId,
                    (int) $userId,
                );

                [$control, $controlName] = $this->controlConnection($connection);
                $attempt = null;

                try {
                    $this->assertNoAbandonedAttempt(
                        $control,
                        $connection,
                        $paymentIntentId,
                        $intent->public_id,
                    );

                    $session = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id', [], false);
                    $providerSessionId = (int) ($session->connection_id ?? 0);
                    if ($providerSessionId < 1) {
                        throw new RuntimeException('Purchase provider mutation database session identity is unavailable.');
                    }

                    $attemptId = (int) $control->table('purchase_provider_mutation_attempts')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'payment_intent_id' => $paymentIntentId,
                        'payment_intent_public_id' => $intent->public_id,
                        'provider_code' => $intent->provider_code,
                        'mutation_key' => $mutationKey,
                        'provider_session_id' => $providerSessionId,
                        'slot' => $acquiredSlot,
                        'state' => 'prepared',
                        'prepared_at' => $control->raw('UTC_TIMESTAMP(6)'),
                        'external_started_at' => null,
                        'resolved_at' => null,
                        'updated_at' => $control->raw('UTC_TIMESTAMP(6)'),
                    ]);
                    if ($attemptId < 1) {
                        throw new RuntimeException('Purchase provider mutation attempt persistence failed.');
                    }

                    $attempt = new PurchaseProviderMutationAttempt(
                        $control,
                        $attemptId,
                        $providerSessionId,
                        $lockName,
                    );
                    $connection->statement(
                        'SET @purchase_provider_mutation_attempt_id := ?',
                        [$attemptId],
                    );

                    try {
                        $result = $operation($attempt);
                        $attempt->complete();

                        return $result;
                    } catch (Throwable $exception) {
                        try {
                            $attempt->fail();
                        } catch (Throwable $stateFailure) {
                            throw new RuntimeException(
                                'Provider mutation failed and its durable reconciliation state could not be advanced.',
                                0,
                                $exception,
                            );
                        }

                        throw $exception;
                    }
                } finally {
                    try {
                        $connection->unprepared('SET @purchase_provider_mutation_attempt_id := NULL');
                    } catch (Throwable) {
                        // Never return a pooled/reused session with a stale capability.
                        $connection->disconnect();
                    }
                    $this->database->purge($controlName);
                }
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

    private function assertMutationKey(string $mutationKey): void
    {
        if ($mutationKey === ''
            || strlen($mutationKey) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $mutationKey) === 1) {
            throw new RuntimeException('Purchase provider mutation key is invalid.');
        }
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

    private function assertNoAbandonedAttempt(
        Connection $authorityConnection,
        Connection $providerConnection,
        int $paymentIntentId,
        string $paymentIntentPublicId,
    ): void {
        $attempts = $authorityConnection->table('purchase_provider_mutation_attempts')
            ->where('payment_intent_id', $paymentIntentId)
            ->where('payment_intent_public_id', $paymentIntentPublicId)
            ->whereIn('state', ['prepared', 'external_started', 'reconciliation_required'])
            ->get(['provider_session_id', 'slot', 'state']);

        foreach ($attempts as $attempt) {
            if ($attempt->state === 'reconciliation_required') {
                throw new DomainException('Purchase provider mutation is locked by unresolved provider reconciliation.');
            }

            $slot = filter_var($attempt->slot, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::SLOT_COUNT - 1]]);
            $session = filter_var($attempt->provider_session_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($slot === false || $session === false) {
                throw new RuntimeException('Purchase provider mutation attempt authority is malformed.');
            }

            $owner = $authorityConnection->selectOne(
                'SELECT IS_USED_LOCK(?) AS owner_connection_id',
                [$this->lockName($providerConnection, (int) $slot)],
                false,
            );
            if ($owner === null || (int) ($owner->owner_connection_id ?? 0) !== (int) $session) {
                throw new DomainException('Purchase provider mutation is locked by unresolved provider reconciliation.');
            }
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

    /** @return array{0:Connection,1:string} */
    private function controlConnection(Connection $providerConnection): array
    {
        $configuration = $providerConnection->getConfig();
        $configuration['name'] = self::CONTROL_CONNECTION;

        $control = $this->database->build($configuration);
        if (! $control instanceof Connection) {
            $this->database->purge(self::CONTROL_CONNECTION);
            throw new RuntimeException('Purchase provider mutation control connection is unavailable.');
        }

        return [$control, self::CONTROL_CONNECTION];
    }

    /**
     * @template T
     *
     * @param  Closure(string,int):T  $operation
     * @return T
     */
    private function withRuntimeSlot(Connection $connection, int $preferredSlot, Closure $operation): mixed
    {
        $reconnector = $this->currentReconnector($connection);
        $this->disableReconnectWhileLocksHeld($connection);
        $lockName = null;
        $acquiredSlot = null;

        try {
            $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;
            while (true) {
                for ($offset = 0; $offset < self::SLOT_COUNT; $offset++) {
                    $slot = ($preferredSlot + $offset) % self::SLOT_COUNT;
                    $candidate = $this->lockName($connection, $slot);
                    $row = $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$candidate], false);
                    if ($row !== null && (int) ($row->acquired ?? 0) === 1) {
                        $lockName = $candidate;
                        $acquiredSlot = $slot;
                        break;
                    }
                }

                if ($lockName !== null && $acquiredSlot !== null) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Purchase provider mutation barrier slot could not be acquired.');
                }

                usleep(self::RUNTIME_LOCK_RETRY_MICROSECONDS);
            }

            return $operation($lockName, $acquiredSlot);
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
