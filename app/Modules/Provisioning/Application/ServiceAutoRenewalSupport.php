<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

trait ServiceAutoRenewalSupport
{
    /** @param ServiceAutoRenewConfigurationFacts $facts */
    private function cycleKey(object $facts): string
    {
        if (! $this->hasCompleteCycleEvidence($facts)) {
            throw new RuntimeException('Auto-renew configuration lacks complete cycle evidence.');
        }

        // Renewal-cycle identity intentionally excludes the remote snapshot hash. Usage and
        // other remote fields can change while the expiry is unchanged; they are evidence, not a
        // new renewal cycle. Identity is the accepted config version + remote identity + expiry.
        return hash('sha256', implode('|', [
            (string) $facts->config_id,
            (string) $facts->service_subscription_id,
            (string) $facts->configuration_version,
            (string) $facts->observed_remote_identity_generation,
            $this->databaseDateTime($this->storedDateTime((string) $facts->observed_expires_at)),
        ]));
    }

    /** @param ServiceAutoRenewConfigurationFacts $facts */
    private function hasCompleteCycleEvidence(object $facts): bool
    {
        return $facts->observed_expires_at !== null
            && $facts->observed_expiry_evidence_hash !== null
            && $facts->observed_expiry_source !== null
            && $facts->observed_remote_identity_generation !== null;
    }

    /** @param ServiceAutoRenewConfigurationFacts $facts */
    private function runtimeEligible(object $facts): bool
    {
        return (bool) $facts->enabled
            && in_array($facts->account_type, ['customer', 'agent'], true)
            && in_array($facts->lifecycle_state, ['active', 'suspended'], true)
            && $facts->remote_deleted_at === null
            && $facts->provisioned_at !== null
            && $facts->service_target_id !== null
            && is_string($facts->remote_service_id) && $facts->remote_service_id !== ''
            && $facts->offering_state === 'active'
            && (bool) $facts->auto_renew_allowed
            && $facts->package_type === 'renewal'
            && $facts->package_duration_days !== null
            && (int) $facts->package_duration_days > 0
            && $this->hasCompleteCycleEvidence($facts)
            && (int) $facts->observed_remote_identity_generation === (int) $facts->remote_identity_generation;
    }

    /** @return ServiceAutoRenewConfigurationFacts */
    private function configurationFacts(int $configurationId): object
    {
        return $this->configurationFactsOn($this->database->connection(), $configurationId, false);
    }

    /** @return ServiceAutoRenewConfigurationFacts */
    private function configurationFactsOn(Connection $connection, int $configurationId, bool $lock): object
    {
        $query = $connection->table('service_auto_renew_configurations as c')
            ->join('service_subscriptions as s', 's.id', '=', 'c.service_subscription_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->join('plan_offerings as o', 'o.id', '=', 's.plan_offering_id')
            ->join('plan_offering_packages as p', 'p.id', '=', 'c.renewal_package_id')
            ->where('c.id', $configurationId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first([
            'c.id as config_id', 'c.service_subscription_id', 'c.renewal_package_id', 'c.enabled',
            'c.accepted_price_irr', 'c.last_settled_price_irr', 'c.observed_expires_at', 'c.expiry_observed_at',
            'c.observed_expiry_evidence_hash', 'c.observed_expiry_source', 'c.observed_remote_identity_generation',
            'c.configuration_version', 's.public_id as service_public_id', 's.user_id', 'u.account_type',
            's.plan_offering_id', 's.service_target_id', 's.remote_service_id', 's.provisioned_at',
            's.lifecycle_state', 's.lifecycle_version', 's.remote_identity_generation', 's.remote_deleted_at',
            'o.state as offering_state', 'o.auto_renew_allowed', 'p.code as package_code', 'p.package_type',
            'p.duration_days as package_duration_days',
        ]);
        if ($row === null) {
            throw new DomainException('Auto-renew configuration does not exist.');
        }

        return $row;
    }

    /** @return ServiceAutoRenewAttemptRow */
    private function attempt(int $attemptId): object
    {
        return $this->attemptOn($this->database->connection(), $attemptId, false);
    }

    /** @return ServiceAutoRenewAttemptRow */
    private function attemptOn(Connection $connection, int $attemptId, bool $lock): object
    {
        $query = $connection->table('service_auto_renew_attempts')->where('id', $attemptId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first([
            'id', 'public_id', 'cycle_key', 'auto_renew_configuration_id', 'service_subscription_id',
            'configuration_version', 'remote_identity_generation', 'observed_expires_at',
            'observed_expiry_evidence_hash', 'observed_expiry_source', 'state', 'reason_code',
            'baseline_price_irr', 'current_price_irr', 'quote_id', 'payment_eligibility_decision_id',
            'payment_intent_id', 'purchase_settlement_id', 'provisioning_operation_id', 'correlation_id', 'retry_count', 'next_retry_at', 'completed_at',
        ]);
        if ($row === null) {
            throw new RuntimeException('Auto-renew attempt does not exist.');
        }

        return $row;
    }

    /** @return ServiceAutoRenewAttemptRow|null */
    private function attemptForCycle(string $cycleKey): ?object
    {
        return $this->attemptForCycleOn($this->database->connection(), $cycleKey, false);
    }

    /** @return ServiceAutoRenewAttemptRow|null */
    private function attemptForCycleOn(Connection $connection, string $cycleKey, bool $lock): ?object
    {
        $query = $connection->table('service_auto_renew_attempts')->where('cycle_key', $cycleKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first([
            'id', 'public_id', 'cycle_key', 'auto_renew_configuration_id', 'service_subscription_id',
            'configuration_version', 'remote_identity_generation', 'observed_expires_at',
            'observed_expiry_evidence_hash', 'observed_expiry_source', 'state', 'reason_code',
            'baseline_price_irr', 'current_price_irr', 'quote_id', 'payment_eligibility_decision_id',
            'payment_intent_id', 'purchase_settlement_id', 'provisioning_operation_id', 'correlation_id', 'retry_count', 'next_retry_at', 'completed_at',
        ]);

        return $row;
    }

    /**
     * @param  ServiceAutoRenewAttemptRow  $attempt
     * @param  ServiceAutoRenewConfigurationFacts  $facts
     */
    private function attemptMatchesConfigurationFacts(object $attempt, object $facts): bool
    {
        return (int) $attempt->auto_renew_configuration_id === (int) $facts->config_id
            && (int) $attempt->service_subscription_id === (int) $facts->service_subscription_id
            && (int) $attempt->configuration_version === (int) $facts->configuration_version
            && (int) $attempt->remote_identity_generation === (int) $facts->observed_remote_identity_generation
            && $this->databaseDateTime($this->storedDateTime((string) $attempt->observed_expires_at))
                === $this->databaseDateTime($this->storedDateTime((string) $facts->observed_expires_at));
    }

    /** @return list<int> */
    private function staleUnfinancializedAttemptIds(int $limit): array
    {
        return array_values($this->database->connection()->table('service_auto_renew_attempts as a')
            ->join('service_auto_renew_configurations as c', 'c.id', '=', 'a.auto_renew_configuration_id')
            ->whereIn('a.state', [
                AutoRenewAttemptState::Pending->value,
                AutoRenewAttemptState::RetryPending->value,
                AutoRenewAttemptState::InsufficientWallet->value,
            ])
            ->whereNull('a.payment_intent_id')
            ->whereNull('a.purchase_settlement_id')
            ->whereNull('a.provisioning_operation_id')
            ->where(function ($query): void {
                $query->whereColumn('a.configuration_version', '<>', 'c.configuration_version')
                    ->orWhere('c.enabled', false);
            })
            ->orderBy('a.updated_at')
            ->limit($limit)
            ->pluck('a.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /** @return list<int> */
    private function outstandingMutationAttemptIds(int $limit): array
    {
        return array_values($this->database->connection()->table('service_auto_renew_attempts')
            ->where('state', AutoRenewAttemptState::MutationQueued->value)
            ->whereNotNull('provisioning_operation_id')
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /** @return list<int> */
    private function outstandingFinancialAttemptIds(int $limit): array
    {
        return array_values($this->database->connection()->table('service_auto_renew_attempts')
            ->whereNotIn('state', [
                AutoRenewAttemptState::PriceChangeBlocked->value,
                AutoRenewAttemptState::Succeeded->value,
                AutoRenewAttemptState::Failed->value,
                AutoRenewAttemptState::MutationQueued->value,
            ])
            ->whereNull('provisioning_operation_id')
            ->where(function ($query): void {
                $query->whereNotNull('payment_intent_id')->orWhereNotNull('purchase_settlement_id');
            })
            ->where(function ($query): void {
                $query->whereNotNull('purchase_settlement_id')
                    ->orWhereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $this->timestamp());
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    private function receiptById(int $attemptId, bool $replayed): ServiceAutoRenewAttemptReceipt
    {
        return $this->receipt($this->attempt($attemptId), $replayed);
    }

    /** @param ServiceAutoRenewAttemptRow $attempt */
    private function receipt(object $attempt, bool $replayed): ServiceAutoRenewAttemptReceipt
    {
        $connection = $this->database->connection();
        $servicePublicId = $connection->table('service_subscriptions')
            ->where('id', (int) $attempt->service_subscription_id)
            ->value('public_id');
        if (! is_string($servicePublicId)) {
            throw new RuntimeException('Auto-renew attempt Service disappeared.');
        }

        return new ServiceAutoRenewAttemptReceipt(
            (string) $attempt->public_id,
            $servicePublicId,
            AutoRenewAttemptState::from((string) $attempt->state),
            $attempt->reason_code === null ? null : (string) $attempt->reason_code,
            $this->publicIdFor('quotes', $attempt->quote_id),
            $this->publicIdFor('payment_intents', $attempt->payment_intent_id),
            $this->publicIdFor('purchase_settlements', $attempt->purchase_settlement_id),
            $this->publicIdFor('provisioning_operations', $attempt->provisioning_operation_id),
            $replayed,
        );
    }

    private function publicIdFor(string $table, int|string|null $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (! in_array($table, ['quotes', 'payment_intents', 'purchase_settlements', 'provisioning_operations'], true)) {
            throw new RuntimeException('Auto-renew authority table lookup is invalid.');
        }
        $value = $this->database->connection()->table($table)->where('id', (int) $id)->value('public_id');
        if (! is_string($value)) {
            throw new RuntimeException('Auto-renew bound authority disappeared.');
        }

        return $value;
    }

    private function safeRecordAttemptEvent(int $attemptId, string $reasonCode): void
    {
        try {
            $this->recordSameStateEvent($attemptId, $reasonCode);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @return ServiceAutoRenewPaymentIntentRow|null */
    private function paymentIntentByCreationKey(string $creationKey): ?object
    {
        return $this->database->connection()->table('payment_intents')
            ->where('creation_key', $creationKey)
            ->first(['id', 'public_id', 'state']);
    }

    private function commercialAuthorityGeneration(int $attemptId): int
    {
        return (int) $this->database->connection()->table('service_auto_renew_attempt_events')
            ->where('auto_renew_attempt_id', $attemptId)
            ->whereIn('reason_code', ['commercial_authority_reset_for_requote', 'commercial_authority_reset_before_failure'])
            ->count();
    }

    private function commercialAuthorityKeySuffix(int $attemptId): string
    {
        $generation = $this->commercialAuthorityGeneration($attemptId);

        return $generation === 0 ? '' : '.r'.$generation;
    }

    private function paymentIntentCreationKey(string $cycleKey, int $attemptId): string
    {
        return 'service.auto-renew.intent.'.$cycleKey.$this->commercialAuthorityKeySuffix($attemptId);
    }

    private function mutationQueueFailureIsRetryable(string $message): bool
    {
        return str_starts_with($message, 'Paid Service mutation is blocked')
            || $message === 'Service has an unresolved mutation operation.';
    }

    /** @param array<string,int> $counters */
    private function countReceipt(ServiceAutoRenewAttemptReceipt $receipt, array &$counters, bool $attempted): void
    {
        if ($attempted) {
            $counters['attempted']++;
        }
        match ($receipt->state) {
            AutoRenewAttemptState::MutationQueued => $counters['queued']++,
            AutoRenewAttemptState::Succeeded => $counters['succeeded']++,
            AutoRenewAttemptState::PriceChangeBlocked => $counters['blocked']++,
            AutoRenewAttemptState::InsufficientWallet => $counters['insufficient']++,
            AutoRenewAttemptState::RetryPending, AutoRenewAttemptState::Failed => $counters['failed']++,
            default => null,
        };
    }

    private function windowHours(): int
    {
        return $this->boundedConfigInt('auto_renew.window_hours', 24, 1, 720);
    }

    private function boundedConfigInt(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = config($key, $default);
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($validated === false) {
            throw new RuntimeException($key.' configuration is invalid.');
        }

        return (int) $validated;
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }

    private function databaseDateTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function storedDateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
