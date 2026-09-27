<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\OperationalAlertRecorder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseOperationalAlertRecorder implements OperationalAlertRecorder
{
    /** @var list<string> */
    private const FORBIDDEN_CONTEXT_KEY_PARTS = [
        'authorization',
        'body',
        'credential',
        'password',
        'phone',
        'private',
        'secret',
        'token',
    ];

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, bool|float|int|string|null>  $safeContext
     */
    public function raise(
        string $severity,
        string $eventName,
        string $deduplicationKey,
        string $correlationId,
        array $safeContext = [],
    ): void {
        $this->assertSeverity($severity);
        $this->assertEventName($eventName);
        $this->assertDeduplicationKey($deduplicationKey);
        $this->assertCorrelationId($correlationId);
        $encodedContext = $this->encodeSafeContext($safeContext);

        $this->database->connection()->transaction(function (Connection $connection) use (
            $severity,
            $eventName,
            $deduplicationKey,
            $correlationId,
            $encodedContext,
        ): void {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $inserted = $connection->table('alerts')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'severity' => $severity,
                'event_name' => $eventName,
                'deduplication_key' => $deduplicationKey,
                'correlation_id' => $correlationId,
                'safe_context' => $encodedContext,
                'occurrence_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'acknowledged_at' => null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 1) {
                return;
            }

            /** @var object{id:string,occurrence_count:int|string,acknowledged_at:?string,resolved_at:?string}|null $existing */
            $existing = $connection->table('alerts')
                ->where('event_name', $eventName)
                ->where('deduplication_key', $deduplicationKey)
                ->lockForUpdate()
                ->first(['id', 'occurrence_count', 'acknowledged_at', 'resolved_at']);
            if ($existing === null) {
                throw new InvalidArgumentException('Operational alert deduplication conflict could not be reconciled.');
            }

            $reopened = $existing->resolved_at !== null;
            $connection->table('alerts')
                ->where('id', $existing->id)
                ->update([
                    'severity' => $severity,
                    'correlation_id' => $correlationId,
                    'safe_context' => $encodedContext,
                    'occurrence_count' => (int) $existing->occurrence_count + ($reopened ? 1 : 0),
                    'last_seen_at' => $now,
                    'acknowledged_at' => $reopened ? null : $existing->acknowledged_at,
                    'resolved_at' => null,
                    'updated_at' => $now,
                ]);
        }, 3);
    }

    public function resolve(string $eventName, string $deduplicationKey): void
    {
        $this->assertEventName($eventName);
        $this->assertDeduplicationKey($deduplicationKey);

        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->database->connection()->table('alerts')
            ->where('event_name', $eventName)
            ->where('deduplication_key', $deduplicationKey)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function assertSeverity(string $severity): void
    {
        if (! in_array($severity, ['info', 'warning', 'critical', 'security'], true)) {
            throw new InvalidArgumentException('Operational alert severity is invalid.');
        }
    }

    private function assertEventName(string $eventName): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,190}\z/', $eventName) !== 1) {
            throw new InvalidArgumentException('Operational alert event name is invalid.');
        }
    }

    private function assertDeduplicationKey(string $deduplicationKey): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $deduplicationKey) !== 1) {
            throw new InvalidArgumentException('Operational alert deduplication key is invalid.');
        }
    }

    private function assertCorrelationId(string $correlationId): void
    {
        if (strlen($correlationId) < 8
            || strlen($correlationId) > 64
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $correlationId) !== 1
        ) {
            throw new InvalidArgumentException('Operational alert correlation ID is invalid.');
        }
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $safeContext
     *
     * @throws JsonException
     */
    private function encodeSafeContext(array $safeContext): ?string
    {
        if ($safeContext === []) {
            return null;
        }

        foreach ($safeContext as $key => $value) {
            if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1) {
                throw new InvalidArgumentException('Operational alert safe-context key is invalid.');
            }
            foreach (self::FORBIDDEN_CONTEXT_KEY_PARTS as $part) {
                if (str_contains($key, $part)) {
                    throw new InvalidArgumentException('Sensitive operational alert context is forbidden.');
                }
            }
            if (is_string($value) && mb_strlen($value) > 512) {
                throw new InvalidArgumentException('Operational alert safe-context string is too long.');
            }
        }

        $encoded = json_encode($safeContext, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 4096) {
            throw new InvalidArgumentException('Operational alert safe context exceeds 4 KiB.');
        }

        return $encoded;
    }
}
