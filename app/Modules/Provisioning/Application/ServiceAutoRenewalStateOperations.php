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
        }, 3);
    }

    private function scheduleRetry(int $attemptId, AutoRenewAttemptState $retryState, string $reasonCode): void
    {
        if (! in_array($retryState, [AutoRenewAttemptState::RetryPending, AutoRenewAttemptState::InsufficientWallet], true)) {
            throw new RuntimeException('Auto-renew retry state is invalid.');
        }

        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $retryState, $reasonCode): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            $current = AutoRenewAttemptState::from((string) $attempt->state);
            if ($current->isTerminal() || in_array($current, [AutoRenewAttemptState::Settled, AutoRenewAttemptState::MutationQueued], true)) {
                return;
            }

            $retryCount = (int) $attempt->retry_count + 1;
            if ($retryCount > $this->maxRetryCount()) {
                $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                    'state' => AutoRenewAttemptState::Failed->value,
                    'reason_code' => 'retry_exhausted',
                    'retry_count' => $retryCount,
                    'next_retry_at' => null,
                    'completed_at' => $this->timestamp(),
                    'updated_at' => $this->timestamp(),
                ]);
                $this->event($connection, $attemptId, $current->value, AutoRenewAttemptState::Failed, 'retry_exhausted');

                return;
            }

            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'state' => $retryState->value,
                'reason_code' => $reasonCode,
                'retry_count' => $retryCount,
                'next_retry_at' => $this->databaseDateTime($this->clock->now()->modify('+'.$this->retryDelayMinutes($retryCount).' minutes')),
                'completed_at' => null,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, $current->value, $retryState, $reasonCode);
        }, 3);

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
        $this->database->connection()->table('service_auto_renew_notification_intents')->insertOrIgnore([
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

    private function recordCandidateFailure(int $configurationId, string $reasonCode): ?ServiceAutoRenewAttemptReceipt
    {
        try {
            $facts = $this->configurationFacts($configurationId);
            if (! (bool) $facts->enabled || ! $this->hasCompleteCycleEvidence($facts)) {
                return null;
            }
            $attempt = $this->ensureAttempt($facts);
            $this->scheduleRetry((int) $attempt->id, AutoRenewAttemptState::RetryPending, $reasonCode);
            $this->notification((int) $attempt->id, AutoRenewNotificationOutcome::Failure, $reasonCode);

            return $this->receiptById((int) $attempt->id, true);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
