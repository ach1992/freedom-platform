<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application;

use App\Modules\Operations\Application\Contracts\OperationalAlertDeliveryGateway;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type DeliveryClaim array{
 *   id:int,
 *   alert_id:string,
 *   activation_sequence:int,
 *   audience:string,
 *   request_key_hash:string,
 *   attempts:int,
 *   lease_token:string
 * }
 */
final readonly class OperationalAlertDeliveryRunner
{
    private const MAX_BATCH = 50;

    private const LEASE_SECONDS = 300;

    private const MAX_ATTEMPTS = 4;

    /** @var array<int,int> */
    private const RETRY_DELAYS = [
        1 => 60,
        2 => 300,
        3 => 900,
    ];

    public function __construct(
        private DatabaseManager $database,
        private OperationalAlertDeliveryGateway $delivery,
        private Clock $clock,
        private RandomGenerator $random,
    ) {}

    /** @requirement OPS-001 OPS-003 DAT-003 SEC-002 QUA-004 */
    public function runDue(int $limit): OperationalAlertDeliveryRunSummary
    {
        if ($limit < 1 || $limit > self::MAX_BATCH) {
            throw new RuntimeException('Operational alert delivery batch limit must be between 1 and '.self::MAX_BATCH.'.');
        }

        $examined = 0;
        $queued = 0;
        $retryScheduled = 0;
        $failed = 0;

        while ($examined < $limit) {
            $claim = $this->claimOne();
            if ($claim === null) {
                break;
            }
            $examined++;

            try {
                $this->queueClaim($claim);
                $queued++;
            } catch (Throwable) {
                if ($claim['attempts'] >= self::MAX_ATTEMPTS) {
                    $this->finishFailed($claim, 'telegram_queue_failed');
                    $failed++;
                } else {
                    $this->finishRetry($claim, 'telegram_queue_failed');
                    $retryScheduled++;
                }
            }
        }

        return new OperationalAlertDeliveryRunSummary(
            $examined,
            $queued,
            $retryScheduled,
            $failed,
        );
    }

    /** @return DeliveryClaim|null */
    private function claimOne(): ?array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $leaseExpires = $now->modify('+'.self::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s.u');

        return $this->database->connection()->transaction(function (Connection $connection) use ($timestamp, $leaseExpires): ?array {
            /** @var object{id:int|string,alert_id:string,activation_sequence:int|string,audience:string,request_key_hash:string,attempts:int|string}|null $row */
            $row = $connection->table('operational_alert_deliveries')
                ->whereIn('state', ['pending', 'retry', 'leased'])
                ->where('available_at', '<=', $timestamp)
                ->where(static function (Builder $query) use ($timestamp): void {
                    $query->whereIn('state', ['pending', 'retry'])
                        ->orWhere(static function (Builder $leased) use ($timestamp): void {
                            $leased->where('state', 'leased')
                                ->where('leased_until', '<=', $timestamp);
                        });
                })
                ->orderBy('available_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first([
                    'id',
                    'alert_id',
                    'activation_sequence',
                    'audience',
                    'request_key_hash',
                    'attempts',
                ]);
            if ($row === null) {
                return null;
            }

            $token = bin2hex($this->random->bytes(32));
            $attempts = (int) $row->attempts + 1;
            $updated = $connection->table('operational_alert_deliveries')
                ->where('id', $row->id)
                ->whereIn('state', ['pending', 'retry', 'leased'])
                ->update([
                    'state' => 'leased',
                    'attempts' => $attempts,
                    'lease_token_hash' => hash('sha256', $token),
                    'leased_until' => $leaseExpires,
                    'last_error_code' => null,
                    'updated_at' => $timestamp,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('Operational alert delivery lease could not be acquired.');
            }

            return [
                'id' => (int) $row->id,
                'alert_id' => $row->alert_id,
                'activation_sequence' => (int) $row->activation_sequence,
                'audience' => $row->audience,
                'request_key_hash' => $row->request_key_hash,
                'attempts' => $attempts,
                'lease_token' => $token,
            ];
        }, 3);
    }

    /** @param DeliveryClaim $claim */
    private function queueClaim(array $claim): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($claim): void {
            /** @var object{severity:string,event_name:string,correlation_id:string,occurrence_count:int|string,resolved_at:?string}|null $alert */
            $alert = $connection->table('alerts')
                ->where('id', $claim['alert_id'])
                ->first([
                    'severity',
                    'event_name',
                    'correlation_id',
                    'occurrence_count',
                    'resolved_at',
                ]);
            if ($alert === null) {
                throw new RuntimeException('Operational alert delivery references a missing alert.');
            }

            $requestKey = sprintf(
                'operations-alert:%s:%d:%s',
                $claim['alert_id'],
                $claim['activation_sequence'],
                $claim['audience'],
            );
            if (! hash_equals($claim['request_key_hash'], hash('sha256', $requestKey))) {
                throw new RuntimeException('Operational alert delivery request identity is inconsistent.');
            }

            $trackingCode = substr(hash(
                'sha256',
                $claim['alert_id'].':'.$claim['activation_sequence'],
            ), 0, 12);

            $operationPublicId = $this->delivery->queue(
                $claim['audience'],
                $alert->severity,
                $alert->event_name,
                (int) $alert->occurrence_count,
                $alert->resolved_at !== null,
                $trackingCode,
                $requestKey,
                $alert->correlation_id,
            );

            $timestamp = $this->clock->now()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s.u');
            $updated = $this->leasedRow($connection, $claim)->update([
                'state' => 'queued',
                'lease_token_hash' => null,
                'leased_until' => null,
                'telegram_operation_public_id' => $operationPublicId,
                'last_error_code' => null,
                'queued_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('Operational alert delivery queue acceptance lost its lease.');
            }
        }, 3);
    }

    /** @param DeliveryClaim $claim */
    private function finishRetry(array $claim, string $errorCode): void
    {
        $delay = self::RETRY_DELAYS[min($claim['attempts'], 3)] ?? 900;
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $timestamp = $now->format('Y-m-d H:i:s.u');
        $availableAt = $now->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u');

        $updated = $this->leasedRow($this->database->connection(), $claim)->update([
            'state' => 'retry',
            'available_at' => $availableAt,
            'lease_token_hash' => null,
            'leased_until' => null,
            'last_error_code' => $errorCode,
            'updated_at' => $timestamp,
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Operational alert delivery retry lost its lease.');
        }
    }

    /** @param DeliveryClaim $claim */
    private function finishFailed(array $claim, string $errorCode): void
    {
        $timestamp = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');

        $updated = $this->leasedRow($this->database->connection(), $claim)->update([
            'state' => 'failed',
            'lease_token_hash' => null,
            'leased_until' => null,
            'last_error_code' => $errorCode,
            'updated_at' => $timestamp,
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Operational alert delivery failure lost its lease.');
        }
    }

    /** @param DeliveryClaim $claim */
    private function leasedRow(Connection $connection, array $claim): Builder
    {
        return $connection->table('operational_alert_deliveries')
            ->where('id', $claim['id'])
            ->where('state', 'leased')
            ->where('lease_token_hash', hash('sha256', $claim['lease_token']));
    }
}
