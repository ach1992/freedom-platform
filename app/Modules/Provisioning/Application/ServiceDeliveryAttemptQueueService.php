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
 * @phpstan-type NotificationStateRow object{id:int|string,service_subscription_id:int|string,state:string,latest_delivery_attempt_id:int|string|null,latest_retry_ordinal:int|string|null,next_retry_at:?string}
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
        if ($purpose === ServiceDeliveryPurpose::Notification) {
            throw new DomainException('Notification delivery requires a durable notification binding.');
        }
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

            $this->assertServiceReadyForDelivery($connection, $service);
            $attempt = $this->createAttempt(
                $connection,
                $service,
                $purpose,
                $requestKeyHash,
                $correlationId,
            );

            return $this->receipt($service, $attempt, false);
        }, 3);
    }

    /** @requirement SVC-013 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function queueNotification(
        int $notificationStateId,
        string $servicePublicId,
        int $retryOrdinal,
        string $requestKey,
        string $correlationId,
        string $presentationText,
    ): ServiceDeliveryAttemptReceipt {
        if ($notificationStateId < 1 || $retryOrdinal < 0 || $retryOrdinal > 100) {
            throw new DomainException('Service notification delivery identity is invalid.');
        }
        if ($presentationText === '' || mb_strlen($presentationText) > 4096) {
            throw new DomainException('Service notification presentation is invalid.');
        }
        $this->assertUlid($servicePublicId, 'Service public ID');
        $requestKeyHash = $this->requestKeyHash($requestKey);
        $this->assertToken($correlationId, 'Service delivery correlation ID', 8, 64);
        $presentationHash = hash('sha256', $presentationText);

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $notificationStateId,
            $servicePublicId,
            $retryOrdinal,
            $requestKeyHash,
            $correlationId,
            $presentationText,
            $presentationHash,
        ): ServiceDeliveryAttemptReceipt {
            $service = $this->lockedService($connection, $servicePublicId);
            /** @var NotificationStateRow|null $notification */
            $notification = $connection->table('service_notification_states')
                ->where('id', $notificationStateId)
                ->where('service_subscription_id', (int) $service->id)
                ->lockForUpdate()
                ->first([
                    'id', 'service_subscription_id', 'state', 'latest_delivery_attempt_id', 'latest_retry_ordinal',
                    'next_retry_at',
                ]);
            if ($notification === null || $notification->state !== 'triggered') {
                throw new DomainException('Service notification delivery requires one triggered notification state.');
            }

            $replayed = $this->attemptByRequestHash($connection, (int) $service->id, $requestKeyHash, true);
            if ($replayed !== null) {
                $this->assertNotificationReplay(
                    $connection,
                    $replayed,
                    $notificationStateId,
                    $retryOrdinal,
                    $presentationHash,
                );

                return $this->receipt($service, $replayed, true);
            }

            $expectedOrdinal = $notification->latest_retry_ordinal === null
                ? 0
                : (int) $notification->latest_retry_ordinal + 1;
            if ($retryOrdinal !== $expectedOrdinal) {
                throw new DomainException('Service notification retry ordinal is not the next deterministic attempt.');
            }
            $this->assertNotificationRetryReady($connection, $notification, $retryOrdinal);

            $this->assertServiceReadyForDelivery($connection, $service);
            $attempt = $this->createAttempt(
                $connection,
                $service,
                ServiceDeliveryPurpose::Notification,
                $requestKeyHash,
                $correlationId,
                function (int $attemptId, string $timestamp) use (
                    $connection,
                    $notificationStateId,
                    $retryOrdinal,
                    $correlationId,
                    $presentationText,
                    $presentationHash,
                ): void {
                    ServiceNotificationDatabaseAuthority::bind(
                        $connection,
                        $notificationStateId,
                        (int) $connection->table('service_notification_states')
                            ->where('id', $notificationStateId)
                            ->value('service_subscription_id'),
                        $attemptId,
                        $retryOrdinal,
                        $correlationId,
                    );
                    try {
                        $updated = $connection->table('service_notification_states')
                            ->where('id', $notificationStateId)
                            ->where('state', 'triggered')
                            ->update([
                                'latest_delivery_attempt_id' => $attemptId,
                                'latest_retry_ordinal' => $retryOrdinal,
                                'next_retry_at' => null,
                                'last_correlation_id' => $correlationId,
                                'updated_at' => $timestamp,
                            ]);
                        if ($updated !== 1) {
                            throw new RuntimeException('Service notification state lost its triggered delivery authority.');
                        }

                        $connection->table('service_notification_delivery_bindings')->insert([
                            'service_delivery_attempt_id' => $attemptId,
                            'service_notification_state_id' => $notificationStateId,
                            'retry_ordinal' => $retryOrdinal,
                            'presentation_text' => $presentationText,
                            'presentation_hash' => $presentationHash,
                            'created_at' => $timestamp,
                        ]);
                        $this->insertNotificationEvent(
                            $connection,
                            $notificationStateId,
                            'delivery_queued',
                            'triggered',
                            'triggered',
                            $attemptId,
                            $retryOrdinal,
                            $correlationId,
                            $timestamp,
                        );
                    } finally {
                        ServiceNotificationDatabaseAuthority::clear($connection);
                    }
                },
            );

            return $this->receipt($service, $attempt, false);
        }, 3);
    }

    /**
     * @param  ServiceRow  $service
     * @param  null|callable(int,string):void  $beforeRelease
     * @return DeliveryAttemptRow
     */
    private function createAttempt(
        Connection $connection,
        object $service,
        ServiceDeliveryPurpose $purpose,
        string $requestKeyHash,
        string $correlationId,
        ?callable $beforeRelease = null,
    ): object {
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

            if ($beforeRelease !== null) {
                $beforeRelease($attemptId, $timestamp);
            }

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

            return $this->attemptById($connection, $attemptId);
        } finally {
            $this->clearQueueAuthority($connection);
        }
    }

    /** @param NotificationStateRow $notification */
    private function assertNotificationRetryReady(
        Connection $connection,
        object $notification,
        int $retryOrdinal,
    ): void {
        if ($retryOrdinal === 0) {
            if ($notification->latest_delivery_attempt_id !== null
                || $notification->latest_retry_ordinal !== null
                || $notification->next_retry_at !== null) {
                throw new DomainException('Initial Service notification delivery has conflicting retry evidence.');
            }

            return;
        }

        $previousAttemptId = $this->positiveDatabaseInt(
            $notification->latest_delivery_attempt_id,
            'Previous Service notification delivery attempt ID',
        );
        if ($notification->latest_retry_ordinal === null
            || (int) $notification->latest_retry_ordinal !== $retryOrdinal - 1
            || $notification->next_retry_at === null) {
            throw new DomainException('Service notification retry is missing deterministic prior-attempt evidence.');
        }

        $dueAt = new \DateTimeImmutable($notification->next_retry_at, new \DateTimeZone('UTC'));
        if ($dueAt > $this->clock->now()) {
            throw new DomainException('Service notification retry backoff has not elapsed.');
        }

        /** @var object{id:int|string}|null $binding */
        $binding = $connection->table('service_notification_delivery_bindings')
            ->where('service_delivery_attempt_id', $previousAttemptId)
            ->where('service_notification_state_id', (int) $notification->id)
            ->where('retry_ordinal', $retryOrdinal - 1)
            ->lockForUpdate()
            ->first(['service_delivery_attempt_id as id']);
        if ($binding === null) {
            throw new DomainException('Service notification retry is not bound to the previous durable attempt.');
        }

        /** @var object{state:string,completed_at:?string}|null $effect */
        $effect = $connection->table('service_delivery_effects')
            ->where('service_delivery_attempt_id', $previousAttemptId)
            ->where('service_subscription_id', (int) $notification->service_subscription_id)
            ->lockForUpdate()
            ->first(['state', 'completed_at']);
        if ($effect === null || $effect->state !== 'failed_final' || $effect->completed_at === null) {
            throw new DomainException('Service notification retry requires one completed failed delivery effect.');
        }
    }

    /** @param ServiceRow $service */
    private function assertServiceReadyForDelivery(Connection $connection, object $service): void
    {
        $this->assertServiceDeliverable($service);
        $blockingDelivery = $connection->table('service_delivery_effects')
            ->where('blocking_service_subscription_id', (int) $service->id)
            ->first(['id']);
        if ($blockingDelivery !== null) {
            throw new DomainException('Service delivery is blocked by an in-flight, uncertain, or provider-directed retry boundary.');
        }

        $activeMutation = $connection->table('provisioning_operations')
            ->where('service_subscription_id', (int) $service->id)
            ->where('operation_type', '<>', 'initial_provision')
            ->whereNotIn('state', self::TERMINAL_MUTATION_STATES)
            ->first(['id']);
        if ($activeMutation !== null) {
            throw new DomainException('Service has an unresolved mutation operation and cannot accept delivery attempts.');
        }
    }

    /** @param DeliveryAttemptRow $attempt */
    private function assertNotificationReplay(
        Connection $connection,
        object $attempt,
        int $notificationStateId,
        int $retryOrdinal,
        string $presentationHash,
    ): void {
        if ($attempt->purpose !== ServiceDeliveryPurpose::Notification->value) {
            throw new DomainException('Service notification delivery request identity conflicts with another delivery purpose.');
        }
        /** @var object{service_notification_state_id:int|string,retry_ordinal:int|string,presentation_hash:string}|null $binding */
        $binding = $connection->table('service_notification_delivery_bindings')
            ->where('service_delivery_attempt_id', (int) $attempt->id)
            ->first(['service_notification_state_id', 'retry_ordinal', 'presentation_hash']);
        if ($binding === null
            || (int) $binding->service_notification_state_id !== $notificationStateId
            || (int) $binding->retry_ordinal !== $retryOrdinal
            || ! hash_equals($binding->presentation_hash, $presentationHash)) {
            throw new DomainException('Service notification delivery replay conflicts with durable notification evidence.');
        }
    }

    private function insertNotificationEvent(
        Connection $connection,
        int $stateId,
        string $eventType,
        ?string $fromState,
        string $toState,
        ?int $attemptId,
        ?int $retryOrdinal,
        string $correlationId,
        string $timestamp,
    ): void {
        $sequence = (int) $connection->table('service_notification_events')
            ->where('service_notification_state_id', $stateId)
            ->max('sequence') + 1;
        $connection->table('service_notification_events')->insert([
            'service_notification_state_id' => $stateId,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'from_state' => $fromState,
            'to_state' => $toState,
            'service_delivery_attempt_id' => $attemptId,
            'retry_ordinal' => $retryOrdinal,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);
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
