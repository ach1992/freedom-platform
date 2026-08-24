<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use App\Modules\Provisioning\Domain\ServiceNotificationState;
use App\Modules\Provisioning\Domain\ServiceNotificationType;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type NotificationServiceRow object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}
 * @phpstan-type NotificationSpec array{type:ServiceNotificationType,threshold:string,cycle:string,episode:string,source_type:string,source_id:?int,message:string}
 * @phpstan-type NotificationStateRow object{id:int|string,public_id:string,service_subscription_id:int|string,episode_key_hash:string,notification_type:string,threshold_code:string,cycle_key_hash:string,source_type:string,source_id:int|string|null,state:string,latest_delivery_attempt_id:int|string|null,latest_retry_ordinal:int|string|null,next_retry_at:?string}
 * @phpstan-type AutoRenewAttemptAuthorityRow object{id:int|string,service_subscription_id:int|string,state:string}
 * @phpstan-type DeliveryEffectRow object{state:string,completed_at:?string,retry_after_seconds:int|string|null}
 */
final readonly class ServiceNotificationThresholdService
{
    private const ACKNOWLEDGE_PERMISSION = 'services.repair';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private WalletHoldService $wallet,
        private ServiceDeliveryAttemptQueueService $delivery,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    /** @requirement SVC-013 SVC-014 WAL-002 ARCH-004 DAT-003 DAT-004 QUA-004 */
    public function processBatch(int $limit): ServiceNotificationRunReceipt
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Service notification batch limit must be between 1 and 500.');
        }

        $services = $this->candidateServices($limit);
        $triggered = 0;
        $queued = 0;
        $notified = 0;
        $escalated = 0;
        $expired = 0;
        $skipped = 0;
        foreach ($services as $service) {
            $specs = $this->isThresholdEligible($service)
                ? $this->notificationSpecs($service)
                : [];
            $activeEpisodes = [];
            foreach ($specs as $spec) {
                $result = $this->ensureTriggered($service, $spec);
                if ($result === null) {
                    continue;
                }
                $activeEpisodes[] = $spec['episode'];
                [, $created] = $result;
                if ($created) {
                    $triggered++;
                }
            }

            $expired += $this->expireStaleTriggeredStates($service, $activeEpisodes);

            /** @var list<NotificationStateRow> $states */
            $states = $this->database->connection()->table('service_notification_states')
                ->where('service_subscription_id', (int) $service->id)
                ->where('state', ServiceNotificationState::Triggered->value)
                ->orderBy('id')
                ->get($this->stateColumns())
                ->all();
            foreach ($states as $state) {
                $result = $this->reconcileTriggeredState($service, $state);
                match ($result) {
                    'queued' => $queued++,
                    'notified' => $notified++,
                    'escalated' => $escalated++,
                    default => $skipped++,
                };
            }
        }

        return new ServiceNotificationRunReceipt(
            count($services),
            $triggered,
            $queued,
            $notified,
            $escalated,
            $expired,
            $skipped,
        );
    }

    /** @requirement SVC-013 SEC-002 DAT-003 QUA-004 */
    public function acknowledge(string $notificationPublicId, ServiceOperationalContext $context): bool
    {
        if (! Str::isUlid($notificationPublicId)) {
            throw new DomainException('Service notification public ID is invalid.');
        }
        $this->authorizer->authorize($context->actorAdministratorId, self::ACKNOWLEDGE_PERMISSION);

        /** @var object{id:int|string,service_subscription_id:int|string,state:string}|null $locator */
        $locator = $this->database->connection()->table('service_notification_states')
            ->where('public_id', $notificationPublicId)
            ->first(['id', 'service_subscription_id', 'state']);
        if ($locator === null) {
            throw new DomainException('Service notification state does not exist.');
        }
        if (! in_array($locator->state, [ServiceNotificationState::Notified->value, ServiceNotificationState::Escalated->value], true)) {
            throw new DomainException('Only notified or escalated Service notifications can be acknowledged.');
        }

        return $this->transitionState(
            (int) $locator->id,
            (int) $locator->service_subscription_id,
            ServiceNotificationState::Acknowledged,
            $context->correlationId,
        );
    }

    /**
     * @param  NotificationServiceRow  $service
     * @return list<NotificationSpec>
     */
    private function notificationSpecs(object $service): array
    {
        $specs = [];
        $expiry = $this->expirySpec($service);
        if ($expiry !== null) {
            $specs[] = $expiry;
        }
        $lowBalance = $this->lowBalanceSpec($service);
        if ($lowBalance !== null) {
            $specs[] = $lowBalance;
        }

        return [...$specs, ...$this->renewalFailureSpecs($service)];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @return NotificationSpec|null
     */
    private function expirySpec(object $service): ?array
    {
        /** @var object{id:int|string,remote_disposition:string,remote_expires_at:string|null}|null $snapshot */
        $snapshot = $this->database->connection()->table('service_sync_snapshots')
            ->where('service_subscription_id', (int) $service->id)
            ->where('local_lifecycle_version', (int) $service->lifecycle_version)
            ->where('local_remote_identity_generation', (int) $service->remote_identity_generation)
            ->where('local_mutation_generation', (int) $service->mutation_generation)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first(['id', 'remote_disposition', 'remote_expires_at']);
        if ($snapshot === null
            || $snapshot->remote_disposition !== 'present'
            || $snapshot->remote_expires_at === null) {
            return null;
        }

        return $this->expirySpecFromSnapshot($service, (int) $snapshot->id, $snapshot->remote_expires_at);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @return NotificationSpec|null
     */
    private function expirySpecFromSnapshot(object $service, int $snapshotId, string $remoteExpiresAt): ?array
    {
        $expiresAt = new DateTimeImmutable($remoteExpiresAt, new DateTimeZone('UTC'));
        $secondsRemaining = $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        $selectedDays = null;
        foreach ($this->expiryThresholdDays() as $days) {
            if ($secondsRemaining <= $days * 86400) {
                $selectedDays = $days;
                break;
            }
        }
        if ($selectedDays === null) {
            return null;
        }

        $threshold = $selectedDays === 0 ? 'expiry_due' : 'expiry_'.$selectedDays.'d';
        $cycle = hash('sha256', implode('|', [
            'service-notification-expiry-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            $expiresAt->format('Y-m-d H:i:s.u'),
        ]));

        return [
            'type' => ServiceNotificationType::Expiry,
            'threshold' => $threshold,
            'cycle' => $cycle,
            'episode' => $this->episodeKey($service, ServiceNotificationType::Expiry, $threshold, $cycle),
            'source_type' => 'service_sync_snapshot',
            'source_id' => $snapshotId,
            'message' => $selectedDays === 0
                ? 'Service expiry warning: your service has reached its recorded expiry time.'
                : 'Service expiry warning: your service expires within '.$selectedDays.' day'.($selectedDays === 1 ? '' : 's').'.',
        ];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @return NotificationSpec|null
     */
    private function lowBalanceSpec(object $service): ?array
    {
        $threshold = $this->boundedConfigInt('service_notifications.low_balance_irr', 0, 0, PHP_INT_MAX);
        if ($threshold === 0) {
            return null;
        }
        $userId = (int) $service->user_id;
        $wallet = $this->cashWalletObservation($userId);
        if ($wallet === null || $wallet['available_balance'] >= $threshold) {
            return null;
        }

        $cycle = hash('sha256', implode('|', [
            'service-notification-low-balance-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            (string) $threshold,
        ]));
        $thresholdCode = 'low_balance';

        return [
            'type' => ServiceNotificationType::LowBalance,
            'threshold' => $thresholdCode,
            'cycle' => $cycle,
            'episode' => $this->episodeKey($service, ServiceNotificationType::LowBalance, $thresholdCode, $cycle),
            'source_type' => 'wallet_balance',
            'source_id' => $wallet['account_id'],
            'message' => 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.',
        ];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @return list<NotificationSpec>
     */
    private function renewalFailureSpecs(object $service): array
    {
        /** @var list<object{id:int|string,auto_renew_attempt_id:int|string,outcome:string,reason_code:string|null,attempt_state:string}> $intents */
        $intents = $this->database->connection()->table('service_auto_renew_notification_intents as intent')
            ->join('service_auto_renew_attempts as attempt', 'attempt.id', '=', 'intent.auto_renew_attempt_id')
            ->where('attempt.service_subscription_id', (int) $service->id)
            ->whereIn('intent.outcome', ['insufficient_wallet', 'price_change_blocked', 'failure'])
            ->orderBy('intent.id')
            ->get([
                'intent.id', 'intent.auto_renew_attempt_id', 'intent.outcome', 'intent.reason_code',
                'attempt.state as attempt_state',
            ])
            ->all();

        $specs = [];
        foreach ($intents as $intent) {
            if (! $this->renewalIntentStillApplicable($intent->outcome, $intent->attempt_state)) {
                continue;
            }
            $threshold = 'renewal_'.$intent->outcome;
            $cycle = hash('sha256', implode('|', [
                'service-notification-renewal-cycle-v1',
                (string) $service->id,
                (string) $intent->id,
                $intent->outcome,
                (string) ($intent->reason_code ?? ''),
            ]));
            $specs[] = [
                'type' => ServiceNotificationType::RenewalFailure,
                'threshold' => $threshold,
                'cycle' => $cycle,
                'episode' => $this->episodeKey($service, ServiceNotificationType::RenewalFailure, $threshold, $cycle),
                'source_type' => 'auto_renew_notification_intent',
                'source_id' => (int) $intent->id,
                'message' => $this->renewalMessage($intent->outcome),
            ];
        }

        return $specs;
    }

    private function renewalIntentStillApplicable(string $outcome, string $attemptState): bool
    {
        return match ($outcome) {
            'insufficient_wallet' => $attemptState === 'insufficient_wallet',
            'price_change_blocked' => $attemptState === 'price_change_blocked',
            'failure' => $attemptState === 'failed',
            default => false,
        };
    }

    private function renewalMessage(string $outcome): string
    {
        return match ($outcome) {
            'insufficient_wallet' => 'Automatic renewal needs attention because the available wallet balance is insufficient.',
            'price_change_blocked' => 'Automatic renewal was blocked because the current price is outside the accepted renewal policy.',
            'failure' => 'Automatic renewal failed and needs attention.',
            default => throw new RuntimeException('Stored auto-renew notification outcome is invalid.'),
        };
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationSpec  $spec
     * @return array{int,bool}|null
     */
    private function ensureTriggered(object $service, array $spec): ?array
    {
        if ($spec['type'] === ServiceNotificationType::RenewalFailure) {
            return $this->ensureRenewalTriggered($service, $spec);
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($service, $spec): ?array {
            $locked = $this->lockedService($connection, (int) $service->id);
            if (! $this->sameServiceFacts($service, $locked)
                || ! $this->sourceStillAuthoritative($connection, $locked, $spec)) {
                return null;
            }

            return $this->createOrReuseTriggeredState($connection, $locked, $spec);
        }, 3);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationSpec  $spec
     * @return array{int,bool}|null
     */
    private function ensureRenewalTriggered(object $service, array $spec): ?array
    {
        if ($spec['source_type'] !== 'auto_renew_notification_intent' || $spec['source_id'] === null) {
            return null;
        }
        $attemptId = $this->renewalAttemptIdForIntent($spec['source_id']);
        if ($attemptId === null) {
            return null;
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($service, $spec, $attemptId): ?array {
            // Auto-renew reconciliation and mutation completion acquire Attempt -> Service.
            // Notification paths that need both rows must preserve that order rather than creating
            // a Service -> Attempt inversion.
            $attempt = $this->lockedAutoRenewAttempt($connection, $attemptId);
            $locked = $this->lockedService($connection, (int) $service->id);
            if (! $this->sameServiceFacts($service, $locked)
                || ! $this->renewalSourceStillAuthoritative($connection, $locked, $spec, $attempt)) {
                return null;
            }

            return $this->createOrReuseTriggeredState($connection, $locked, $spec);
        }, 3);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationSpec  $spec
     * @return array{int,bool}
     */
    private function createOrReuseTriggeredState(Connection $connection, object $service, array $spec): array
    {
        /** @var object{id:int|string}|null $existing */
        $existing = $connection->table('service_notification_states')
            ->where('episode_key_hash', $spec['episode'])
            ->lockForUpdate()
            ->first(['id']);
        if ($existing !== null) {
            return [(int) $existing->id, false];
        }

        $correlationId = 'service-notification:trigger:'.substr($spec['episode'], 0, 24);
        $timestamp = $this->timestamp();
        ServiceNotificationDatabaseAuthority::create(
            $connection,
            (int) $service->id,
            $spec['episode'],
            $spec['type']->value,
            $spec['threshold'],
            $spec['cycle'],
            $spec['source_type'],
            $spec['source_id'],
            $timestamp,
            $correlationId,
            $spec['type'] === ServiceNotificationType::LowBalance
                ? $this->boundedConfigInt('service_notifications.low_balance_irr', 0, 1, PHP_INT_MAX)
                : null,
        );
        try {
            $stateId = (int) $connection->table('service_notification_states')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'service_subscription_id' => (int) $service->id,
                'episode_key_hash' => $spec['episode'],
                'notification_type' => $spec['type']->value,
                'threshold_code' => $spec['threshold'],
                'cycle_key_hash' => $spec['cycle'],
                'source_type' => $spec['source_type'],
                'source_id' => $spec['source_id'],
                'state' => ServiceNotificationState::Triggered->value,
                'latest_delivery_attempt_id' => null,
                'latest_retry_ordinal' => null,
                'next_retry_at' => null,
                'triggered_at' => $timestamp,
                'notified_at' => null,
                'acknowledged_at' => null,
                'escalated_at' => null,
                'expired_at' => null,
                'last_correlation_id' => $correlationId,
                'updated_at' => $timestamp,
            ]);
            $this->insertEvent(
                $connection,
                $stateId,
                'triggered',
                null,
                ServiceNotificationState::Triggered->value,
                $correlationId,
                $timestamp,
            );
        } finally {
            ServiceNotificationDatabaseAuthority::clear($connection);
        }

        return [$stateId, true];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationSpec  $spec
     */
    private function sourceStillAuthoritative(Connection $connection, object $service, array $spec): bool
    {
        if ($spec['type'] === ServiceNotificationType::Expiry) {
            if ($spec['source_type'] !== 'service_sync_snapshot' || $spec['source_id'] === null) {
                return false;
            }
            /** @var object{id:int|string,remote_disposition:string,remote_expires_at:string|null}|null $snapshot */
            $snapshot = $connection->table('service_sync_snapshots')
                ->where('service_subscription_id', (int) $service->id)
                ->where('local_lifecycle_version', (int) $service->lifecycle_version)
                ->where('local_remote_identity_generation', (int) $service->remote_identity_generation)
                ->where('local_mutation_generation', (int) $service->mutation_generation)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first(['id', 'remote_disposition', 'remote_expires_at']);
            if ($snapshot === null
                || (int) $snapshot->id !== $spec['source_id']
                || $snapshot->remote_disposition !== 'present'
                || $snapshot->remote_expires_at === null) {
                return false;
            }
            $current = $this->expirySpecFromSnapshot($service, (int) $snapshot->id, $snapshot->remote_expires_at);

            return $current !== null && $this->sameSpecIdentity($current, $spec);
        }

        if ($spec['type'] !== ServiceNotificationType::LowBalance
            || $spec['source_type'] !== 'wallet_balance'
            || $spec['source_id'] === null) {
            return false;
        }
        $threshold = $this->boundedConfigInt('service_notifications.low_balance_irr', 0, 0, PHP_INT_MAX);
        if ($threshold === 0) {
            return false;
        }
        $wallet = $this->cashWalletObservation((int) $service->user_id);
        if ($wallet === null
            || $wallet['account_id'] !== $spec['source_id']
            || $wallet['available_balance'] >= $threshold) {
            return false;
        }
        $cycle = hash('sha256', implode('|', [
            'service-notification-low-balance-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            (string) $threshold,
        ]));
        $current = [
            'type' => ServiceNotificationType::LowBalance,
            'threshold' => 'low_balance',
            'cycle' => $cycle,
            'episode' => $this->episodeKey($service, ServiceNotificationType::LowBalance, 'low_balance', $cycle),
            'source_type' => 'wallet_balance',
            'source_id' => $wallet['account_id'],
            'message' => 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.',
        ];

        return $this->sameSpecIdentity($current, $spec);
    }

    /**
     * @param  NotificationSpec  $left
     * @param  NotificationSpec  $right
     */
    private function sameSpecIdentity(array $left, array $right): bool
    {
        return $left['type'] === $right['type']
            && $left['threshold'] === $right['threshold']
            && hash_equals($left['cycle'], $right['cycle'])
            && hash_equals($left['episode'], $right['episode'])
            && $left['source_type'] === $right['source_type']
            && $left['source_id'] === $right['source_id'];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  list<string>  $activeEpisodes
     */
    private function expireStaleTriggeredStates(object $service, array $activeEpisodes): int
    {
        /** @var list<object{id:int|string,episode_key_hash:string}> $rows */
        $rows = $this->database->connection()->table('service_notification_states')
            ->where('service_subscription_id', (int) $service->id)
            ->where('state', ServiceNotificationState::Triggered->value)
            ->get(['id', 'episode_key_hash'])
            ->all();
        $expired = 0;
        foreach ($rows as $row) {
            if (! in_array($row->episode_key_hash, $activeEpisodes, true)
                && $this->transitionState(
                    (int) $row->id,
                    (int) $service->id,
                    ServiceNotificationState::Expired,
                    'service-notification:expired:'.substr($row->episode_key_hash, 0, 24),
                )) {
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function reconcileTriggeredState(object $service, object $state): string
    {
        if ($state->latest_delivery_attempt_id === null) {
            return $this->queueState($service, $state, 0);
        }

        /** @var DeliveryEffectRow|null $effect */
        $effect = $this->database->connection()->table('service_delivery_effects')
            ->where('service_delivery_attempt_id', (int) $state->latest_delivery_attempt_id)
            ->first(['state', 'completed_at', 'retry_after_seconds']);
        if ($effect === null) {
            return $this->reconcileNonTerminalDeliveryOutbox($service, $state);
        }

        $effectState = ServiceDeliveryEffectState::tryFrom($effect->state)
            ?? throw new RuntimeException('Stored notification delivery effect state is invalid.');
        if ($effectState === ServiceDeliveryEffectState::Prepared) {
            return $this->reconcileNonTerminalDeliveryOutbox($service, $state);
        }
        if ($effectState === ServiceDeliveryEffectState::Succeeded) {
            return $this->transitionState(
                (int) $state->id,
                (int) $service->id,
                ServiceNotificationState::Notified,
                'service-notification:notified:'.substr($state->episode_key_hash, 0, 24),
            ) ? 'notified' : 'waiting';
        }
        if ($effectState === ServiceDeliveryEffectState::Uncertain) {
            return $this->transitionState(
                (int) $state->id,
                (int) $service->id,
                ServiceNotificationState::Escalated,
                'service-notification:uncertain:'.substr($state->episode_key_hash, 0, 24),
            ) ? 'escalated' : 'waiting';
        }
        if ($effectState !== ServiceDeliveryEffectState::FailedFinal) {
            return 'waiting';
        }
        if ($effect->retry_after_seconds !== null) {
            return $this->transitionState(
                (int) $state->id,
                (int) $service->id,
                ServiceNotificationState::Escalated,
                'service-notification:provider-retry-fenced:'.substr($state->episode_key_hash, 0, 21),
            ) ? 'escalated' : 'waiting';
        }

        $ordinal = $state->latest_retry_ordinal === null ? 0 : (int) $state->latest_retry_ordinal;
        $type = ServiceNotificationType::tryFrom($state->notification_type)
            ?? throw new RuntimeException('Stored Service notification type is invalid.');
        if ($ordinal >= $this->maxRetries($type)) {
            return $this->transitionState(
                (int) $state->id,
                (int) $service->id,
                ServiceNotificationState::Escalated,
                'service-notification:exhausted:'.substr($state->episode_key_hash, 0, 24),
            ) ? 'escalated' : 'waiting';
        }

        if ($state->next_retry_at === null) {
            if ($effect->completed_at === null) {
                throw new RuntimeException('Failed notification delivery effect is missing completion evidence.');
            }
            $completedAt = new DateTimeImmutable($effect->completed_at, new DateTimeZone('UTC'));
            $delay = $this->retryDelaySeconds($type, $ordinal);
            $this->scheduleRetry(
                (int) $state->id,
                (int) $service->id,
                $completedAt->modify('+'.$delay.' seconds'),
                'service-notification:retry:'.substr($state->episode_key_hash, 0, 24),
            );

            return 'waiting';
        }

        $dueAt = new DateTimeImmutable($state->next_retry_at, new DateTimeZone('UTC'));
        if ($dueAt > $this->clock->now()) {
            return 'waiting';
        }

        return $this->queueState($service, $state, $ordinal + 1);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function reconcileNonTerminalDeliveryOutbox(object $service, object $state): string
    {
        $dispatchState = $this->notificationOutboxDispatchState(
            $this->database->connection(),
            $service,
            $state,
            false,
        );

        return match ($dispatchState) {
            'pending', 'leased', 'retry' => 'waiting',
            'review_required' => $this->transitionState(
                (int) $state->id,
                (int) $service->id,
                ServiceNotificationState::Escalated,
                'service-notification:outbox-review:'.substr($state->episode_key_hash, 0, 24),
            ) ? 'escalated' : 'waiting',
            'authority_pending' => throw new RuntimeException('Service notification Outbox command remained authority-pending after queue commit.'),
            'processed' => throw new RuntimeException('Processed Service notification Outbox command does not have a terminal Delivery Effect.'),
            default => throw new RuntimeException('Stored Service notification Outbox dispatch state is invalid.'),
        };
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function notificationOutboxDispatchState(
        Connection $connection,
        object $service,
        object $state,
        bool $lock,
    ): string {
        /** @var object{outbox_event_id:string}|null $attempt */
        $attempt = $connection->table('service_delivery_attempts')
            ->where('id', (int) $state->latest_delivery_attempt_id)
            ->where('service_subscription_id', (int) $service->id)
            ->where('purpose', ServiceDeliveryPurpose::Notification->value)
            ->first(['outbox_event_id']);
        if ($attempt === null || $attempt->outbox_event_id === '') {
            throw new RuntimeException('Service notification Delivery Attempt lost its Outbox authority.');
        }

        $query = $connection->table('outbox_messages')->where('id', $attempt->outbox_event_id);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var object{dispatch_state:string}|null $outbox */
        $outbox = $query->first(['dispatch_state']);
        if ($outbox === null) {
            throw new RuntimeException('Service notification Delivery Attempt lost its Outbox authority.');
        }
        if (! in_array($outbox->dispatch_state, [
            'authority_pending', 'pending', 'leased', 'retry', 'processed', 'review_required',
        ], true)) {
            throw new RuntimeException('Stored Service notification Outbox dispatch state is invalid.');
        }

        return $outbox->dispatch_state;
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function queueState(object $service, object $state, int $ordinal): string
    {
        $requestKey = 'service-notification:'.$state->public_id.':'.$ordinal;
        try {
            $receipt = $this->delivery->queueNotification(
                (int) $state->id,
                (string) $service->public_id,
                $ordinal,
                $requestKey,
                $requestKey,
                $this->presentationFor($state),
            );
        } catch (ServiceDeliveryTemporarilyBlockedException) {
            return 'waiting';
        }

        return $receipt->replayed ? 'waiting' : 'queued';
    }

    /** @param  NotificationStateRow  $state */
    private function presentationFor(object $state): string
    {
        $type = ServiceNotificationType::tryFrom($state->notification_type)
            ?? throw new RuntimeException('Stored Service notification type is invalid.');

        return match ($type) {
            ServiceNotificationType::Expiry => match ($state->threshold_code) {
                'expiry_due' => 'Service expiry warning: your service has reached its recorded expiry time.',
                'expiry_1d' => 'Service expiry warning: your service expires within 1 day.',
                'expiry_3d' => 'Service expiry warning: your service expires within 3 days.',
                'expiry_7d' => 'Service expiry warning: your service expires within 7 days.',
                default => throw new RuntimeException('Stored Service expiry notification threshold is invalid.'),
            },
            ServiceNotificationType::LowBalance => 'Wallet balance warning: your available wallet balance is below the configured renewal threshold.',
            ServiceNotificationType::RenewalFailure => match ($state->threshold_code) {
                'renewal_insufficient_wallet' => 'Automatic renewal needs attention because the available wallet balance is insufficient.',
                'renewal_price_change_blocked' => 'Automatic renewal was blocked because the current price is outside the accepted renewal policy.',
                'renewal_failure' => 'Automatic renewal failed and needs attention.',
                default => throw new RuntimeException('Stored auto-renew notification threshold is invalid.'),
            },
        };
    }

    private function scheduleRetry(
        int $stateId,
        int $serviceId,
        DateTimeImmutable $nextRetryAt,
        string $correlationId,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use (
            $stateId,
            $serviceId,
            $nextRetryAt,
            $correlationId,
        ): void {
            $this->lockService($connection, $serviceId);
            /** @var NotificationStateRow|null $state */
            $state = $connection->table('service_notification_states')
                ->where('id', $stateId)
                ->where('service_subscription_id', $serviceId)
                ->lockForUpdate()
                ->first($this->stateColumns());
            if ($state === null || $state->state !== ServiceNotificationState::Triggered->value) {
                return;
            }
            if ($state->next_retry_at !== null) {
                return;
            }

            $timestamp = $this->timestamp();
            $nextRetryAtValue = $this->databaseDateTime($nextRetryAt);
            ServiceNotificationDatabaseAuthority::scheduleRetry(
                $connection,
                $stateId,
                $serviceId,
                $nextRetryAtValue,
                $timestamp,
                $correlationId,
            );
            try {
                $updated = $connection->table('service_notification_states')
                    ->where('id', $stateId)
                    ->where('state', ServiceNotificationState::Triggered->value)
                    ->whereNull('next_retry_at')
                    ->update([
                        'next_retry_at' => $nextRetryAtValue,
                        'last_correlation_id' => $correlationId,
                        'updated_at' => $timestamp,
                    ]);
                if ($updated !== 1) {
                    return;
                }
                $this->insertEvent(
                    $connection,
                    $stateId,
                    'retry_scheduled',
                    ServiceNotificationState::Triggered->value,
                    ServiceNotificationState::Triggered->value,
                    $correlationId,
                    $timestamp,
                );
            } finally {
                ServiceNotificationDatabaseAuthority::clear($connection);
            }
        }, 3);
    }

    private function transitionState(
        int $stateId,
        int $serviceId,
        ServiceNotificationState $next,
        string $correlationId,
    ): bool {
        if ($next === ServiceNotificationState::Expired) {
            $renewalLocator = $this->renewalExpirationLocator($stateId, $serviceId);
            if ($renewalLocator !== null) {
                return $this->transitionRenewalFailureToExpired(
                    $stateId,
                    $serviceId,
                    $renewalLocator['source_id'],
                    $renewalLocator['attempt_id'],
                    $correlationId,
                );
            }
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $stateId,
            $serviceId,
            $next,
            $correlationId,
        ): bool {
            $service = $this->lockedService($connection, $serviceId);
            /** @var NotificationStateRow|null $state */
            $state = $connection->table('service_notification_states')
                ->where('id', $stateId)
                ->where('service_subscription_id', $serviceId)
                ->lockForUpdate()
                ->first($this->stateColumns());
            if ($state === null || $state->state === $next->value) {
                return false;
            }
            if ($next === ServiceNotificationState::Expired
                && $state->notification_type === ServiceNotificationType::RenewalFailure->value) {
                // Renewal expiration must enter through transitionRenewalFailureToExpired(),
                // which acquires the auto-renew Attempt lock before the Service lock.
                return false;
            }

            return $this->transitionLockedState($connection, $service, $state, $next, $correlationId, false);
        }, 3);
    }

    private function transitionRenewalFailureToExpired(
        int $stateId,
        int $serviceId,
        int $sourceId,
        int $attemptId,
        string $correlationId,
    ): bool {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $stateId,
            $serviceId,
            $sourceId,
            $attemptId,
            $correlationId,
        ): bool {
            // Match the canonical auto-renew lock order: Attempt -> Service -> notification state.
            $attempt = $this->lockedAutoRenewAttempt($connection, $attemptId);
            $service = $this->lockedService($connection, $serviceId);
            /** @var NotificationStateRow|null $state */
            $state = $connection->table('service_notification_states')
                ->where('id', $stateId)
                ->where('service_subscription_id', $serviceId)
                ->lockForUpdate()
                ->first($this->stateColumns());
            if ($state === null || $state->state === ServiceNotificationState::Expired->value) {
                return false;
            }
            if ($state->state !== ServiceNotificationState::Triggered->value
                || $state->notification_type !== ServiceNotificationType::RenewalFailure->value
                || $state->source_type !== 'auto_renew_notification_intent'
                || $state->source_id === null
                || (int) $state->source_id !== $sourceId
                || ! $this->canExpireRenewalFailureState($connection, $service, $state, $attempt)) {
                return false;
            }

            return $this->transitionLockedState(
                $connection,
                $service,
                $state,
                ServiceNotificationState::Expired,
                $correlationId,
                true,
            );
        }, 3);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function transitionLockedState(
        Connection $connection,
        object $service,
        object $state,
        ServiceNotificationState $next,
        string $correlationId,
        bool $renewalExpirationRevalidated,
    ): bool {
        $current = ServiceNotificationState::tryFrom($state->state)
            ?? throw new RuntimeException('Stored Service notification state is invalid.');
        $allowed = ($current === ServiceNotificationState::Triggered
                && in_array($next, [ServiceNotificationState::Notified, ServiceNotificationState::Escalated, ServiceNotificationState::Expired], true))
            || (in_array($current, [ServiceNotificationState::Notified, ServiceNotificationState::Escalated], true)
                && $next === ServiceNotificationState::Acknowledged);
        if (! $allowed) {
            return false;
        }
        if ($next === ServiceNotificationState::Expired
            && $state->notification_type === ServiceNotificationType::RenewalFailure->value
            && ! $renewalExpirationRevalidated) {
            return false;
        }
        if ($next === ServiceNotificationState::Expired
            && ! $this->canExpireTriggeredState($connection, $service, $state)) {
            return false;
        }

        $timestamp = $this->timestamp();
        $values = [
            'state' => $next->value,
            'next_retry_at' => null,
            'last_correlation_id' => $correlationId,
            'updated_at' => $timestamp,
        ];
        $values[match ($next) {
            ServiceNotificationState::Notified => 'notified_at',
            ServiceNotificationState::Acknowledged => 'acknowledged_at',
            ServiceNotificationState::Escalated => 'escalated_at',
            default => 'expired_at',
        }] = $timestamp;

        ServiceNotificationDatabaseAuthority::transition(
            $connection,
            (int) $state->id,
            (int) $service->id,
            $current->value,
            $next->value,
            $timestamp,
            $correlationId,
        );
        try {
            $updated = $connection->table('service_notification_states')
                ->where('id', (int) $state->id)
                ->where('state', $current->value)
                ->update($values);
            if ($updated !== 1) {
                return false;
            }
            $this->insertEvent(
                $connection,
                (int) $state->id,
                $next->value,
                $current->value,
                $next->value,
                $correlationId,
                $timestamp,
            );
        } finally {
            ServiceNotificationDatabaseAuthority::clear($connection);
        }

        return true;
    }

    /** @return array{source_id:int,attempt_id:int}|null */
    private function renewalExpirationLocator(int $stateId, int $serviceId): ?array
    {
        /** @var object{source_id:int|string,auto_renew_attempt_id:int|string}|null $locator */
        $locator = $this->database->connection()->table('service_notification_states as state')
            ->join('service_auto_renew_notification_intents as intent', 'intent.id', '=', 'state.source_id')
            ->where('state.id', $stateId)
            ->where('state.service_subscription_id', $serviceId)
            ->where('state.notification_type', ServiceNotificationType::RenewalFailure->value)
            ->where('state.source_type', 'auto_renew_notification_intent')
            ->first(['state.source_id', 'intent.auto_renew_attempt_id']);
        if ($locator === null) {
            return null;
        }

        return [
            'source_id' => (int) $locator->source_id,
            'attempt_id' => (int) $locator->auto_renew_attempt_id,
        ];
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     * @param  AutoRenewAttemptAuthorityRow  $attempt
     */
    private function canExpireRenewalFailureState(
        Connection $connection,
        object $service,
        object $state,
        object $attempt,
    ): bool {
        if ((int) $attempt->service_subscription_id !== (int) $service->id
            || $state->source_id === null) {
            return false;
        }
        /** @var object{id:int|string,auto_renew_attempt_id:int|string,outcome:string,reason_code:string|null}|null $intent */
        $intent = $connection->table('service_auto_renew_notification_intents')
            ->where('id', (int) $state->source_id)
            ->first(['id', 'auto_renew_attempt_id', 'outcome', 'reason_code']);
        if ($intent === null || (int) $intent->auto_renew_attempt_id !== (int) $attempt->id) {
            return false;
        }
        $threshold = 'renewal_'.$intent->outcome;
        $cycle = hash('sha256', implode('|', [
            'service-notification-renewal-cycle-v1',
            (string) $service->id,
            (string) $intent->id,
            $intent->outcome,
            (string) ($intent->reason_code ?? ''),
        ]));
        $episode = $this->episodeKey($service, ServiceNotificationType::RenewalFailure, $threshold, $cycle);
        if ($state->threshold_code !== $threshold
            || ! hash_equals($cycle, $state->cycle_key_hash)
            || ! hash_equals($episode, $state->episode_key_hash)) {
            return false;
        }

        return ! $this->renewalIntentStillApplicable($intent->outcome, (string) $attempt->state);
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationSpec  $spec
     * @param  AutoRenewAttemptAuthorityRow  $attempt
     */
    private function renewalSourceStillAuthoritative(
        Connection $connection,
        object $service,
        array $spec,
        object $attempt,
    ): bool {
        if ($spec['source_type'] !== 'auto_renew_notification_intent'
            || $spec['source_id'] === null
            || (int) $attempt->service_subscription_id !== (int) $service->id) {
            return false;
        }
        /** @var object{id:int|string,auto_renew_attempt_id:int|string,outcome:string,reason_code:string|null}|null $intent */
        $intent = $connection->table('service_auto_renew_notification_intents')
            ->where('id', $spec['source_id'])
            ->first(['id', 'auto_renew_attempt_id', 'outcome', 'reason_code']);
        if ($intent === null
            || (int) $intent->auto_renew_attempt_id !== (int) $attempt->id
            || ! $this->renewalIntentStillApplicable($intent->outcome, (string) $attempt->state)) {
            return false;
        }
        $threshold = 'renewal_'.$intent->outcome;
        $cycle = hash('sha256', implode('|', [
            'service-notification-renewal-cycle-v1',
            (string) $service->id,
            (string) $intent->id,
            $intent->outcome,
            (string) ($intent->reason_code ?? ''),
        ]));
        $current = [
            'type' => ServiceNotificationType::RenewalFailure,
            'threshold' => $threshold,
            'cycle' => $cycle,
            'episode' => $this->episodeKey($service, ServiceNotificationType::RenewalFailure, $threshold, $cycle),
            'source_type' => 'auto_renew_notification_intent',
            'source_id' => (int) $intent->id,
            'message' => $this->renewalMessage($intent->outcome),
        ];

        return $this->sameSpecIdentity($current, $spec);
    }

    private function renewalAttemptIdForIntent(int $intentId): ?int
    {
        $attemptId = $this->database->connection()->table('service_auto_renew_notification_intents')
            ->where('id', $intentId)
            ->value('auto_renew_attempt_id');
        if (! is_int($attemptId) && ! is_string($attemptId)) {
            return null;
        }

        return (int) $attemptId;
    }

    /** @return AutoRenewAttemptAuthorityRow */
    private function lockedAutoRenewAttempt(Connection $connection, int $attemptId): object
    {
        /** @var AutoRenewAttemptAuthorityRow|null $attempt */
        $attempt = $connection->table('service_auto_renew_attempts')
            ->where('id', $attemptId)
            ->lockForUpdate()
            ->first(['id', 'service_subscription_id', 'state']);
        if ($attempt === null) {
            throw new RuntimeException('Auto-renew attempt disappeared during notification processing.');
        }

        return $attempt;
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function canExpireAgainstNotificationOutbox(
        Connection $connection,
        object $service,
        object $state,
    ): bool {
        return match ($this->notificationOutboxDispatchState($connection, $service, $state, true)) {
            'pending', 'leased', 'retry' => true,
            'review_required' => false,
            'authority_pending' => throw new RuntimeException('Service notification Outbox command remained authority-pending after queue commit.'),
            'processed' => throw new RuntimeException('Processed Service notification Outbox command does not have a terminal Delivery Effect.'),
            default => throw new RuntimeException('Stored Service notification Outbox dispatch state is invalid.'),
        };
    }

    /**
     * @param  NotificationServiceRow  $service
     * @param  NotificationStateRow  $state
     */
    private function canExpireTriggeredState(Connection $connection, object $service, object $state): bool
    {
        if ($this->isThresholdEligible($service)
            && $state->notification_type === ServiceNotificationType::LowBalance->value) {
            $current = $this->lowBalanceSpec($service);
            if ($current !== null && hash_equals($current['episode'], $state->episode_key_hash)) {
                return false;
            }
        }

        if ($state->latest_delivery_attempt_id === null) {
            return true;
        }

        /** @var object{state:string,retry_after_seconds:int|string|null}|null $effect */
        $effect = $connection->table('service_delivery_effects')
            ->where('service_delivery_attempt_id', (int) $state->latest_delivery_attempt_id)
            ->lockForUpdate()
            ->first(['state', 'retry_after_seconds']);
        if ($effect === null) {
            return $this->canExpireAgainstNotificationOutbox($connection, $service, $state);
        }

        $effectState = ServiceDeliveryEffectState::tryFrom($effect->state)
            ?? throw new RuntimeException('Stored notification delivery effect state is invalid.');
        if ($effectState === ServiceDeliveryEffectState::Prepared) {
            return $this->canExpireAgainstNotificationOutbox($connection, $service, $state);
        }
        if ($effectState === ServiceDeliveryEffectState::FailedFinal
            && $effect->retry_after_seconds !== null) {
            return false;
        }

        return $effectState === ServiceDeliveryEffectState::FailedFinal;
    }

    private function insertEvent(
        Connection $connection,
        int $stateId,
        string $eventType,
        ?string $fromState,
        string $toState,
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
            'service_delivery_attempt_id' => null,
            'retry_ordinal' => null,
            'correlation_id' => $correlationId,
            'created_at' => $timestamp,
        ]);
    }

    /** @param  NotificationServiceRow  $service */
    private function episodeKey(
        object $service,
        ServiceNotificationType $type,
        string $threshold,
        string $cycle,
    ): string {
        return hash('sha256', implode('|', [
            'service-notification-episode-v1',
            (string) $service->id,
            $type->value,
            $threshold,
            $cycle,
        ]));
    }

    /** @return array{account_id:int,available_balance:int}|null */
    private function cashWalletObservation(int $userId): ?array
    {
        $accountId = $this->database->connection()->table('ledger_accounts')
            ->where('owner_user_id', $userId)
            ->where('wallet_bucket', 'cash')
            ->where('account_class', 'liability')
            ->where('currency', 'IRR')
            ->where('is_active', true)
            ->value('id');
        if (! is_int($accountId) && ! is_string($accountId)) {
            return null;
        }

        $id = (int) $accountId;

        return [
            'account_id' => $id,
            'available_balance' => $this->wallet->balance($userId, $id)->availableBalance->amount,
        ];
    }

    /** @return list<int> */
    private function expiryThresholdDays(): array
    {
        $raw = config('service_notifications.expiry_threshold_days', [7, 3, 1, 0]);
        if (! is_array($raw)) {
            throw new DomainException('Service notification expiry thresholds must be an array.');
        }
        $thresholds = [];
        foreach ($raw as $value) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 365]]);
            if ($validated === false) {
                throw new DomainException('Service notification expiry thresholds must be integers from 0 to 365 days.');
            }
            $thresholds[] = (int) $validated;
        }
        $thresholds = array_values(array_unique($thresholds));
        sort($thresholds, SORT_NUMERIC);

        return $thresholds;
    }

    private function maxRetries(ServiceNotificationType $type): int
    {
        return $this->boundedConfigInt('service_notifications.retry.'.$type->value.'.max_retries', 2, 0, 10);
    }

    private function retryDelaySeconds(ServiceNotificationType $type, int $completedOrdinal): int
    {
        $base = $this->boundedConfigInt(
            'service_notifications.retry.'.$type->value.'.base_delay_seconds',
            60,
            1,
            86400,
        );
        $maximum = $this->boundedConfigInt(
            'service_notifications.retry.'.$type->value.'.max_delay_seconds',
            1800,
            $base,
            604800,
        );
        $delay = $base;
        for ($step = 0; $step < $completedOrdinal && $delay < $maximum; $step++) {
            $delay = min($maximum, $delay > intdiv(PHP_INT_MAX, 2) ? $maximum : $delay * 2);
        }

        return $delay;
    }

    private function boundedConfigInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $validated = filter_var(config($key, $default), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($validated === false) {
            throw new DomainException($key.' is outside its supported range.');
        }

        return (int) $validated;
    }

    /** @return list<NotificationServiceRow> */
    private function candidateServices(int $limit): array
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($limit): array {
            /** @var object{last_service_subscription_id:int|string|null}|null $cursor */
            $cursor = $connection->table('service_notification_scan_cursor')
                ->where('id', 1)
                ->lockForUpdate()
                ->first(['last_service_subscription_id']);
            if ($cursor === null) {
                throw new RuntimeException('Service notification scan cursor is unavailable.');
            }

            $lastServiceId = $cursor->last_service_subscription_id === null
                ? null
                : (int) $cursor->last_service_subscription_id;
            $firstQuery = $this->eligibleCandidateQuery($connection);
            if ($lastServiceId !== null) {
                $firstQuery->where('id', '>', $lastServiceId);
            }

            /** @var list<NotificationServiceRow> $rows */
            $rows = $firstQuery
                ->orderBy('id')
                ->limit($limit)
                ->get($this->serviceColumns())
                ->all();

            if ($lastServiceId !== null && count($rows) < $limit) {
                $remaining = $limit - count($rows);
                /** @var list<NotificationServiceRow> $wrapped */
                $wrapped = $this->eligibleCandidateQuery($connection)
                    ->where('id', '<=', $lastServiceId)
                    ->orderBy('id')
                    ->limit($remaining)
                    ->get($this->serviceColumns())
                    ->all();
                $rows = [...$rows, ...$wrapped];
            }

            if ($rows === []) {
                return [];
            }

            $nextServiceId = (int) $rows[array_key_last($rows)]->id;
            $timestamp = $this->timestamp();
            ServiceNotificationDatabaseAuthority::cursor(
                $connection,
                $lastServiceId,
                $nextServiceId,
                $timestamp,
            );
            try {
                $update = $connection->table('service_notification_scan_cursor')->where('id', 1);
                if ($lastServiceId === null) {
                    $update->whereNull('last_service_subscription_id');
                } else {
                    $update->where('last_service_subscription_id', $lastServiceId);
                }
                $updated = $update->update([
                    'last_service_subscription_id' => $nextServiceId,
                    'updated_at' => $timestamp,
                ]);
                if ($updated !== 1) {
                    // MariaDB reports zero changed rows for an exact no-op. Under the held row lock,
                    // accept that only when the authoritative cursor postcondition already matches.
                    /** @var object{last_service_subscription_id:int|string|null,updated_at:string}|null $currentCursor */
                    $currentCursor = $connection->table('service_notification_scan_cursor')
                        ->where('id', 1)
                        ->first(['last_service_subscription_id', 'updated_at']);
                    if ($updated !== 0
                        || $currentCursor === null
                        || (int) $currentCursor->last_service_subscription_id !== $nextServiceId
                        || $currentCursor->updated_at !== $timestamp) {
                        throw new RuntimeException('Service notification scan cursor lost its current authority.');
                    }
                }
            } finally {
                ServiceNotificationDatabaseAuthority::clear($connection);
            }

            return $rows;
        }, 3);
    }

    private function eligibleCandidateQuery(Connection $connection): Builder
    {
        return $connection->table('service_subscriptions')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $eligible): void {
                    $eligible->whereNotNull('provisioned_at')
                        ->whereNotNull('service_target_id')
                        ->whereNotNull('remote_service_id')
                        ->whereNull('remote_deleted_at')
                        ->whereIn('lifecycle_state', ['active', 'suspended']);
                })->orWhereExists(function (Builder $triggered): void {
                    $triggered->selectRaw('1')
                        ->from('service_notification_states as notification_state')
                        ->whereColumn('notification_state.service_subscription_id', 'service_subscriptions.id')
                        ->where('notification_state.state', ServiceNotificationState::Triggered->value);
                });
            });
    }

    /** @param  NotificationServiceRow  $service */
    private function isThresholdEligible(object $service): bool
    {
        return $service->provisioned_at !== null
            && $service->service_target_id !== null
            && (int) $service->service_target_id > 0
            && is_string($service->remote_service_id)
            && $service->remote_service_id !== ''
            && $service->remote_deleted_at === null
            && in_array($service->lifecycle_state, ['active', 'suspended'], true);
    }

    /** @return list<string> */
    private function stateColumns(): array
    {
        return [
            'id', 'public_id', 'service_subscription_id', 'episode_key_hash', 'notification_type',
            'threshold_code', 'cycle_key_hash', 'source_type', 'source_id', 'state',
            'latest_delivery_attempt_id', 'latest_retry_ordinal', 'next_retry_at',
        ];
    }

    /** @return list<string> */
    private function serviceColumns(): array
    {
        return [
            'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
            'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
        ];
    }

    /** @return NotificationServiceRow */
    private function lockedService(Connection $connection, int $serviceId): object
    {
        /** @var NotificationServiceRow|null $service */
        $service = $connection->table('service_subscriptions')
            ->where('id', $serviceId)
            ->lockForUpdate()
            ->first($this->serviceColumns());
        if ($service === null) {
            throw new RuntimeException('Service Subscription disappeared during notification processing.');
        }

        return $service;
    }

    private function lockService(Connection $connection, int $serviceId): void
    {
        $this->lockedService($connection, $serviceId);
    }

    /**
     * @param  NotificationServiceRow  $left
     * @param  NotificationServiceRow  $right
     */
    private function sameServiceFacts(object $left, object $right): bool
    {
        return (int) $left->id === (int) $right->id
            && $left->public_id === $right->public_id
            && (int) $left->user_id === (int) $right->user_id
            && (int) ($left->service_target_id ?? 0) === (int) ($right->service_target_id ?? 0)
            && $left->remote_service_id === $right->remote_service_id
            && $left->provisioned_at === $right->provisioned_at
            && $left->remote_deleted_at === $right->remote_deleted_at
            && $left->lifecycle_state === $right->lifecycle_state
            && (int) $left->lifecycle_version === (int) $right->lifecycle_version
            && (int) $left->remote_identity_generation === (int) $right->remote_identity_generation
            && (int) $left->mutation_generation === (int) $right->mutation_generation;
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }

    private function databaseDateTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
