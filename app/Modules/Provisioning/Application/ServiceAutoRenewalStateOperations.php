<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Provisioning\Domain\AutoRenewNotificationOutcome;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

trait ServiceAutoRenewalStateOperations
{
    private function transition(
        int $attemptId,
        AutoRenewAttemptState $next,
        string $reasonCode,
        bool $completed,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $next, $reasonCode, $completed): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            $current = AutoRenewAttemptState::from((string) $attempt->state);
            if ($current === $next && (string) ($attempt->reason_code ?? '') === $reasonCode) {
                return;
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'state' => $next->value,
                'reason_code' => $reasonCode,
                'next_retry_at' => null,
                'completed_at' => $completed ? $this->timestamp() : null,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, $current->value, $next, $reasonCode);
            $terminalOutcome = match ($next) {
                AutoRenewAttemptState::PriceChangeBlocked => AutoRenewNotificationOutcome::PriceChangeBlocked,
                AutoRenewAttemptState::Succeeded => AutoRenewNotificationOutcome::Success,
                AutoRenewAttemptState::Failed => AutoRenewNotificationOutcome::Failure,
                default => null,
            };
            if ($terminalOutcome !== null) {
                $this->notificationOn($connection, $attemptId, $terminalOutcome, $reasonCode);
            }
        }, 3);
    }

    private function scheduleRetry(int $attemptId, AutoRenewAttemptState $retryState, string $reasonCode): void
    {
        $this->scheduleRetryWithBudget($attemptId, $retryState, $reasonCode, true);
    }

    private function scheduleConfigurationRetry(int $attemptId, string $reasonCode): void
    {
        $this->scheduleRetryWithBudget($attemptId, AutoRenewAttemptState::RetryPending, $reasonCode, false);
    }

    private function scheduleRetryWithBudget(
        int $attemptId,
        AutoRenewAttemptState $retryState,
        string $reasonCode,
        bool $consumeBudget,
    ): void {
        if (! in_array($retryState, [AutoRenewAttemptState::RetryPending, AutoRenewAttemptState::InsufficientWallet], true)) {
            throw new RuntimeException('Auto-renew retry state is invalid.');
        }

        $this->database->connection()->transaction(function (Connection $connection) use (
            $attemptId,
            $retryState,
            $reasonCode,
            $consumeBudget,
        ): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            $current = AutoRenewAttemptState::from((string) $attempt->state);
            if ($current->isTerminal() || in_array($current, [AutoRenewAttemptState::Settled, AutoRenewAttemptState::MutationQueued], true)) {
                return;
            }
            if ($current === $retryState
                && (string) ($attempt->reason_code ?? '') === $reasonCode
                && $attempt->next_retry_at !== null
                && $this->storedDateTime((string) $attempt->next_retry_at) > $this->clock->now()) {
                return;
            }

            $retryCount = (int) $attempt->retry_count + ($consumeBudget ? 1 : 0);
            if ($consumeBudget && $retryCount > $this->maxRetryCount()) {
                if ((string) ($attempt->reason_code ?? '') !== $reasonCode) {
                    // Preserve the cause that exhausted the budget before the terminal marker
                    // replaces the attempt reason with the stable retry_exhausted contract.
                    $this->event($connection, $attemptId, $current->value, $current, $reasonCode);
                }
                $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                    'state' => AutoRenewAttemptState::Failed->value,
                    'reason_code' => 'retry_exhausted',
                    'retry_count' => $retryCount,
                    'next_retry_at' => null,
                    'completed_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $this->event($connection, $attemptId, $current->value, AutoRenewAttemptState::Failed, 'retry_exhausted');
                $this->notificationOn(
                    $connection,
                    $attemptId,
                    AutoRenewNotificationOutcome::Failure,
                    'retry_exhausted',
                );

                return;
            }

            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'state' => $retryState->value,
                'reason_code' => $reasonCode,
                'retry_count' => $retryCount,
                'next_retry_at' => $this->databaseDateTime(
                    $this->clock->now()->modify('+'.$this->retryDelayMinutes(max(1, $retryCount)).' minutes'),
                ),
                'completed_at' => null,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, $current->value, $retryState, $reasonCode);
            $this->notificationOn(
                $connection,
                $attemptId,
                $retryState === AutoRenewAttemptState::InsufficientWallet
                    ? AutoRenewNotificationOutcome::InsufficientWallet
                    : AutoRenewNotificationOutcome::Failure,
                $reasonCode,
            );
        }, 3);

        if (! $consumeBudget) {
            return;
        }

        $attempt = $this->attempt($attemptId);
        if (AutoRenewAttemptState::from((string) $attempt->state) === AutoRenewAttemptState::Failed
            && (string) $attempt->reason_code === 'retry_exhausted') {
            $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'retry_exhausted');
        }
    }

    /** @param ServiceAutoRenewAttemptRow $attempt */
    private function retryReady(object $attempt): bool
    {
        if ($attempt->next_retry_at === null) {
            return true;
        }

        return $this->storedDateTime((string) $attempt->next_retry_at) <= $this->clock->now();
    }

    private function maxRetryCount(): int
    {
        return $this->boundedConfigInt('auto_renew.max_retry_count', 5, 1, 20);
    }

    private function retryDelayMinutes(int $retryCount): int
    {
        $initial = $this->boundedConfigInt('auto_renew.retry_initial_delay_minutes', 15, 1, 1440);
        $maximum = $this->boundedConfigInt('auto_renew.retry_max_delay_minutes', 240, $initial, 10080);
        $delay = $initial;
        for ($step = 1; $step < $retryCount && $delay < $maximum; $step++) {
            $delay = min($maximum, $delay > intdiv(PHP_INT_MAX, 2) ? $maximum : $delay * 2);
        }

        return $delay;
    }

    private function finishFailure(int $attemptId, string $reasonCode): ServiceAutoRenewAttemptReceipt
    {
        $this->transition($attemptId, AutoRenewAttemptState::Failed, $reasonCode, true);
        $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, $reasonCode);

        return $this->receiptById($attemptId, false);
    }

    private function finishUnfinancializedFailure(int $attemptId, string $reasonCode): ?ServiceAutoRenewAttemptReceipt
    {
        $finished = $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $reasonCode): bool {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            $current = AutoRenewAttemptState::from((string) $attempt->state);
            if (! in_array($current, [
                AutoRenewAttemptState::Pending,
                AutoRenewAttemptState::RetryPending,
                AutoRenewAttemptState::InsufficientWallet,
            ], true)
                || $attempt->payment_intent_id !== null
                || $attempt->purchase_settlement_id !== null
                || $attempt->provisioning_operation_id !== null) {
                return false;
            }

            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'state' => AutoRenewAttemptState::Failed->value,
                'reason_code' => $reasonCode,
                'next_retry_at' => null,
                'completed_at' => $this->timestamp(),
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, $current->value, AutoRenewAttemptState::Failed, $reasonCode);
            $this->notificationOn($connection, $attemptId, AutoRenewNotificationOutcome::Failure, $reasonCode);

            return true;
        }, 3);

        return $finished ? $this->receiptById($attemptId, false) : null;
    }

    private function recordSameStateEvent(int $attemptId, string $reasonCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $reasonCode): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            $state = AutoRenewAttemptState::from((string) $attempt->state);
            if ((string) ($attempt->reason_code ?? '') === $reasonCode) {
                return;
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'reason_code' => $reasonCode,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, $state->value, $state, $reasonCode);
        }, 3);
    }

    private function notification(
        int $attemptId,
        AutoRenewNotificationOutcome $outcome,
        string $reasonCode,
    ): void {
        $this->notificationOn($this->database->connection(), $attemptId, $outcome, $reasonCode);
    }

    private function notificationOn(
        Connection $connection,
        int $attemptId,
        AutoRenewNotificationOutcome $outcome,
        string $reasonCode,
    ): void {
        $connection->table('service_auto_renew_notification_intents')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'auto_renew_attempt_id' => $attemptId,
            'outcome' => $outcome->value,
            'reason_code' => $reasonCode,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function event(
        Connection $connection,
        int $attemptId,
        ?string $fromState,
        AutoRenewAttemptState $toState,
        string $reasonCode,
    ): void {
        $attempt = $this->attemptOn($connection, $attemptId, true);
        $sequence = (int) $connection->table('service_auto_renew_attempt_events')
            ->where('auto_renew_attempt_id', $attemptId)
            ->max('sequence') + 1;
        $connection->table('service_auto_renew_attempt_events')->insert([
            'auto_renew_attempt_id' => $attemptId,
            'sequence' => $sequence,
            'from_state' => $fromState,
            'to_state' => $toState->value,
            'reason_code' => $reasonCode,
            'quote_id' => $attempt->quote_id,
            'payment_intent_id' => $attempt->payment_intent_id,
            'purchase_settlement_id' => $attempt->purchase_settlement_id,
            'provisioning_operation_id' => $attempt->provisioning_operation_id,
            'correlation_id' => (string) $attempt->correlation_id,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function recordCandidateFailure(
        int $configurationId,
        string $reasonCode,
        bool $consumeRetryBudget = true,
    ): ?ServiceAutoRenewAttemptReceipt {
        try {
            $facts = $this->configurationFacts($configurationId);
            if (! (bool) $facts->enabled || ! $this->hasCompleteCycleEvidence($facts)) {
                return null;
            }
            $attempt = $this->ensureAttempt($facts);
            if ($consumeRetryBudget) {
                $this->scheduleRetry((int) $attempt->id, AutoRenewAttemptState::RetryPending, $reasonCode);
            } else {
                $this->scheduleConfigurationRetry((int) $attempt->id, $reasonCode);
            }
            $this->notification((int) $attempt->id, AutoRenewNotificationOutcome::Failure, $reasonCode);

            return $this->receiptById((int) $attempt->id, true);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
