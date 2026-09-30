<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Shared\Application\Clock;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class OperationalAlertLifecycleService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $administrators,
        private Clock $clock,
    ) {}

    /** @requirement OPS-001 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 */
    public function acknowledge(
        int $actorUserId,
        string $alertId,
        string $reason,
        string $correlationId,
        string $requestKey,
    ): OperationalAlertLifecycleReceipt {
        return $this->transition(
            $actorUserId,
            $alertId,
            'acknowledged',
            $reason,
            $correlationId,
            $requestKey,
        );
    }

    /** @requirement OPS-001 OPS-003 ACL-001 ACL-002 DAT-003 SEC-002 */
    public function resolve(
        int $actorUserId,
        string $alertId,
        string $reason,
        string $correlationId,
        string $requestKey,
    ): OperationalAlertLifecycleReceipt {
        return $this->transition(
            $actorUserId,
            $alertId,
            'resolved',
            $reason,
            $correlationId,
            $requestKey,
        );
    }

    private function transition(
        int $actorUserId,
        string $alertId,
        string $eventType,
        string $reason,
        string $correlationId,
        string $requestKey,
    ): OperationalAlertLifecycleReceipt {
        $administratorId = $this->administrators->authorizeUser(
            $actorUserId,
            OperationsPermissions::ALERTS_MANAGE,
        );
        $this->assertContext($alertId, $reason, $correlationId, $requestKey);
        if (! in_array($eventType, ['acknowledged', 'resolved'], true)) {
            throw new InvalidArgumentException('Operational alert lifecycle event is invalid.');
        }

        $requestKeyHash = hash('sha256', 'operations.alert.'.$eventType."\0".$requestKey);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $administratorId,
            $alertId,
            $eventType,
            $reason,
            $correlationId,
            $requestKeyHash,
        ): OperationalAlertLifecycleReceipt {
            /** @var object{id:string,severity:string,event_name:string,activation_sequence:int|string,occurrence_count:int|string,acknowledged_at:?string,resolved_at:?string}|null $alert */
            $alert = $connection->table('alerts')
                ->where('id', $alertId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'severity',
                    'event_name',
                    'activation_sequence',
                    'occurrence_count',
                    'acknowledged_at',
                    'resolved_at',
                ]);
            if ($alert === null) {
                throw new RuntimeException('Operational alert does not exist.');
            }

            /** @var object{alert_id:string,event_type:string,actor_administrator_id:int|string,reason:string,correlation_id:string}|null $existing */
            $existing = $connection->table('operational_alert_events')
                ->where('request_key_hash', $requestKeyHash)
                ->first([
                    'alert_id',
                    'event_type',
                    'actor_administrator_id',
                    'reason',
                    'correlation_id',
                ]);
            if ($existing !== null) {
                if ($existing->alert_id !== $alertId
                    || $existing->event_type !== $eventType
                    || (int) $existing->actor_administrator_id !== $administratorId
                    || $existing->reason !== $reason
                    || $existing->correlation_id !== $correlationId
                ) {
                    throw new RuntimeException('Operational alert lifecycle request key was replayed with conflicting semantics.');
                }

                return new OperationalAlertLifecycleReceipt($alertId, $eventType, true);
            }

            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            if ($eventType === 'acknowledged') {
                if ($alert->resolved_at !== null) {
                    throw new RuntimeException('Resolved operational alert cannot be acknowledged.');
                }
                if ($alert->acknowledged_at !== null) {
                    throw new RuntimeException('Operational alert is already acknowledged.');
                }

                $updated = $connection->table('alerts')
                    ->where('id', $alertId)
                    ->whereNull('acknowledged_at')
                    ->whereNull('resolved_at')
                    ->update([
                        'acknowledged_at' => $now,
                        'updated_at' => $now,
                    ]);
            } else {
                if ($alert->resolved_at !== null) {
                    throw new RuntimeException('Operational alert is already resolved.');
                }

                $updated = $connection->table('alerts')
                    ->where('id', $alertId)
                    ->whereNull('resolved_at')
                    ->update([
                        'resolved_at' => $now,
                        'updated_at' => $now,
                    ]);
            }
            if ($updated !== 1) {
                throw new RuntimeException('Operational alert lifecycle transition lost concurrency.');
            }

            $connection->table('operational_alert_events')->insert([
                'public_id' => (string) Str::ulid(),
                'alert_id' => $alertId,
                'event_type' => $eventType,
                'actor_administrator_id' => $administratorId,
                'request_key_hash' => $requestKeyHash,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'created_at' => $now,
            ]);

            $action = 'operations.alert.'.$eventType;
            $connection->table('audit_logs')->insert([
                'actor_type' => 'administrator',
                'actor_id' => (string) $administratorId,
                'action' => $action,
                'target_type' => 'operational_alert',
                'target_id' => $alertId,
                'before_safe_data' => null,
                'after_safe_data' => json_encode([
                    'severity' => $alert->severity,
                    'event_name' => $alert->event_name,
                    'activation_sequence' => (int) $alert->activation_sequence,
                    'occurrence_count' => (int) $alert->occurrence_count,
                ], JSON_THROW_ON_ERROR),
                'reason_code' => 'operational_alert_'.$eventType,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'request_fingerprint' => hash('sha256', $action."\0".$requestKeyHash),
                'created_at' => $now,
            ]);

            return new OperationalAlertLifecycleReceipt($alertId, $eventType, false);
        }, 3);
    }

    private function assertContext(
        string $alertId,
        string $reason,
        string $correlationId,
        string $requestKey,
    ): void {
        if (! Str::isUuid($alertId)
            || trim($reason) === ''
            || mb_strlen($reason) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1
            || trim($requestKey) === ''
            || strlen($requestKey) > 256
        ) {
            throw new InvalidArgumentException('Operational alert lifecycle context is invalid.');
        }
    }
}
