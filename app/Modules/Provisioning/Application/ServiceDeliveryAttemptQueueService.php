<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxPublisher;
use App\Shared\Application\SafeOutboxPayload;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type DeliveryAttemptRow object{id:int|string,public_id:string,service_subscription_id:int|string,purpose:string,request_key_hash:string,correlation_id:string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,outbox_event_id:string}
 */
final readonly class ServiceDeliveryAttemptQueueService
{
    private const QUEUE_AUTHORITY = 'service_delivery_queue_v1';

    /** @var list<string> */
    private const TERMINAL_MUTATION_STATES = ['succeeded', 'failed_final', 'compensated'];

    public const OUTBOX_EVENT_TYPE = 'provisioning.service_delivery.requested';

    public const OUTBOX_AGGREGATE_TYPE = 'service_delivery_attempt';

    public const OUTBOX_EVENT_KEY_PREFIX = 'provisioning-service-delivery-requested:';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private OutboxPublisher $outbox,
    ) {}

    /** @requirement SVC-002 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function queue(
        string $servicePublicId,
        ServiceDeliveryPurpose $purpose,
        string $requestKey,
        string $correlationId,
    ): ServiceDeliveryAttemptReceipt {
        $this->assertUlid($servicePublicId, 'Service public ID');
        $requestKeyHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Service delivery correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $servicePublicId,
            $purpose,
            $requestKeyHash,
            $correlationId,
        ): ServiceDeliveryAttemptReceipt {
            $service = $this->lockedService($connection, $servicePublicId);
            $replayed = $this->attemptByRequestHash($connection, (int) $service->id, $requestKeyHash, true);

            if ($replayed !== null) {
                $storedPurpose = ServiceDeliveryPurpose::tryFrom($replayed->purpose)
                    ?? throw new RuntimeException('Stored Service delivery purpose is invalid.');
                if ($storedPurpose !== $purpose) {
                    throw new DomainException('Service delivery request key was already used for a different purpose.');
                }

                return $this->receipt($service, $replayed, true);
            }

            $this->assertServiceDeliverable($service);
            $activeMutation = $connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->where('operation_type', '<>', 'initial_provision')
                ->whereNotIn('state', self::TERMINAL_MUTATION_STATES)
                ->first(['id']);
            if ($activeMutation !== null) {
                throw new DomainException('Service has an unresolved mutation operation and cannot accept delivery attempts.');
            }

            $remoteIdentityGeneration = $this->positiveDatabaseInt(
                $service->remote_identity_generation,
                'Service remote identity generation',
            );
            $lifecycleVersion = $this->nonNegativeDatabaseInt(
                $service->lifecycle_version,
                'Service lifecycle version',
            );
            $attemptPublicId = (string) Str::ulid();
            $outboxEventId = (string) Str::uuid();
            $timestamp = $this->timestamp();

            try {
                $this->setQueueAuthority(
                    $connection,
                    (int) $service->id,
                    $purpose,
                    $requestKeyHash,
                    $correlationId,
                    $attemptPublicId,
                    $outboxEventId,
                );

                $publishedEventId = $this->outbox->publish(
                    $outboxEventId,
                    self::OUTBOX_EVENT_KEY_PREFIX.$attemptPublicId,
                    self::OUTBOX_EVENT_TYPE,
                    self::OUTBOX_AGGREGATE_TYPE,
                    $attemptPublicId,
                    new SafeOutboxPayload([
                        'service_delivery_attempt_public_id' => $attemptPublicId,
                    ]),
                    $correlationId,
                );
                if (! hash_equals($outboxEventId, $publishedEventId)) {
                    throw new RuntimeException('Service delivery Outbox event identity was unexpectedly replayed.');
                }

                $attemptId = (int) $connection->table('service_delivery_attempts')->insertGetId([
                    'public_id' => $attemptPublicId,
                    'service_subscription_id' => $this->positiveDatabaseInt($service->id, 'Service Subscription ID'),
                    'purpose' => $purpose->value,
                    'request_key_hash' => $requestKeyHash,
                    'correlation_id' => $correlationId,
                    'target_remote_identity_generation' => $remoteIdentityGeneration,
                    'target_lifecycle_version' => $lifecycleVersion,
                    'outbox_event_id' => $outboxEventId,
                    'created_at' => $timestamp,
                ]);

                $released = $connection->table('outbox_messages')
                    ->where('id', $outboxEventId)
                    ->where('dispatch_state', 'authority_pending')
                    ->update([
                        'dispatch_state' => 'pending',
                        'updated_at' => $timestamp,
                    ]);
                if ($released !== 1) {
                    throw new RuntimeException('Service delivery Outbox command did not release from queue authority.');
                }

                $attempt = $this->attemptById($connection, $attemptId);

                return $this->receipt($service, $attempt, false);
            } finally {
                $this->clearQueueAuthority($connection);
            }
        }, 3);
    }

    /** @return ServiceRow */
    private function lockedService(Connection $connection, string $publicId): object
    {
        /** @var ServiceRow|null $row */
        $row = $connection->table('service_subscriptions')
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first([
                'id', 'public_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
                'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'remote_deleted_at',
            ]);
        if ($row === null) {
            throw new DomainException('Service Subscription does not exist.');
        }

        return $row;
    }

    /** @return DeliveryAttemptRow|null */
    private function attemptByRequestHash(
        Connection $connection,
        int $serviceId,
        string $requestKeyHash,
        bool $lock,
    ): ?object {
        $query = $connection->table('service_delivery_attempts')
            ->where('service_subscription_id', $serviceId)
            ->where('request_key_hash', $requestKeyHash);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var DeliveryAttemptRow|null $row */
        $row = $query->first([
            'id', 'public_id', 'service_subscription_id', 'purpose', 'request_key_hash', 'correlation_id',
            'target_remote_identity_generation', 'target_lifecycle_version', 'outbox_event_id',
        ]);

        return $row;
    }

    /** @return DeliveryAttemptRow */
    private function attemptById(Connection $connection, int $attemptId): object
    {
        /** @var DeliveryAttemptRow|null $row */
        $row = $connection->table('service_delivery_attempts')->where('id', $attemptId)->first([
            'id', 'public_id', 'service_subscription_id', 'purpose', 'request_key_hash', 'correlation_id',
            'target_remote_identity_generation', 'target_lifecycle_version', 'outbox_event_id',
        ]);
        if ($row === null) {
            throw new RuntimeException('Service Delivery Attempt disappeared after creation.');
        }

        return $row;
    }

    /** @param ServiceRow $service */
    private function assertServiceDeliverable(object $service): void
    {
        if ($service->remote_deleted_at !== null || $service->lifecycle_state === 'retired') {
            throw new DomainException('Retired Service Subscription cannot accept new delivery attempts.');
        }
        if (! in_array($service->lifecycle_state, ['active', 'suspended'], true)) {
            throw new RuntimeException('Stored Service lifecycle state is invalid.');
        }
        if ($service->service_target_id === null || (int) $service->service_target_id < 1
            || ! is_string($service->remote_service_id) || $service->remote_service_id === ''
            || $service->provisioned_at === null
            || $this->positiveDatabaseInt($service->remote_identity_generation, 'Service remote identity generation') < 1) {
            throw new DomainException('Service Subscription is not fully provisioned for delivery.');
        }
    }

    /**
     * @param  ServiceRow  $service
     * @param  DeliveryAttemptRow  $attempt
     */
    private function receipt(object $service, object $attempt, bool $replayed): ServiceDeliveryAttemptReceipt
    {
        $purpose = ServiceDeliveryPurpose::tryFrom($attempt->purpose)
            ?? throw new RuntimeException('Stored Service delivery purpose is invalid.');

        return new ServiceDeliveryAttemptReceipt(
            (string) $service->public_id,
            (string) $attempt->public_id,
            $purpose,
            $this->positiveDatabaseInt(
                $attempt->target_remote_identity_generation,
                'Delivery target remote identity generation',
            ),
            $this->nonNegativeDatabaseInt(
                $attempt->target_lifecycle_version,
                'Delivery target lifecycle version',
            ),
            (string) $attempt->outbox_event_id,
            $replayed,
        );
    }

    private function requestKeyHash(string $requestKey): string
    {
        $this->assertToken($requestKey, 'Service delivery request key', 8, 128);

        return hash('sha256', $requestKey);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function setQueueAuthority(
        Connection $connection,
        int $serviceId,
        ServiceDeliveryPurpose $purpose,
        string $requestHash,
        string $correlationId,
        string $attemptPublicId,
        string $outboxEventId,
    ): void {
        $connection->statement('SET @app_service_delivery_authority = ?', [self::QUEUE_AUTHORITY]);
        $connection->statement('SET @app_service_delivery_service_id = ?', [$serviceId]);
        $connection->statement('SET @app_service_delivery_purpose = ?', [$purpose->value]);
        $connection->statement('SET @app_service_delivery_request_hash = ?', [$requestHash]);
        $connection->statement('SET @app_service_delivery_correlation_id = ?', [$correlationId]);
        $connection->statement('SET @app_service_delivery_attempt_public_id = ?', [$attemptPublicId]);
        $connection->statement('SET @app_service_delivery_outbox_event_id = ?', [$outboxEventId]);
    }

    private function clearQueueAuthority(Connection $connection): void
    {
        $connection->statement('SET @app_service_delivery_authority = NULL');
        $connection->statement('SET @app_service_delivery_service_id = NULL');
        $connection->statement('SET @app_service_delivery_purpose = NULL');
        $connection->statement('SET @app_service_delivery_request_hash = NULL');
        $connection->statement('SET @app_service_delivery_correlation_id = NULL');
        $connection->statement('SET @app_service_delivery_attempt_public_id = NULL');
        $connection->statement('SET @app_service_delivery_outbox_event_id = NULL');
    }

    private function assertUlid(string $value, string $label): void
    {
        if (! Str::isUlid($value)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function positiveDatabaseInt(int|string|null $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
