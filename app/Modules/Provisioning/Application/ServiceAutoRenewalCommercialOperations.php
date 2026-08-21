<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentEligibilityDecisionReceipt;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use App\Modules\Provisioning\Domain\AutoRenewNotificationOutcome;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

trait ServiceAutoRenewalCommercialOperations
{
    private function executeCommercialAttempt(int $attemptId, object $facts): ServiceAutoRenewAttemptReceipt
    {
        $attempt = $this->attempt($attemptId);
        if ($attempt->payment_intent_id !== null) {
            return $this->resumeReservedAttempt($attemptId, $facts, $this->clock->now()->modify('+'.$this->windowHours().' hours'));
        }

        $quote = $this->freshQuote($attempt, $facts);
        $policy = $this->pricePolicyForOffering((int) $facts->plan_offering_id);
        $allowed = $this->pricePolicy->allows(
            (int) $attempt->baseline_price_irr,
            $quote->finalPriceIrr,
            $policy['mode'],
            $policy['absolute'],
            $policy['percentage'],
        );
        $this->bindQuote($attemptId, $quote, $allowed ? 'fresh_quote_accepted_for_evaluation' : 'price_change_blocked');
        if (! $allowed) {
            $this->transition($attemptId, AutoRenewAttemptState::PriceChangeBlocked, 'price_change_blocked', true);
            $this->notification($attemptId, AutoRenewNotificationOutcome::PriceChangeBlocked, 'price_change_blocked');

            return $this->receiptById($attemptId, false);
        }

        $decision = $this->eligibility->evaluate(
            'service.auto-renew.eligibility.'.$quote->quotePublicId,
            (int) $facts->user_id,
            $quote->quotePublicId,
        );
        if (! $this->walletIsEligible($decision)) {
            $this->bindEligibility($attemptId, $quote, $decision, 'wallet_not_eligible');
            $this->scheduleRetry($attemptId, AutoRenewAttemptState::RetryPending, 'wallet_not_eligible');
            $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'wallet_not_eligible');

            return $this->receiptById($attemptId, false);
        }
        $this->bindEligibility($attemptId, $quote, $decision, 'wallet_eligible');

        $walletAccountId = $this->walletAccountId((int) $facts->user_id);
        if ($walletAccountId === null) {
            $this->scheduleRetry($attemptId, AutoRenewAttemptState::InsufficientWallet, 'insufficient_wallet');
            $this->notification($attemptId, AutoRenewNotificationOutcome::InsufficientWallet, 'insufficient_wallet');

            return $this->receiptById($attemptId, false);
        }

        $attempt = $this->attempt($attemptId);
        $creationKey = $this->paymentIntentCreationKey((string) $attempt->cycle_key);
        try {
            $intent = $this->walletPayments->reserve(
                $creationKey,
                (int) $facts->user_id,
                $walletAccountId,
                $quote->quotePublicId,
                $decision->publicId,
                (string) $attempt->correlation_id,
            );
            $this->bindPaymentIntent($attemptId, $intent->intentPublicId, 'wallet_reserved');
        } catch (DomainException $exception) {
            $existingIntent = $this->paymentIntentByCreationKey($creationKey);
            if ($existingIntent !== null) {
                $this->bindPaymentIntent($attemptId, (string) $existingIntent->public_id, 'wallet_reservation_replayed');
            } elseif ($exception->getMessage() === self::INSUFFICIENT_WALLET_MESSAGE) {
                $this->scheduleRetry($attemptId, AutoRenewAttemptState::InsufficientWallet, 'insufficient_wallet');
                $this->notification($attemptId, AutoRenewNotificationOutcome::InsufficientWallet, 'insufficient_wallet');

                return $this->receiptById($attemptId, false);
            } else {
                $this->scheduleRetry($attemptId, AutoRenewAttemptState::RetryPending, 'wallet_reservation_deferred');
                $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'wallet_reservation_deferred');

                return $this->receiptById($attemptId, false);
            }
        }

        return $this->captureAndQueue($attemptId);
    }

    private function resumeReservedAttempt(
        int $attemptId,
        object $facts,
        DateTimeImmutable $dueUntil,
    ): ServiceAutoRenewAttemptReceipt {
        $attempt = $this->attempt($attemptId);
        if ($attempt->payment_intent_id === null) {
            return $this->executeCommercialAttempt($attemptId, $facts);
        }
        $intent = $this->database->connection()->table('payment_intents')
            ->where('id', (int) $attempt->payment_intent_id)
            ->first(['public_id', 'state']);
        if ($intent === null) {
            return $this->finishFailure($attemptId, 'payment_intent_missing');
        }
        if ($intent->state !== 'captured'
            && (! (bool) $facts->enabled
                || ! $this->runtimeEligible($facts)
                || ! $this->attemptMatchesConfigurationFacts($attempt, $facts))) {
            try {
                $this->walletPayments->release((string) $intent->public_id, 'auto-renew configuration changed before capture');

                return $this->finishFailure($attemptId, 'configuration_changed_after_reservation');
            } catch (DomainException|RuntimeException $releaseException) {
                $refreshedIntent = $this->database->connection()->table('payment_intents')
                    ->where('id', (int) $attempt->payment_intent_id)
                    ->first(['state']);
                if ($refreshedIntent === null || $refreshedIntent->state !== 'captured') {
                    report($releaseException);
                    $this->scheduleRetry($attemptId, AutoRenewAttemptState::RetryPending, 'reservation_release_deferred');
                    $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'reservation_release_deferred');

                    return $this->receiptById($attemptId, true);
                }
            }
        }
        if ($intent->state !== 'captured') {
            try {
                $observation = $this->remoteObservation($facts);
                $originalExpiry = $this->storedDateTime((string) $attempt->observed_expires_at);
                if ($observation['expires_at'] != $originalExpiry || $observation['expires_at'] > $dueUntil) {
                    try {
                        $this->walletPayments->release((string) $intent->public_id, 'auto-renew expiry changed before capture');
                        return $this->finishFailure($attemptId, 'expiry_changed_after_reservation');
                    } catch (DomainException|RuntimeException $releaseException) {
                        $refreshedIntent = $this->database->connection()->table('payment_intents')
                            ->where('id', (int) $attempt->payment_intent_id)
                            ->first(['state']);
                        if ($refreshedIntent === null || $refreshedIntent->state !== 'captured') {
                            throw $releaseException;
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->scheduleRetry($attemptId, AutoRenewAttemptState::RetryPending, 'reserved_attempt_recheck_deferred');
                $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'reserved_attempt_recheck_deferred');

                return $this->receiptById($attemptId, true);
            }
        }

        return $this->captureAndQueue($attemptId);
    }

    private function captureAndQueue(int $attemptId): ServiceAutoRenewAttemptReceipt
    {
        $attempt = $this->attempt($attemptId);
        if ($attempt->payment_intent_id === null) {
            throw new RuntimeException('Auto-renew capture requires a bound Payment Intent.');
        }
        $intent = $this->database->connection()->table('payment_intents')
            ->where('id', (int) $attempt->payment_intent_id)
            ->first(['public_id']);
        if ($intent === null) {
            return $this->finishFailure($attemptId, 'payment_intent_missing');
        }

        try {
            $order = $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $intent): ?PurchaseOrderReceipt {
                $lockedAttempt = $this->attemptOn($connection, $attemptId, true);
                $lockedIntent = $connection->table('payment_intents')
                    ->where('id', (int) $lockedAttempt->payment_intent_id)
                    ->lockForUpdate()
                    ->first(['public_id', 'state']);
                if ($lockedIntent === null || ! hash_equals((string) $lockedIntent->public_id, (string) $intent->public_id)) {
                    throw new RuntimeException('Auto-renew Payment Intent changed before capture.');
                }

                if ($lockedIntent->state !== 'captured') {
                    $facts = $this->configurationFactsOn($connection, (int) $lockedAttempt->auto_renew_configuration_id, true);
                    $policy = $this->pricePolicyForOfferingOn($connection, (int) $facts->plan_offering_id, true);
                    $policyAllows = $lockedAttempt->current_price_irr !== null
                        && $this->pricePolicy->allows(
                            (int) $lockedAttempt->baseline_price_irr,
                            (int) $lockedAttempt->current_price_irr,
                            $policy['mode'],
                            $policy['absolute'],
                            $policy['percentage'],
                        );
                    if (! (bool) $facts->enabled
                        || ! $this->runtimeEligible($facts)
                        || ! $this->attemptMatchesConfigurationFacts($lockedAttempt, $facts)
                        || ! $policyAllows) {
                        $this->walletPayments->release((string) $lockedIntent->public_id, 'auto-renew authority changed before capture');

                        return null;
                    }
                }

                return $this->walletPayments->capture((string) $lockedIntent->public_id, (string) $lockedAttempt->correlation_id);
            }, 3);
        } catch (Throwable $exception) {
            $captured = $this->database->connection()->table('payment_intents')
                ->where('id', (int) $attempt->payment_intent_id)
                ->value('state') === 'captured';
            if (! $captured) {
                report($exception);
                $this->scheduleRetry($attemptId, AutoRenewAttemptState::RetryPending, 'wallet_capture_deferred');
                $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'wallet_capture_deferred');

                return $this->receiptById($attemptId, true);
            }

            $order = $this->walletPayments->capture((string) $intent->public_id, (string) $attempt->correlation_id);
        }

        if ($order === null) {
            return $this->finishFailure($attemptId, 'authority_changed_before_capture');
        }

        $this->bindSettlement($attemptId, $order);
        try {
            $this->recordSettledPrice($attemptId);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordSameStateEvent($attemptId, 'settled_price_sync_deferred');
        }

        return $this->queueCapturedAttempt($attemptId);
    }

    private function queueCapturedAttempt(int $attemptId): ServiceAutoRenewAttemptReceipt
    {
        $attempt = $this->attempt($attemptId);
        if ($attempt->purchase_settlement_id === null) {
            throw new RuntimeException('Auto-renew mutation queue requires a captured settlement.');
        }
        $settlement = $this->database->connection()->table('purchase_settlements')
            ->where('id', (int) $attempt->purchase_settlement_id)
            ->first(['public_id']);
        if ($settlement === null) {
            return $this->finishFailure($attemptId, 'purchase_settlement_missing');
        }

        try {
            $mutation = $this->mutationQueue->queueFromSettlement(
                (string) $settlement->public_id,
                'service.auto-renew.mutation.'.substr((string) $attempt->cycle_key, 0, 64),
                (string) $attempt->correlation_id,
            );
        } catch (DomainException $exception) {
            if ($this->mutationQueueFailureIsRetryable($exception->getMessage())) {
                $this->recordSameStateEvent($attemptId, 'mutation_queue_deferred');

                return $this->receiptById($attemptId, true);
            }

            return $this->finishFailure($attemptId, 'mutation_queue_rejected');
        } catch (Throwable $exception) {
            report($exception);
            $this->recordSameStateEvent($attemptId, 'mutation_queue_deferred');

            return $this->receiptById($attemptId, true);
        }

        $operation = $this->database->connection()->table('provisioning_operations')
            ->where('public_id', $mutation->operationPublicId)
            ->first(['id']);
        if ($operation === null) {
            return $this->finishFailure($attemptId, 'mutation_operation_missing');
        }
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $operation): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            if ($attempt->provisioning_operation_id !== null
                && (int) $attempt->provisioning_operation_id !== (int) $operation->id) {
                throw new RuntimeException('Auto-renew attempt is already bound to a different mutation operation.');
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'provisioning_operation_id' => (int) $operation->id,
                'state' => AutoRenewAttemptState::MutationQueued->value,
                'reason_code' => 'mutation_queued',
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, (string) $attempt->state, AutoRenewAttemptState::MutationQueued, 'mutation_queued');
        }, 3);

        return $this->receiptById($attemptId, false);
    }

    private function reconcileAttempt(int $attemptId): ServiceAutoRenewAttemptReceipt
    {
        $attempt = $this->attempt($attemptId);
        $state = AutoRenewAttemptState::from((string) $attempt->state);
        if ($state->isTerminal()) {
            return $this->receipt($attempt, true);
        }
        if ($attempt->provisioning_operation_id === null) {
            return $this->receipt($attempt, true);
        }

        $operation = $this->database->connection()->table('provisioning_operations')
            ->where('id', (int) $attempt->provisioning_operation_id)
            ->first(['state']);
        if ($operation === null) {
            return $this->finishFailure($attemptId, 'mutation_operation_missing');
        }
        $provisioningState = ProvisioningState::from((string) $operation->state);

        if ($provisioningState === ProvisioningState::Succeeded) {
            $this->updateObservationFromSuccessfulMutation($attemptId);
            $this->transition($attemptId, AutoRenewAttemptState::Succeeded, 'renewal_succeeded', true);
            $this->notification($attemptId, AutoRenewNotificationOutcome::Success, 'renewal_succeeded');

            return $this->receiptById($attemptId, true);
        }
        if (in_array($provisioningState, [ProvisioningState::FailedFinal, ProvisioningState::Compensated], true)) {
            return $this->finishFailure($attemptId, 'renewal_mutation_failed');
        }
        if (in_array($provisioningState, [ProvisioningState::UncertainRemoteResult, ProvisioningState::NeedsReview], true)) {
            $this->recordSameStateEvent($attemptId, 'mutation_reconciliation_required');
            $this->notification($attemptId, AutoRenewNotificationOutcome::Failure, 'mutation_reconciliation_required');
        }

        return $this->receiptById($attemptId, true);
    }

}
