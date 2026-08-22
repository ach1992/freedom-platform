<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Provisioning\Domain\AutoRenewNotificationOutcome;
use DateTimeImmutable;
use DomainException;
use Throwable;

trait ServiceAutoRenewalBatchOperations
{
    public function processDue(int $limit): ServiceAutoRenewBatchReceipt
    {
        if ($limit < 1 || $limit > 500) {
            throw new DomainException('Auto-renew batch limit must be between 1 and 500.');
        }

        $connection = $this->database->connection();
        ServiceAutoRenewDatabaseAuthority::beginRuntime($connection);
        try {
            return $this->processDueAuthorized($limit);
        } finally {
            ServiceAutoRenewDatabaseAuthority::endRuntime($connection);
        }
    }

    private function processDueAuthorized(int $limit): ServiceAutoRenewBatchReceipt
    {
        $counters = [
            'candidates' => 0,
            'attempted' => 0,
            'queued' => 0,
            'succeeded' => 0,
            'blocked' => 0,
            'insufficient' => 0,
            'failed' => 0,
        ];

        foreach ($this->staleUnfinancializedAttemptIds($limit) as $attemptId) {
            try {
                $receipt = $this->finishFailure($attemptId, 'configuration_superseded');
                $this->countReceipt($receipt, $counters, false);
            } catch (Throwable $exception) {
                report($exception);
                $this->safeRecordAttemptEvent($attemptId, 'configuration_supersession_deferred');
                $counters['failed']++;
            }
        }

        foreach ($this->outstandingMutationAttemptIds($limit) as $attemptId) {
            try {
                $receipt = $this->reconcileAttempt($attemptId);
                $this->countReceipt($receipt, $counters, false);
            } catch (Throwable $exception) {
                report($exception);
                $this->safeRecordAttemptEvent($attemptId, 'mutation_reconciliation_deferred');
                $counters['failed']++;
            }
        }
        foreach ($this->outstandingFinancialAttemptIds($limit) as $attemptId) {
            try {
                $attempt = $this->attempt($attemptId);
                $facts = $this->configurationFacts((int) $attempt->auto_renew_configuration_id);
                $receipt = $attempt->purchase_settlement_id !== null
                    ? $this->queueCapturedAttempt($attemptId)
                    : $this->resumeReservedAttempt(
                        $attemptId,
                        $facts,
                        $this->clock->now()->modify('+'.$this->windowHours().' hours'),
                    );
                $this->countReceipt($receipt, $counters, false);
            } catch (Throwable $exception) {
                report($exception);
                $this->safeRecordAttemptEvent($attemptId, 'financial_recovery_deferred');
                $counters['failed']++;
            }
        }

        $windowHours = $this->boundedConfigInt('auto_renew.window_hours', 24, 1, 720);
        $dueUntil = $this->clock->now()->modify('+'.$windowHours.' hours');
        $candidateIds = $this->database->connection()->table('service_auto_renew_configurations as c')
            ->leftJoin('service_auto_renew_attempts as a', function ($join): void {
                $join->on('a.auto_renew_configuration_id', '=', 'c.id')
                    ->on('a.configuration_version', '=', 'c.configuration_version')
                    ->on('a.remote_identity_generation', '=', 'c.observed_remote_identity_generation')
                    ->on('a.observed_expires_at', '=', 'c.observed_expires_at');
            })
            ->where('c.enabled', true)
            ->whereNotNull('c.observed_expires_at')
            ->where('c.observed_expires_at', '<=', $this->databaseDateTime($dueUntil))
            ->where(function ($query): void {
                $query->whereNull('a.id')
                    ->orWhere(function ($attempt): void {
                        $attempt->whereIn('a.state', [
                            AutoRenewAttemptState::Pending->value,
                            AutoRenewAttemptState::RetryPending->value,
                            AutoRenewAttemptState::InsufficientWallet->value,
                        ])
                            ->whereNull('a.payment_intent_id')
                            ->whereNull('a.purchase_settlement_id')
                            ->whereNull('a.provisioning_operation_id')
                            ->where(function ($ready): void {
                                $ready->where('a.state', AutoRenewAttemptState::Pending->value)
                                    ->orWhere(function ($retry): void {
                                        $retry->whereIn('a.state', [
                                            AutoRenewAttemptState::RetryPending->value,
                                            AutoRenewAttemptState::InsufficientWallet->value,
                                        ])->where('a.next_retry_at', '<=', $this->timestamp());
                                    });
                            });
                    });
            })
            ->orderBy('c.observed_expires_at')
            ->orderBy('c.id')
            ->limit($limit)
            ->pluck('c.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $counters['candidates'] = count($candidateIds);

        foreach ($candidateIds as $configurationId) {
            try {
                $receipt = $this->processConfiguration($configurationId, $dueUntil);
                if ($receipt !== null) {
                    $this->countReceipt($receipt, $counters, true);
                }
            } catch (Throwable $exception) {
                $configurationDeferral = $exception instanceof DomainException
                    && $exception->getMessage() === self::UNSAFE_RENEWAL_WINDOW_MESSAGE;
                if (! $configurationDeferral) {
                    report($exception);
                }
                $receipt = $this->recordCandidateFailure(
                    $configurationId,
                    $configurationDeferral ? 'renewal_window_unsafe' : 'auto_renew_unexpected_failure',
                    ! $configurationDeferral,
                );
                if ($receipt !== null) {
                    $this->countReceipt($receipt, $counters, true);
                } else {
                    $counters['failed']++;
                }
            }
        }

        return new ServiceAutoRenewBatchReceipt(
            $counters['candidates'],
            $counters['attempted'],
            $counters['queued'],
            $counters['succeeded'],
            $counters['blocked'],
            $counters['insufficient'],
            $counters['failed'],
        );
    }

    private function processConfiguration(int $configurationId, DateTimeImmutable $dueUntil): ?ServiceAutoRenewAttemptReceipt
    {
        $facts = $this->configurationFacts($configurationId);
        if (! (bool) $facts->enabled) {
            return null;
        }

        $existing = $this->hasCompleteCycleEvidence($facts)
            ? $this->attemptForCycle($this->cycleKey($facts))
            : null;
        if ($existing !== null) {
            $state = AutoRenewAttemptState::from((string) $existing->state);
            if ($state->isTerminal()) {
                return $this->receipt($existing, true);
            }
            if ($existing->provisioning_operation_id !== null || $state === AutoRenewAttemptState::MutationQueued) {
                return $this->reconcileAttempt((int) $existing->id);
            }
            if ($existing->purchase_settlement_id !== null || $state === AutoRenewAttemptState::Settled) {
                return $this->queueCapturedAttempt((int) $existing->id);
            }
            if ($existing->payment_intent_id !== null) {
                if (! $this->retryReady($existing)) {
                    return null;
                }

                return $this->resumeReservedAttempt((int) $existing->id, $facts, $dueUntil);
            }
            if (in_array($state, [AutoRenewAttemptState::RetryPending, AutoRenewAttemptState::InsufficientWallet], true)
                && ! $this->retryReady($existing)) {
                return null;
            }
        }

        // A renewal must push even an already-expired Service beyond the active scheduler window.
        // Otherwise a successful short-duration renewal can remain immediately due and be charged
        // again on the next scheduler pass. Fail closed before any new wallet authority is created,
        // but keep the cycle retryable so an operator can safely reduce the window configuration.
        if (! $this->renewalWindowIsShorterThanPackage($facts)) {
            if (! $this->hasCompleteCycleEvidence($facts)) {
                throw new DomainException('Auto-renew configuration has stale or incomplete cycle evidence.');
            }
            $attempt = $existing ?? $this->ensureAttempt($facts);
            $this->scheduleConfigurationRetry((int) $attempt->id, 'renewal_window_unsafe');

            return $this->receiptById((int) $attempt->id, $existing !== null);
        }

        if (! $this->runtimeEligible($facts)) {
            if (! $this->hasCompleteCycleEvidence($facts)) {
                throw new DomainException('Auto-renew configuration has stale or incomplete cycle evidence.');
            }
            $attempt = $existing ?? $this->ensureAttempt($facts);

            return $this->finishFailure((int) $attempt->id, 'service_not_eligible');
        }

        try {
            $refreshed = $this->refreshRemoteObservation($facts);
        } catch (Throwable $exception) {
            report($exception);
            $attempt = $existing ?? $this->ensureAttempt($facts);
            $this->scheduleRetry((int) $attempt->id, AutoRenewAttemptState::RetryPending, 'remote_observation_unavailable');
            $this->notification((int) $attempt->id, AutoRenewNotificationOutcome::Failure, 'remote_observation_unavailable');

            return $this->receiptById((int) $attempt->id, $existing !== null);
        }
        if (! $refreshed) {
            return null;
        }

        $facts = $this->configurationFacts($configurationId);
        if ($facts->observed_expires_at === null || $this->storedDateTime($facts->observed_expires_at) > $dueUntil) {
            return null;
        }

        $attempt = $this->ensureAttempt($facts);
        $state = AutoRenewAttemptState::from((string) $attempt->state);
        if ($state->isTerminal()) {
            return $this->receipt($attempt, true);
        }
        if ($attempt->payment_intent_id !== null) {
            return $this->resumeReservedAttempt((int) $attempt->id, $facts, $dueUntil);
        }

        return $this->executeCommercialAttempt((int) $attempt->id, $facts);
    }

    /** @param ServiceAutoRenewConfigurationFacts $facts */
    private function renewalWindowIsShorterThanPackage(object $facts): bool
    {
        if ($facts->package_duration_days === null || (int) $facts->package_duration_days < 1) {
            return false;
        }

        return (int) $facts->package_duration_days > intdiv($this->windowHours(), 24);
    }
}
