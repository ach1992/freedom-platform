<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceNotificationType;
use App\Modules\Wallet\Application\WalletBalanceSnapshot;
use App\Modules\Wallet\Application\WalletHoldService;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type SourceLocator array{
 *     notification_state_id:int,
 *     service_subscription_id:int,
 *     notification_type:string,
 *     source_type:string,
 *     source_id:int,
 *     preliminary_user_id:int,
 *     renewal_attempt_id:?int
 * }
 * @phpstan-type SourceLocatorStateRow object{
 *     notification_state_id:int|string|null,
 *     service_subscription_id:int|string,
 *     notification_type:string|null,
 *     source_type:string|null,
 *     source_id:int|string|null,
 *     preliminary_user_id:int|string|null,
 *     renewal_attempt_id:int|string|null
 * }
 * @phpstan-type DeliverySourceLocatorRow object{
 *     purpose:string,
 *     notification_state_id:int|string|null,
 *     service_subscription_id:int|string,
 *     notification_type:string|null,
 *     source_type:string|null,
 *     source_id:int|string|null,
 *     preliminary_user_id:int|string|null,
 *     renewal_attempt_id:int|string|null
 * }
 * @phpstan-type RenewalAttemptRow object{id:int|string,service_subscription_id:int|string,state:string}
 * @phpstan-type SourceLock array{
 *     locator:SourceLocator,
 *     wallet_balance:?WalletBalanceSnapshot,
 *     renewal_attempt:?RenewalAttemptRow
 * }
 * @phpstan-type NotificationSourceState object{
 *     id:int|string,
 *     service_subscription_id:int|string,
 *     episode_key_hash:string,
 *     notification_type:string,
 *     threshold_code:string,
 *     cycle_key_hash:string,
 *     source_type:string,
 *     source_id:int|string|null,
 *     low_balance_threshold_irr:int|string|null
 * }
 * @phpstan-type NotificationSourceService object{
 *     id:int|string,
 *     user_id:int|string,
 *     lifecycle_version:int|string,
 *     remote_identity_generation:int|string,
 *     mutation_generation:int|string
 * }
 */
final readonly class ServiceNotificationSourceAuthority
{
    public function __construct(
        private Clock $clock,
        private WalletHoldService $wallet,
    ) {}

    /** @return SourceLocator */
    public function locatorForState(Connection $connection, int $notificationStateId): array
    {
        /** @var SourceLocatorStateRow|null $row */
        $row = $connection->table('service_notification_states as state')
            ->join('service_subscriptions as service', 'service.id', '=', 'state.service_subscription_id')
            ->leftJoin('service_auto_renew_notification_intents as intent', function ($join): void {
                $join->on('intent.id', '=', 'state.source_id')
                    ->where('state.source_type', '=', 'auto_renew_notification_intent');
            })
            ->where('state.id', $notificationStateId)
            ->first([
                'state.id as notification_state_id',
                'state.service_subscription_id',
                'state.notification_type',
                'state.source_type',
                'state.source_id',
                'service.user_id as preliminary_user_id',
                'intent.auto_renew_attempt_id as renewal_attempt_id',
            ]);
        if ($row === null) {
            throw new ServiceNotificationCandidateInvalidatedException('Service notification source authority no longer exists.');
        }

        return $this->normalizeLocator($row);
    }

    /** @return SourceLocator|null */
    public function locatorForDeliveryAttempt(Connection $connection, int $deliveryAttemptId): ?array
    {
        /** @var DeliverySourceLocatorRow|null $row */
        $row = $connection->table('service_delivery_attempts as attempt')
            ->leftJoin('service_notification_delivery_bindings as binding', 'binding.service_delivery_attempt_id', '=', 'attempt.id')
            ->leftJoin('service_notification_states as state', 'state.id', '=', 'binding.service_notification_state_id')
            ->leftJoin('service_subscriptions as service', 'service.id', '=', 'attempt.service_subscription_id')
            ->leftJoin('service_auto_renew_notification_intents as intent', function ($join): void {
                $join->on('intent.id', '=', 'state.source_id')
                    ->where('state.source_type', '=', 'auto_renew_notification_intent');
            })
            ->where('attempt.id', $deliveryAttemptId)
            ->first([
                'attempt.purpose',
                'state.id as notification_state_id',
                'attempt.service_subscription_id',
                'state.notification_type',
                'state.source_type',
                'state.source_id',
                'service.user_id as preliminary_user_id',
                'intent.auto_renew_attempt_id as renewal_attempt_id',
            ]);
        if ($row === null) {
            throw new RuntimeException('Service Delivery Attempt disappeared while locating notification source authority.');
        }
        if ($row->purpose !== 'notification') {
            return null;
        }
        if ($row->notification_state_id === null) {
            throw new ServiceNotificationCandidateInvalidatedException('Notification Delivery Attempt lost its durable source binding.');
        }

        return $this->normalizeLocator($row);
    }

    /**
     * Lock source authority that must precede the Service lock.
     *
     * @param  SourceLocator  $locator
     * @return SourceLock
     */
    public function lockBeforeService(Connection $connection, array $locator): array
    {
        $walletBalance = null;
        $renewalAttempt = null;

        if ($locator['source_type'] === 'wallet_balance') {
            try {
                $walletBalance = $this->wallet->balanceOnLockedAccount(
                    $connection,
                    $locator['preliminary_user_id'],
                    $locator['source_id'],
                );
            } catch (DomainException $exception) {
                throw new ServiceNotificationCandidateInvalidatedException(
                    'Service low-balance notification source is no longer authoritative.',
                    previous: $exception,
                );
            }
        } elseif ($locator['source_type'] === 'auto_renew_notification_intent') {
            if ($locator['renewal_attempt_id'] === null) {
                throw new ServiceNotificationCandidateInvalidatedException('Service renewal notification intent lost its Attempt authority.');
            }
            /** @var RenewalAttemptRow|null $renewalAttempt */
            $renewalAttempt = $connection->table('service_auto_renew_attempts')
                ->where('id', $locator['renewal_attempt_id'])
                ->lockForUpdate()
                ->first(['id', 'service_subscription_id', 'state']);
            if ($renewalAttempt === null) {
                throw new ServiceNotificationCandidateInvalidatedException('Service renewal notification Attempt no longer exists.');
            }
        }

        return [
            'locator' => $locator,
            'wallet_balance' => $walletBalance,
            'renewal_attempt' => $renewalAttempt,
        ];
    }

    /**
     * @param  NotificationSourceService  $service
     * @param  NotificationSourceState  $state
     * @param  SourceLock  $sourceLock
     */
    public function assertCurrent(
        Connection $connection,
        object $service,
        object $state,
        array $sourceLock,
    ): void {
        $locator = $sourceLock['locator'];
        if ((int) $state->id !== $locator['notification_state_id']
            || (int) $state->service_subscription_id !== $locator['service_subscription_id']
            || (int) $service->id !== $locator['service_subscription_id']
            || $state->notification_type !== $locator['notification_type']
            || $state->source_type !== $locator['source_type']
            || $state->source_id === null
            || (int) $state->source_id !== $locator['source_id']) {
            throw new ServiceNotificationCandidateInvalidatedException('Service notification source identity changed before delivery.');
        }

        match ($state->notification_type) {
            ServiceNotificationType::Expiry->value => $this->assertExpiryCurrent($connection, $service, $state),
            ServiceNotificationType::LowBalance->value => $this->assertLowBalanceCurrent($connection, $service, $state, $sourceLock),
            ServiceNotificationType::RenewalFailure->value => $this->assertRenewalCurrent($connection, $service, $state, $sourceLock),
            default => throw new RuntimeException('Stored Service notification type is invalid.'),
        };
    }

    /**
     * @param  NotificationSourceService  $service
     * @param  NotificationSourceState  $state
     */
    private function assertExpiryCurrent(Connection $connection, object $service, object $state): void
    {
        if ($state->source_type !== 'service_sync_snapshot') {
            throw new RuntimeException('Stored Service expiry notification source type is invalid.');
        }
        /** @var object{remote_disposition:string,remote_expires_at:?string}|null $snapshot */
        $snapshot = $connection->table('service_sync_snapshots')
            ->where('service_subscription_id', (int) $service->id)
            ->where('local_lifecycle_version', (int) $service->lifecycle_version)
            ->where('local_remote_identity_generation', (int) $service->remote_identity_generation)
            ->where('local_mutation_generation', (int) $service->mutation_generation)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first(['remote_disposition', 'remote_expires_at']);
        if ($snapshot === null || $snapshot->remote_disposition !== 'present' || $snapshot->remote_expires_at === null) {
            throw new ServiceNotificationCandidateInvalidatedException('Service expiry notification snapshot is no longer authoritative.');
        }

        $expiresAt = new DateTimeImmutable($snapshot->remote_expires_at, new DateTimeZone('UTC'));
        $cycle = hash('sha256', implode('|', [
            'service-notification-expiry-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            $expiresAt->format('Y-m-d H:i:s.u'),
        ]));
        $this->assertIdentity($service, $state, ServiceNotificationType::Expiry, $state->threshold_code, $cycle);

        $now = $this->clock->now();
        $crossedNextBoundary = match ($state->threshold_code) {
            'expiry_7d' => $expiresAt <= $now->modify('+3 days'),
            'expiry_3d' => $expiresAt <= $now->modify('+1 day'),
            'expiry_1d' => $expiresAt <= $now,
            'expiry_due' => false,
            default => throw new RuntimeException('Stored Service expiry notification threshold is invalid.'),
        };
        if ($crossedNextBoundary) {
            throw new ServiceNotificationCandidateInvalidatedException('Service expiry notification crossed its durable episode boundary.');
        }
    }

    /**
     * @param  NotificationSourceService  $service
     * @param  NotificationSourceState  $state
     * @param  SourceLock  $sourceLock
     */
    private function assertLowBalanceCurrent(
        Connection $connection,
        object $service,
        object $state,
        array $sourceLock,
    ): void {
        if ($state->source_type !== 'wallet_balance' || $sourceLock['wallet_balance'] === null) {
            throw new RuntimeException('Stored Service low-balance notification source type is invalid.');
        }
        if ($state->low_balance_threshold_irr === null) {
            throw new RuntimeException('Stored Service low-balance notification threshold evidence is missing.');
        }
        $threshold = filter_var($state->low_balance_threshold_irr, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);
        if ($threshold === false) {
            throw new RuntimeException('Stored Service low-balance notification threshold evidence is invalid.');
        }
        if ((int) $service->user_id !== $sourceLock['locator']['preliminary_user_id']
            || $sourceLock['wallet_balance']->availableBalance->amount >= (int) $threshold) {
            throw new ServiceNotificationCandidateInvalidatedException('Service low-balance notification source is no longer authoritative.');
        }
        $activeCashWalletId = $connection->table('ledger_accounts')
            ->where('owner_user_id', (int) $service->user_id)
            ->where('wallet_bucket', 'cash')
            ->where('account_class', 'liability')
            ->where('currency', 'IRR')
            ->where('is_active', true)
            ->value('id');
        if ((! is_int($activeCashWalletId) && ! is_string($activeCashWalletId))
            || (int) $activeCashWalletId !== (int) $state->source_id) {
            throw new ServiceNotificationCandidateInvalidatedException('Service cash Wallet authority changed before notification delivery.');
        }

        $cycle = hash('sha256', implode('|', [
            'service-notification-low-balance-cycle-v1',
            (string) $service->id,
            (string) $service->remote_identity_generation,
            (string) $service->mutation_generation,
            (string) $service->lifecycle_version,
            (string) (int) $threshold,
        ]));
        $this->assertIdentity($service, $state, ServiceNotificationType::LowBalance, 'low_balance', $cycle);
    }

    /**
     * @param  NotificationSourceService  $service
     * @param  NotificationSourceState  $state
     * @param  SourceLock  $sourceLock
     */
    private function assertRenewalCurrent(
        Connection $connection,
        object $service,
        object $state,
        array $sourceLock,
    ): void {
        if ($state->source_type !== 'auto_renew_notification_intent'
            || $sourceLock['renewal_attempt'] === null) {
            throw new RuntimeException('Stored Service renewal notification source type is invalid.');
        }
        $attempt = $sourceLock['renewal_attempt'];
        if ((int) $attempt->service_subscription_id !== (int) $service->id) {
            throw new ServiceNotificationCandidateInvalidatedException('Service renewal notification Attempt changed Service authority.');
        }
        /** @var object{auto_renew_attempt_id:int|string,outcome:string,reason_code:?string}|null $intent */
        $intent = $connection->table('service_auto_renew_notification_intents')
            ->where('id', (int) $state->source_id)
            ->first(['auto_renew_attempt_id', 'outcome', 'reason_code']);
        if ($intent === null
            || (int) $intent->auto_renew_attempt_id !== (int) $attempt->id
            || ! $this->renewalIntentStillApplicable($intent->outcome, (string) $attempt->state)) {
            throw new ServiceNotificationCandidateInvalidatedException('Service renewal notification intent is no longer authoritative.');
        }

        $threshold = 'renewal_'.$intent->outcome;
        $cycle = hash('sha256', implode('|', [
            'service-notification-renewal-cycle-v1',
            (string) $service->id,
            (string) $state->source_id,
            $intent->outcome,
            (string) ($intent->reason_code ?? ''),
        ]));
        $this->assertIdentity($service, $state, ServiceNotificationType::RenewalFailure, $threshold, $cycle);
    }

    /**
     * @param  NotificationSourceService  $service
     * @param  NotificationSourceState  $state
     */
    private function assertIdentity(
        object $service,
        object $state,
        ServiceNotificationType $type,
        string $threshold,
        string $cycle,
    ): void {
        $episode = hash('sha256', implode('|', [
            'service-notification-episode-v1',
            (string) $service->id,
            $type->value,
            $threshold,
            $cycle,
        ]));
        if ($state->threshold_code !== $threshold
            || ! hash_equals($state->cycle_key_hash, $cycle)
            || ! hash_equals($state->episode_key_hash, $episode)) {
            throw new ServiceNotificationCandidateInvalidatedException('Service notification episode is stale for current source authority.');
        }
    }

    /**
     * @param  SourceLocatorStateRow|DeliverySourceLocatorRow  $row
     * @return SourceLocator
     */
    private function normalizeLocator(object $row): array
    {
        foreach (['notification_state_id', 'service_subscription_id', 'source_id', 'preliminary_user_id'] as $field) {
            if (! isset($row->{$field}) || (int) $row->{$field} < 1) {
                throw new RuntimeException('Stored Service notification source locator is invalid.');
            }
        }
        if (! in_array($row->source_type, [
            'service_sync_snapshot',
            'wallet_balance',
            'auto_renew_notification_intent',
        ], true)) {
            throw new RuntimeException('Stored Service notification source locator type is invalid.');
        }

        return [
            'notification_state_id' => (int) $row->notification_state_id,
            'service_subscription_id' => (int) $row->service_subscription_id,
            'notification_type' => (string) $row->notification_type,
            'source_type' => (string) $row->source_type,
            'source_id' => (int) $row->source_id,
            'preliminary_user_id' => (int) $row->preliminary_user_id,
            'renewal_attempt_id' => $row->renewal_attempt_id === null ? null : (int) $row->renewal_attempt_id,
        ];
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
}
