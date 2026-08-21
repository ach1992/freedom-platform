<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Application\PurchaseOrderReceipt;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Payments\Eligibility\Application\PaymentEligibilityDecisionReceipt;
use App\Modules\Provisioning\Domain\AutoRenewAttemptState;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait ServiceAutoRenewalPersistence
{
    /**
     * @param  ServiceAutoRenewConfigurationFacts  $facts
     * @return ServiceAutoRenewAttemptRow
     */
    private function ensureAttempt(object $facts): object
    {
        $cycleKey = $this->cycleKey($facts);
        $existing = $this->attemptForCycle($cycleKey);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use ($facts, $cycleKey): object {
                $current = $this->configurationFactsOn($connection, (int) $facts->config_id, true);
                if ($this->cycleKey($current) !== $cycleKey) {
                    throw new DomainException('Auto-renew configuration changed before cycle claim.');
                }
                $existing = $this->attemptForCycleOn($connection, $cycleKey, true);
                if ($existing !== null) {
                    return $existing;
                }
                if ($current->observed_expires_at === null
                    || $current->observed_expiry_evidence_hash === null
                    || $current->observed_expiry_source === null
                    || $current->observed_remote_identity_generation === null) {
                    throw new RuntimeException('Auto-renew cycle lacks complete expiry evidence.');
                }

                $publicId = (string) Str::ulid();
                $correlationId = hash('sha256', 'service-auto-renew-cycle:'.$cycleKey);
                $timestamp = $this->timestamp();
                $attemptId = (int) $connection->table('service_auto_renew_attempts')->insertGetId([
                    'public_id' => $publicId,
                    'cycle_key' => $cycleKey,
                    'auto_renew_configuration_id' => (int) $current->config_id,
                    'service_subscription_id' => (int) $current->service_subscription_id,
                    'configuration_version' => (int) $current->configuration_version,
                    'remote_identity_generation' => (int) $current->observed_remote_identity_generation,
                    'observed_expires_at' => (string) $current->observed_expires_at,
                    'observed_expiry_evidence_hash' => (string) $current->observed_expiry_evidence_hash,
                    'observed_expiry_source' => (string) $current->observed_expiry_source,
                    'state' => AutoRenewAttemptState::Pending->value,
                    'reason_code' => null,
                    'baseline_price_irr' => $current->last_settled_price_irr === null
                        ? (int) $current->accepted_price_irr
                        : (int) $current->last_settled_price_irr,
                    'current_price_irr' => null,
                    'quote_id' => null,
                    'payment_eligibility_decision_id' => null,
                    'payment_intent_id' => null,
                    'purchase_settlement_id' => null,
                    'provisioning_operation_id' => null,
                    'correlation_id' => $correlationId,
                    'retry_count' => 0,
                    'next_retry_at' => null,
                    'completed_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $this->event($connection, $attemptId, null, AutoRenewAttemptState::Pending, 'cycle_claimed');

                return $this->attemptOn($connection, $attemptId, true);
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->attemptForCycle($cycleKey);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function bindQuote(int $attemptId, QuoteReceipt $quote, string $reasonCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $quote, $reasonCode): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            if ($attempt->payment_intent_id !== null) {
                return;
            }
            $quoteId = $connection->table('quotes')->where('public_id', $quote->quotePublicId)->value('id');
            if (! is_int($quoteId) && ! is_string($quoteId)) {
                throw new RuntimeException('Auto-renew Quote disappeared before binding.');
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'quote_id' => (int) $quoteId,
                'payment_eligibility_decision_id' => null,
                'current_price_irr' => $quote->finalPriceIrr,
                'reason_code' => $reasonCode,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, (string) $attempt->state, AutoRenewAttemptState::from((string) $attempt->state), $reasonCode);
        }, 3);
    }

    private function bindEligibility(
        int $attemptId,
        QuoteReceipt $quote,
        PaymentEligibilityDecisionReceipt $decision,
        string $reasonCode,
    ): void {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $quote, $decision, $reasonCode): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            if ($attempt->payment_intent_id !== null) {
                return;
            }
            $quoteId = $connection->table('quotes')->where('public_id', $quote->quotePublicId)->value('id');
            if ((! is_int($quoteId) && ! is_string($quoteId)) || (int) $quoteId !== (int) $attempt->quote_id) {
                throw new RuntimeException('Auto-renew eligibility Quote binding is inconsistent.');
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'payment_eligibility_decision_id' => $decision->decisionId,
                'reason_code' => $reasonCode,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, (string) $attempt->state, AutoRenewAttemptState::from((string) $attempt->state), $reasonCode);
        }, 3);
    }

    private function bindPaymentIntent(int $attemptId, string $intentPublicId, string $reasonCode): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $intentPublicId, $reasonCode): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            /** @var object{id:int|string,source_quote_id:int|string,payment_eligibility_decision_id:int|string,payment_method_code:string}|null $intent */
            $intent = $connection->table('payment_intents')->where('public_id', $intentPublicId)->first([
                'id', 'source_quote_id', 'payment_eligibility_decision_id', 'payment_method_code',
            ]);
            if ($intent === null || $intent->payment_method_code !== 'wallet') {
                throw new RuntimeException('Auto-renew wallet Payment Intent is unavailable.');
            }
            if ($attempt->payment_intent_id !== null && (int) $attempt->payment_intent_id !== (int) $intent->id) {
                throw new RuntimeException('Auto-renew attempt is already bound to a different Payment Intent.');
            }
            /** @var object{final_price_irr:int|string}|null $quote */
            $quote = $connection->table('quotes')->where('id', (int) $intent->source_quote_id)->first(['final_price_irr']);
            if ($quote === null) {
                throw new RuntimeException('Auto-renew Payment Intent source Quote disappeared.');
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'quote_id' => (int) $intent->source_quote_id,
                'payment_eligibility_decision_id' => (int) $intent->payment_eligibility_decision_id,
                'payment_intent_id' => (int) $intent->id,
                'current_price_irr' => (int) $quote->final_price_irr,
                'reason_code' => $reasonCode,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, (string) $attempt->state, AutoRenewAttemptState::from((string) $attempt->state), $reasonCode);
        }, 3);
    }

    private function resetCommercialAuthorityForRequoteOn(
        Connection $connection,
        int $attemptId,
        string $reasonCode,
    ): void {
        $attempt = $this->attemptOn($connection, $attemptId, true);
        if ($attempt->payment_intent_id === null) {
            return;
        }
        if ($attempt->purchase_settlement_id !== null || $attempt->provisioning_operation_id !== null) {
            throw new RuntimeException('Captured auto-renew authority cannot be reset for re-quote.');
        }

        $state = AutoRenewAttemptState::from((string) $attempt->state);
        $this->event($connection, $attemptId, $state->value, $state, $reasonCode);

        $updated = $connection->table('service_auto_renew_attempts')
            ->where('id', $attemptId)
            ->where('payment_intent_id', (int) $attempt->payment_intent_id)
            ->update([
                'quote_id' => null,
                'payment_eligibility_decision_id' => null,
                'payment_intent_id' => null,
                'current_price_irr' => null,
                'reason_code' => $reasonCode,
                'updated_at' => $this->timestamp(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Auto-renew commercial authority reset lost its authoritative state.');
        }
    }

    private function bindSettlement(int $attemptId, PurchaseOrderReceipt $order): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId, $order): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            /** @var object{id:int|string,payment_intent_id:int|string}|null $settlement */
            $settlement = $connection->table('purchase_settlements')
                ->where('public_id', $order->purchaseSettlementPublicId)
                ->first(['id', 'payment_intent_id']);
            if ($settlement === null
                || $attempt->payment_intent_id === null
                || (int) $settlement->payment_intent_id !== (int) $attempt->payment_intent_id) {
                throw new RuntimeException('Auto-renew captured settlement does not match the bound Payment Intent.');
            }
            if ($attempt->purchase_settlement_id !== null) {
                if ((int) $attempt->purchase_settlement_id !== (int) $settlement->id) {
                    throw new RuntimeException('Auto-renew attempt is already bound to a different settlement.');
                }
                $state = AutoRenewAttemptState::from((string) $attempt->state);
                if (! in_array($state, [
                    AutoRenewAttemptState::Settled,
                    AutoRenewAttemptState::MutationQueued,
                    AutoRenewAttemptState::Succeeded,
                    AutoRenewAttemptState::Failed,
                ], true)) {
                    throw new RuntimeException('Auto-renew settlement replay has inconsistent attempt state.');
                }

                return;
            }
            $connection->table('service_auto_renew_attempts')->where('id', $attemptId)->update([
                'purchase_settlement_id' => (int) $settlement->id,
                'state' => AutoRenewAttemptState::Settled->value,
                'reason_code' => 'wallet_captured',
                'next_retry_at' => null,
                'updated_at' => $this->timestamp(),
            ]);
            $this->event($connection, $attemptId, (string) $attempt->state, AutoRenewAttemptState::Settled, 'wallet_captured');
        }, 3);
    }

    private function recordSettledPrice(int $attemptId): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            if ($attempt->current_price_irr === null) {
                throw new RuntimeException('Auto-renew settled attempt lacks current price authority.');
            }
            /** @var object{configuration_version:int|string}|null $config */
            $config = $connection->table('service_auto_renew_configurations')
                ->where('id', (int) $attempt->auto_renew_configuration_id)
                ->lockForUpdate()
                ->first(['configuration_version']);
            if ($config !== null && (int) $config->configuration_version === (int) $attempt->configuration_version) {
                $connection->table('service_auto_renew_configurations')
                    ->where('id', (int) $attempt->auto_renew_configuration_id)
                    ->update([
                        'last_settled_price_irr' => (int) $attempt->current_price_irr,
                        'last_correlation_id' => (string) $attempt->correlation_id,
                        'updated_at' => $this->timestamp(),
                    ]);
            }
        }, 3);
    }

    private function updateObservationFromSuccessfulMutation(int $attemptId): void
    {
        $this->database->connection()->transaction(function (Connection $connection) use ($attemptId): void {
            $attempt = $this->attemptOn($connection, $attemptId, true);
            if ($attempt->provisioning_operation_id === null) {
                throw new RuntimeException('Successful auto-renew attempt lacks mutation authority.');
            }
            /** @var object{id:int|string,quoted_remote_identity_generation:int|string,target_expires_at:string|null}|null $authority */
            $authority = $connection->table('service_paid_mutation_authorities')
                ->where('provisioning_operation_id', (int) $attempt->provisioning_operation_id)
                ->first(['id', 'quoted_remote_identity_generation', 'target_expires_at']);
            if ($authority === null || $authority->target_expires_at === null) {
                throw new RuntimeException('Successful auto-renew mutation lacks resolved target expiry authority.');
            }
            /** @var object{remote_identity_generation:int|string}|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('id', (int) $attempt->service_subscription_id)
                ->lockForUpdate()
                ->first(['remote_identity_generation']);
            if ($service === null
                || (int) $service->remote_identity_generation !== (int) $authority->quoted_remote_identity_generation) {
                return;
            }
            $evidenceHash = hash('sha256', implode('|', [
                'paid_mutation',
                (string) $authority->id,
                (string) $attempt->provisioning_operation_id,
                (string) $authority->target_expires_at,
                (string) $authority->quoted_remote_identity_generation,
            ]));
            $connection->table('service_auto_renew_configurations')
                ->where('id', (int) $attempt->auto_renew_configuration_id)
                ->update([
                    'observed_expires_at' => (string) $authority->target_expires_at,
                    'expiry_observed_at' => $this->timestamp(),
                    'observed_expiry_evidence_hash' => $evidenceHash,
                    'observed_expiry_source' => 'paid_mutation',
                    'observed_remote_identity_generation' => (int) $authority->quoted_remote_identity_generation,
                    'last_correlation_id' => (string) $attempt->correlation_id,
                    'updated_at' => $this->timestamp(),
                ]);
        }, 3);
    }
}
