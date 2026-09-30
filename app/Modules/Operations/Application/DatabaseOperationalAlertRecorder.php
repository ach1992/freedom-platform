<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\OperationalAlertRecorder;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

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
        $this->record(
            $severity,
            $eventName,
            $deduplicationKey,
            $correlationId,
            $safeContext,
            true,
        );
    }

    public function raiseOnce(
        string $severity,
        string $eventName,
        string $deduplicationKey,
        string $correlationId,
        array $safeContext = [],
    ): void {
        $this->record(
            $severity,
            $eventName,
            $deduplicationKey,
            $correlationId,
            $safeContext,
            false,
        );
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $safeContext
     */
    private function record(
        string $severity,
        string $eventName,
        string $deduplicationKey,
        string $correlationId,
        array $safeContext,
        bool $countRepeatedOccurrence,
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
            $countRepeatedOccurrence,
        ): void {
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $alertId = (string) Str::uuid();
            $inserted = $connection->table('alerts')->insertOrIgnore([
                'id' => $alertId,
                'severity' => $severity,
                'event_name' => $eventName,
                'deduplication_key' => $deduplicationKey,
                'correlation_id' => $correlationId,
                'safe_context' => $encodedContext,
                'occurrence_count' => 1,
                'activation_sequence' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'acknowledged_at' => null,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 1) {
                $this->ensureDeliveryIntents(
                    $connection,
                    $alertId,
                    1,
                    $severity,
                    $correlationId,
                    $now,
                );
                $this->recordSecurityActivationAudit(
                    $connection,
                    $alertId,
                    1,
                    $severity,
                    $eventName,
                    $correlationId,
                    $now,
                );

                return;
            }

            /** @var object{id:string,occurrence_count:int|string,activation_sequence:int|string,acknowledged_at:?string,resolved_at:?string}|null $existing */
            $existing = $connection->table('alerts')
                ->where('event_name', $eventName)
                ->where('deduplication_key', $deduplicationKey)
                ->lockForUpdate()
                ->first(['id', 'occurrence_count', 'activation_sequence', 'acknowledged_at', 'resolved_at']);
            if ($existing === null) {
                throw new RuntimeException('Operational alert deduplication conflict could not be reconciled.');
            }
            if (! $countRepeatedOccurrence) {
                return;
            }

            $occurrenceCount = (int) $existing->occurrence_count + 1;
            $activationSequence = (int) $existing->activation_sequence;
            $reopened = $existing->resolved_at !== null;
            if ($reopened) {
                $activationSequence++;
            }

            $connection->table('alerts')
                ->where('id', $existing->id)
                ->update([
                    'severity' => $severity,
                    'correlation_id' => $correlationId,
                    'safe_context' => $encodedContext,
                    'occurrence_count' => $occurrenceCount,
                    'activation_sequence' => $activationSequence,
                    'last_seen_at' => $now,
                    'acknowledged_at' => $reopened ? null : $existing->acknowledged_at,
                    'resolved_at' => null,
                    'updated_at' => $now,
                ]);

            $this->ensureDeliveryIntents(
                $connection,
                $existing->id,
                $activationSequence,
                $severity,
                $correlationId,
                $now,
            );
            $this->recordSecurityActivationAudit(
                $connection,
                $existing->id,
                $activationSequence,
                $severity,
                $eventName,
                $correlationId,
                $now,
            );
        }, 3);
    }

    public function resolve(string $eventName, string $deduplicationKey): void
    {
        $this->assertEventName($eventName);
        $this->assertDeduplicationKey($deduplicationKey);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->database->connection()->table('alerts')
            ->where('event_name', $eventName)
            ->where('deduplication_key', $deduplicationKey)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function ensureDeliveryIntents(
        Connection $connection,
        string $alertId,
        int $activationSequence,
        string $severity,
        string $correlationId,
        string $now,
    ): void {
        foreach ($this->deliveryAudiences($severity) as $audience) {
            $requestKey = sprintf(
                'operations-alert:%s:%d:%s',
                $alertId,
                $activationSequence,
                $audience,
            );
            $requestKeyHash = hash('sha256', $requestKey);
            try {
                $connection->table('operational_alert_deliveries')->insert([
                    'public_id' => (string) Str::ulid(),
                    'alert_id' => $alertId,
                    'activation_sequence' => $activationSequence,
                    'audience' => $audience,
                    'request_key_hash' => $requestKeyHash,
                    'state' => 'pending',
                    'attempts' => 0,
                    'available_at' => $now,
                    'lease_token_hash' => null,
                    'leased_until' => null,
                    'telegram_operation_public_id' => null,
                    'last_error_code' => null,
                    'queued_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }
            }

            /** @var object{request_key_hash:string}|null $existing */
            $existing = $connection->table('operational_alert_deliveries')
                ->where('alert_id', $alertId)
                ->where('activation_sequence', $activationSequence)
                ->where('audience', $audience)
                ->first(['request_key_hash']);
            if ($existing === null || ! hash_equals($existing->request_key_hash, $requestKeyHash)) {
                throw new RuntimeException('Operational alert delivery intent replay is inconsistent.');
            }
        }
    }

    private function recordSecurityActivationAudit(
        Connection $connection,
        string $alertId,
        int $activationSequence,
        string $severity,
        string $eventName,
        string $correlationId,
        string $now,
    ): void {
        if ($severity !== 'security') {
            return;
        }

        $action = 'operations.alert.security_activated';
        $safeData = [
            'event_name' => $eventName,
            'activation_sequence' => $activationSequence,
        ];
        $safeJson = json_encode($safeData, JSON_THROW_ON_ERROR);
        $requestFingerprint = hash(
            'sha256',
            $action."\0".$alertId."\0".$activationSequence,
        );

        try {
            $connection->table('audit_logs')->insert([
                'actor_type' => 'system',
                'actor_id' => null,
                'action' => $action,
                'target_type' => 'operational_alert',
                'target_id' => $alertId,
                'before_safe_data' => null,
                'after_safe_data' => $safeJson,
                'reason_code' => 'security_alert_activation',
                'reason' => 'Security operational alert activation recorded.',
                'correlation_id' => $correlationId,
                'request_fingerprint' => $requestFingerprint,
                'created_at' => $now,
            ]);

            return;
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKey($exception)) {
                throw $exception;
            }
        }

        /** @var object{actor_type:string,actor_id:?string,action:string,target_type:string,target_id:string,after_safe_data:string,reason_code:string,reason:string}|null $existing */
        $existing = $connection->table('audit_logs')
            ->where('action', $action)
            ->where('request_fingerprint', $requestFingerprint)
            ->first([
                'actor_type',
                'actor_id',
                'action',
                'target_type',
                'target_id',
                'after_safe_data',
                'reason_code',
                'reason',
            ]);
        if ($existing === null
            || $existing->actor_type !== 'system'
            || $existing->actor_id !== null
            || $existing->action !== $action
            || $existing->target_type !== 'operational_alert'
            || $existing->target_id !== $alertId
            || $existing->reason_code !== 'security_alert_activation'
            || $existing->reason !== 'Security operational alert activation recorded.'
            || json_decode($existing->after_safe_data, true, 512, JSON_THROW_ON_ERROR) !== $safeData
        ) {
            throw new RuntimeException('Security operational alert audit replay is inconsistent.');
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /** @return list<string> */
    private function deliveryAudiences(string $severity): array
    {
        return match ($severity) {
            'warning' => ['report_channel'],
            'critical' => ['report_channel', 'owner'],
            'security' => ['owner'],
            default => [],
        };
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
