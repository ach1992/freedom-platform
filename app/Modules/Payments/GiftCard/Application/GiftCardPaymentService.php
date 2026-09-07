<?php

declare(strict_types=1);

namespace App\Modules\Payments\GiftCard\Application;

use App\Modules\Orders\Application\PurchaseOrderService;
use App\Modules\Orders\Application\PurchaseOrderSettlementAvailability;
use App\Modules\Payments\Domain\PaymentIntentState;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderEvidence;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardProviderRequest;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class GiftCardPaymentService
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private GiftCardRedemptionService $redemptions,
        private GiftCardReleaseService $releases,
        private PurchaseOrderService $orders,
        private Clock $clock,
    ) {}

    /** @requirement GFT-001 GFT-002 PAY-002 DAT-002 DAT-003 SEC-002 QUA-001 */
    public function routeManualOnly(string $submissionPublicId, string $correlationId): GiftCardProcessingReceipt
    {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card submission public ID is invalid.');
        }
        $this->assertToken($correlationId, 'Gift-card manual-review correlation ID', 8, 64);

        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $correlationId): GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card submission does not exist.');
            }
            if ($authority->verification_mode !== 'manual_only') {
                throw new DomainException('Gift-card submission is not configured for manual-only review.');
            }
            if ($authority->state === 'pending_manual_review') {
                if ($authority->intent_state !== PaymentIntentState::PendingManualReview->value) {
                    throw new RuntimeException('Gift-card manual-review replay intent state is inconsistent.');
                }
                $receipt = $this->receipt($connection, $authority, true);
                if ($receipt->reviewPublicId === null) {
                    throw new RuntimeException('Gift-card manual-review replay is missing review authority.');
                }

                return $receipt;
            }
            if ($authority->state !== 'submitted' || $authority->intent_state !== PaymentIntentState::Submitted->value) {
                throw new DomainException('Gift-card submission cannot enter manual review from its current state.');
            }

            $this->createReview($connection, $authority, 'manual_only', $correlationId);
            $refreshed = $this->submissionAuthority($connection, $submissionPublicId);
            if ($refreshed === null
                || $refreshed->state !== 'pending_manual_review'
                || $refreshed->intent_state !== PaymentIntentState::PendingManualReview->value) {
                throw new RuntimeException('Gift-card manual-review transition did not converge.');
            }
            $receipt = $this->receipt($connection, $refreshed, false);
            if ($receipt->reviewPublicId === null) {
                throw new RuntimeException('Gift-card manual-review transition is missing review authority.');
            }

            return $receipt;
        }, 3);
    }

    /** @requirement GFT-001 GFT-002 GFT-003 GFT-004 PAY-002 PAY-003 DAT-002 DAT-003 DAT-004 SEC-002 INT-001 INT-002 QUA-001 QUA-004 */
    public function process(
        string $submissionPublicId,
        GiftCardVerificationProvider $provider,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        if (! Str::isUlid($submissionPublicId)) {
            throw new DomainException('Gift-card submission public ID is invalid.');
        }
        $this->assertToken($provider->code(), 'Gift-card provider code', 2, 64);
        $this->assertToken($correlationId, 'Gift-card processing correlation ID', 8, 64);

        if ($this->hasPersistedRedemption($submissionPublicId)) {
            return $this->redemptions->settlePersistedRedemption($submissionPublicId, $correlationId);
        }
        $persistedRelease = $this->releases->settlePersistedRelease($submissionPublicId, $provider->code(), $correlationId);
        if ($persistedRelease !== null) {
            return $persistedRelease;
        }

        $prepared = $this->beginValidation($submissionPublicId, $provider->code(), $correlationId);
        if ($prepared instanceof GiftCardProcessingReceipt) {
            return $prepared;
        }
        $capabilities = $provider->capabilities();
        if (! $capabilities->validate) {
            return $this->handleProviderFailure($submissionPublicId, 'validation_unsupported', $provider->code(), $correlationId, false);
        }

        try {
            $validation = $provider->validate($prepared);
        } catch (Throwable $exception) {
            return $this->handleProviderFailure($submissionPublicId, 'validation_call_failed', $provider->code(), $correlationId, false, $exception);
        }

        $validationResult = $this->recordValidation($submissionPublicId, $provider->code(), $validation, $correlationId);
        if ($validationResult instanceof GiftCardProcessingReceipt) {
            return $validationResult;
        }

        if (! $capabilities->redeem) {
            return $this->routeMissingRedeemCapability($submissionPublicId, $provider->code(), $correlationId);
        }

        if ($capabilities->reserve) {
            $orderUnavailable = $this->guardProviderValueMutation(
                $submissionPublicId,
                $provider,
                $correlationId,
            );
            if ($orderUnavailable !== null) {
                return $orderUnavailable;
            }
            $reserveRequest = $this->beginProviderMutation($submissionPublicId, 'reserve', ['valid_unreserved'], 'reserving');
            try {
                $reserve = $provider->reserve($reserveRequest);
            } catch (Throwable $exception) {
                return $this->handleProviderFailure($submissionPublicId, 'reserve_call_uncertain', $provider->code(), $correlationId, true, $exception);
            }
            $reserveResult = $this->recordReserve($submissionPublicId, $provider->code(), $reserve, $correlationId);
            if ($reserveResult instanceof GiftCardProcessingReceipt) {
                return $reserveResult;
            }
        }

        $orderUnavailable = $this->guardProviderValueMutation(
            $submissionPublicId,
            $provider,
            $correlationId,
        );
        if ($orderUnavailable !== null) {
            return $orderUnavailable;
        }
        $redeemRequest = $this->beginProviderMutation($submissionPublicId, 'redeem', ['valid_unreserved', 'reserved'], 'redeeming');
        try {
            $redeem = $provider->redeem($redeemRequest);
        } catch (Throwable $exception) {
            $this->recordFinding($submissionPublicId, 'redeem_call_uncertain', 'critical', $provider->code(), null, null, null, $correlationId);
            throw new RuntimeException('Gift-card redeem outcome is uncertain; reconcile provider status before retry.', 0, $exception);
        }

        if ($redeem->operation !== 'redeem') {
            throw new RuntimeException('Gift-card provider returned evidence for the wrong operation.');
        }
        if ($redeem->outcome !== 'success' || $redeem->status !== 'redeemed') {
            return $this->recordNonSuccessfulRedeem($submissionPublicId, $provider->code(), $redeem, $correlationId);
        }

        return $this->redemptions->recordAndSettle($submissionPublicId, $provider->code(), $redeem, $correlationId);
    }

    private function guardProviderValueMutation(
        string $submissionPublicId,
        GiftCardVerificationProvider $provider,
        string $correlationId,
    ): ?GiftCardProcessingReceipt {
        $connection = $this->database->connection();
        $authority = $this->submissionAuthority($connection, $submissionPublicId);
        if ($authority === null) {
            throw new DomainException('Gift-card submission does not exist.');
        }
        $availability = $this->orders->settlementAvailabilityFromQuote(
            (string) $authority->source_quote_public_id,
            (int) $authority->user_id,
        );
        if ($availability !== PurchaseOrderSettlementAvailability::Unavailable) {
            return null;
        }

        $this->recordFinding(
            $submissionPublicId,
            'purchase_order_unavailable_before_provider_mutation',
            $authority->state === 'reserved' ? 'critical' : 'high',
            $provider->code(),
            null,
            null,
            null,
            $correlationId,
        );

        if ($authority->state === 'reserved') {
            if ($provider->capabilities()->release) {
                return $this->releases->release($submissionPublicId, $provider, $correlationId);
            }

            return $this->database->connection()->transaction(function (Connection $connection) use (
                $submissionPublicId,
                $provider,
                $correlationId,
            ): GiftCardProcessingReceipt {
                $locked = $this->submissionAuthority($connection, $submissionPublicId, true);
                if ($locked === null || $locked->state !== 'reserved') {
                    throw new RuntimeException('Gift-card reserved Order-loss state changed concurrently.');
                }
                $this->recordFindingInConnection(
                    $connection,
                    $locked,
                    'purchase_order_unavailable_reservation_release_unsupported',
                    'critical',
                    $provider->code(),
                    null,
                    $correlationId,
                );
                $this->createReview($connection, $locked, 'purchase_order_unavailable', $correlationId);

                return $this->receipt(
                    $connection,
                    $this->submissionAuthority($connection, $submissionPublicId) ?? $locked,
                    false,
                );
            }, 3);
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $submissionPublicId,
            $provider,
            $correlationId,
        ): GiftCardProcessingReceipt {
            $locked = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($locked === null || $locked->state !== 'valid_unreserved') {
                throw new RuntimeException('Gift-card Order-loss state changed concurrently.');
            }
            $this->recordFindingInConnection(
                $connection,
                $locked,
                'purchase_order_unavailable_before_provider_mutation',
                'high',
                $provider->code(),
                null,
                $correlationId,
            );
            $updated = $connection->table('gift_card_submissions')
                ->where('id', $locked->submission_id)
                ->where('state', 'valid_unreserved')
                ->update(['state' => 'rejected']);
            if ($updated !== 1) {
                throw new RuntimeException('Gift-card Order-loss state changed concurrently.');
            }
            $this->failIntent(
                $connection,
                $locked,
                $correlationId,
                'gift_card_purchase_order_unavailable',
            );

            return $this->receipt(
                $connection,
                $this->submissionAuthority($connection, $submissionPublicId) ?? $locked,
                false,
            );
        }, 3);
    }

    private function beginValidation(string $submissionPublicId, string $providerCode, string $correlationId): GiftCardProviderRequest|GiftCardProcessingReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $correlationId): GiftCardProviderRequest|GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card submission does not exist.');
            }
            if ($authority->provider_code !== $providerCode) {
                throw new DomainException('Gift-card provider does not match the immutable type authority.');
            }
            if (in_array($authority->state, ['captured', 'pending_manual_review'], true)) {
                return $this->receipt($connection, $authority, true);
            }
            if (in_array($authority->state, ['validating', 'reserving', 'redeeming'], true)) {
                throw new RuntimeException('Gift-card provider operation is already in flight; reconcile status before retry.');
            }
            if ($authority->state !== 'submitted') {
                return $this->receipt($connection, $authority, true);
            }
            if ($authority->verification_mode === 'manual_only') {
                $this->createReview($connection, $authority, 'manual_only', $correlationId);

                return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
            }

            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'validating']);
            $this->advanceIntentToVerifying($connection, $authority, $correlationId);

            return $this->providerRequest($authority, 'validate');
        }, 3);
    }

    /** @return 'ready'|GiftCardProcessingReceipt */
    private function recordValidation(
        string $submissionPublicId,
        string $providerCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): string|GiftCardProcessingReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $evidence, $correlationId): string|GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null || $authority->state !== 'validating') {
                throw new RuntimeException('Gift-card validation state changed before provider evidence was recorded.');
            }
            $this->validateProviderEvidence($evidence, 'validate');
            $this->recordProviderEvent($connection, $authority, $providerCode, $evidence);

            if ($evidence->outcome === 'success' && $evidence->status === 'valid') {
                if (! $this->providerEvidenceMatchesClaim($authority, $evidence)) {
                    return $this->routeMismatch($connection, $authority, $providerCode, $evidence, $correlationId, 'validation_identity_mismatch');
                }
                if ($authority->claimed_currency !== 'IRR' || (int) $authority->claimed_face_value !== (int) $authority->amount_irr) {
                    $this->recordFindingInConnection($connection, $authority, 'unsupported_settlement_currency_or_amount', 'high', $providerCode, $evidence, $correlationId);
                    $this->createReview($connection, $authority, 'unsupported_settlement_currency_or_amount', $correlationId);

                    return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
                }
                if ($authority->verification_mode === 'automatic_with_manual_approval_above_limit'
                    && $authority->manual_approval_limit_face_value !== null
                    && (int) $authority->claimed_face_value > (int) $authority->manual_approval_limit_face_value) {
                    $this->createReview($connection, $authority, 'manual_approval_threshold', $correlationId);

                    return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
                }

                $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'valid_unreserved']);

                return 'ready';
            }

            if (in_array($evidence->outcome, ['pending', 'uncertain', 'unavailable'], true)) {
                if ($this->allowsManualFallback((string) $authority->verification_mode)) {
                    $this->recordFindingInConnection($connection, $authority, 'validation_not_authoritative', 'warning', $providerCode, $evidence, $correlationId);
                    $this->createReview($connection, $authority, 'validation_not_authoritative', $correlationId);

                    return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
                }
                $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'provider_unavailable']);
                $this->failIntent($connection, $authority, $correlationId, 'gift_card_provider_unavailable');

                return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
            }

            $terminalState = match ($evidence->status) {
                'already_used' => 'already_used',
                'expired' => 'expired',
                default => 'invalid',
            };
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => $terminalState]);
            $this->failIntent($connection, $authority, $correlationId, 'gift_card_'.$terminalState);

            return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
        }, 3);
    }

    private function routeMissingRedeemCapability(string $submissionPublicId, string $providerCode, string $correlationId): GiftCardProcessingReceipt
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $correlationId): GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null || $authority->state !== 'valid_unreserved') {
                throw new RuntimeException('Gift-card redeem capability resolution state changed.');
            }
            $this->recordFindingInConnection($connection, $authority, 'redeem_unsupported', 'high', $providerCode, null, $correlationId);
            if ($this->allowsManualFallback((string) $authority->verification_mode)) {
                $this->createReview($connection, $authority, 'redeem_unsupported', $correlationId);
            } else {
                $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'provider_unavailable']);
                $this->failIntent($connection, $authority, $correlationId, 'gift_card_redeem_unsupported');
            }

            return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
        }, 3);
    }

    /** @param list<string> $fromStates */
    private function beginProviderMutation(string $submissionPublicId, string $operation, array $fromStates, string $toState): GiftCardProviderRequest
    {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $operation, $fromStates, $toState): GiftCardProviderRequest {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card submission does not exist.');
            }
            if (! in_array($authority->state, $fromStates, true)) {
                throw new RuntimeException('Gift-card provider mutation state is not retry-safe.');
            }
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => $toState]);

            return $this->providerRequest($authority, $operation);
        }, 3);
    }

    /** @return 'ready'|GiftCardProcessingReceipt */
    private function recordReserve(
        string $submissionPublicId,
        string $providerCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): string|GiftCardProcessingReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $evidence, $correlationId): string|GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null || $authority->state !== 'reserving') {
                throw new RuntimeException('Gift-card reserve state changed before provider evidence was recorded.');
            }
            $this->validateProviderEvidence($evidence, 'reserve');
            $this->recordProviderEvent($connection, $authority, $providerCode, $evidence);
            if ($evidence->outcome === 'success' && $evidence->status === 'reserved') {
                if (! $this->providerEvidenceMatchesClaim($authority, $evidence)) {
                    return $this->routeMismatch($connection, $authority, $providerCode, $evidence, $correlationId, 'reserve_identity_mismatch');
                }
                $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'reserved']);

                return 'ready';
            }
            $this->recordFindingInConnection($connection, $authority, 'reserve_not_confirmed', 'high', $providerCode, $evidence, $correlationId);
            $this->createReview($connection, $authority, 'reserve_not_confirmed', $correlationId);

            return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
        }, 3);
    }

    private function recordNonSuccessfulRedeem(
        string $submissionPublicId,
        string $providerCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
    ): GiftCardProcessingReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $providerCode, $evidence, $correlationId): GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null || $authority->state !== 'redeeming') {
                throw new RuntimeException('Gift-card redeem state changed before provider evidence was recorded.');
            }
            $this->validateProviderEvidence($evidence, 'redeem');
            $this->recordProviderEvent($connection, $authority, $providerCode, $evidence);
            if (in_array($evidence->outcome, ['pending', 'uncertain', 'unavailable'], true)) {
                $this->recordFindingInConnection($connection, $authority, 'redeem_not_confirmed', 'critical', $providerCode, $evidence, $correlationId);

                return $this->receipt($connection, $authority, false);
            }
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'rejected']);
            $this->failIntent($connection, $authority, $correlationId, 'gift_card_redeem_rejected');

            return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
        }, 3);
    }

    private function routeMismatch(
        Connection $connection,
        stdClass $authority,
        string $providerCode,
        GiftCardProviderEvidence $evidence,
        string $correlationId,
        string $findingType,
    ): GiftCardProcessingReceipt {
        $this->recordFindingInConnection($connection, $authority, $findingType, 'high', $providerCode, $evidence, $correlationId);
        if ($this->allowsManualFallback((string) $authority->verification_mode)) {
            $this->createReview($connection, $authority, $findingType, $correlationId);
        } else {
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'rejected']);
            $this->failIntent($connection, $authority, $correlationId, 'gift_card_provider_identity_mismatch');
        }

        return $this->receipt($connection, $this->submissionAuthority($connection, (string) $authority->submission_public_id) ?? $authority, false);
    }

    private function handleProviderFailure(
        string $submissionPublicId,
        string $findingType,
        string $providerCode,
        string $correlationId,
        bool $mutationUncertain,
        ?Throwable $exception = null,
    ): GiftCardProcessingReceipt {
        unset($exception);

        return $this->database->connection()->transaction(function (Connection $connection) use ($submissionPublicId, $findingType, $providerCode, $correlationId, $mutationUncertain): GiftCardProcessingReceipt {
            $authority = $this->submissionAuthority($connection, $submissionPublicId, true);
            if ($authority === null) {
                throw new DomainException('Gift-card submission does not exist.');
            }
            $this->recordFindingInConnection($connection, $authority, $findingType, $mutationUncertain ? 'critical' : 'warning', $providerCode, null, $correlationId);
            if ($mutationUncertain) {
                return $this->receipt($connection, $authority, false);
            }
            if ($this->allowsManualFallback((string) $authority->verification_mode)) {
                $this->createReview($connection, $authority, $findingType, $correlationId);
            } else {
                $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'provider_unavailable']);
                $this->failIntent($connection, $authority, $correlationId, 'gift_card_provider_unavailable');
            }

            return $this->receipt($connection, $this->submissionAuthority($connection, $submissionPublicId) ?? $authority, false);
        }, 3);
    }

    private function createReview(Connection $connection, stdClass $authority, string $reasonCode, string $correlationId): void
    {
        $existing = $connection->table('gift_card_reviews')->where('gift_card_submission_id', $authority->submission_id)->lockForUpdate()->first();
        if ($existing === null) {
            $now = $this->timestamp();
            $connection->table('gift_card_reviews')->insert([
                'public_id' => (string) Str::ulid(),
                'gift_card_submission_id' => $authority->submission_id,
                'reason_code' => substr($reasonCode, 0, 64),
                'state' => 'pending',
                'decided_by_administrator_id' => null,
                'decision_reason' => null,
                'decided_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } elseif ($existing->state !== 'pending') {
            throw new RuntimeException('Gift-card review was already decided.');
        }
        if ($authority->state !== 'pending_manual_review') {
            $connection->table('gift_card_submissions')->where('id', $authority->submission_id)->update(['state' => 'pending_manual_review']);
        }
        $this->advanceIntentToPendingReview($connection, $authority, $correlationId);
    }

    private function providerRequest(stdClass $authority, string $operation): GiftCardProviderRequest
    {
        $code = $authority->encrypted_code === null ? null : $this->encrypter->decryptString((string) $authority->encrypted_code);

        return new GiftCardProviderRequest(
            hash('sha256', 'gift-card:'.$authority->submission_public_id.':'.$operation),
            (string) $authority->submission_public_id,
            (string) $authority->type_code,
            (string) $authority->claimed_brand,
            $authority->claimed_region === null ? null : (string) $authority->claimed_region,
            (string) $authority->claimed_currency,
            (int) $authority->claimed_face_value,
            $code,
            $authority->private_image_reference === null ? null : (string) $authority->private_image_reference,
        );
    }

    private function advanceIntentToVerifying(Connection $connection, stdClass $authority, string $correlationId): void
    {
        if ($authority->intent_state === PaymentIntentState::Verifying->value) {
            return;
        }
        if ($authority->intent_state !== PaymentIntentState::Submitted->value) {
            throw new RuntimeException('Gift-card payment intent is not ready for verification.');
        }
        $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::Submitted, PaymentIntentState::Verifying, 'gift_card_verification_started', $correlationId);
    }

    private function advanceIntentToPendingReview(Connection $connection, stdClass $authority, string $correlationId): void
    {
        $state = PaymentIntentState::from((string) $authority->intent_state);
        if ($state === PaymentIntentState::Submitted) {
            $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::Submitted, PaymentIntentState::Verifying, 'gift_card_verification_started', $correlationId);
            $state = PaymentIntentState::Verifying;
        }
        if ($state === PaymentIntentState::PendingManualReview) {
            return;
        }
        if ($state !== PaymentIntentState::Verifying) {
            throw new RuntimeException('Gift-card payment intent cannot enter manual review from its current state.');
        }
        $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::Verifying, PaymentIntentState::PendingManualReview, 'gift_card_manual_review_required', $correlationId);
    }

    private function failIntent(Connection $connection, stdClass $authority, string $correlationId, string $reason): void
    {
        $stateValue = (string) $connection->table('payment_intents')->where('id', $authority->payment_intent_id)->value('state');
        $state = PaymentIntentState::from($stateValue);
        if ($state === PaymentIntentState::Failed) {
            return;
        }
        if ($state === PaymentIntentState::Submitted) {
            $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::Submitted, PaymentIntentState::Verifying, 'gift_card_verification_started', $correlationId);
            $state = PaymentIntentState::Verifying;
        }
        if ($state === PaymentIntentState::PendingManualReview) {
            $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::PendingManualReview, PaymentIntentState::Failed, $reason, $correlationId);

            return;
        }
        if ($state !== PaymentIntentState::Verifying) {
            throw new RuntimeException('Gift-card payment intent cannot fail from its current state.');
        }
        $this->transitionIntent($connection, (int) $authority->payment_intent_id, PaymentIntentState::Verifying, PaymentIntentState::Failed, $reason, $correlationId);
    }

    private function transitionIntent(Connection $connection, int $intentId, PaymentIntentState $from, PaymentIntentState $to, string $reason, string $correlationId): void
    {
        $from->transitionTo($to);
        $updated = $connection->table('payment_intents')->where('id', $intentId)->where('state', $from->value)->update([
            'state' => $to->value,
            'updated_at' => $this->timestamp(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('Gift-card payment intent state changed concurrently.');
        }
        $connection->table('payment_intent_state_histories')->insert([
            'payment_intent_id' => $intentId,
            'from_state' => $from->value,
            'to_state' => $to->value,
            'reason_code' => $reason,
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function validateProviderEvidence(GiftCardProviderEvidence $evidence, string $operation): void
    {
        if ($evidence->operation !== $operation
            || ! in_array($evidence->outcome, ['success', 'pending', 'rejected', 'uncertain', 'unavailable'], true)) {
            throw new DomainException('Gift-card provider evidence operation/outcome is invalid.');
        }
        $this->assertPrintable($evidence->providerEventId, 'Gift-card provider event ID', 1, 191);
        $this->assertSha256($evidence->evidenceHash, 'Gift-card provider evidence hash');
        if ($evidence->providerTransactionId !== null) {
            $this->assertPrintable($evidence->providerTransactionId, 'Gift-card provider transaction ID', 1, 191);
        }
        if (strlen($evidence->status) < 1 || strlen($evidence->status) > 64 || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $evidence->status) !== 1) {
            throw new DomainException('Gift-card provider status is invalid.');
        }
    }

    private function providerEvidenceMatchesClaim(stdClass $authority, GiftCardProviderEvidence $evidence): bool
    {
        return $evidence->faceValue !== null
            && $evidence->currency !== null
            && $evidence->brand !== null
            && (int) $authority->claimed_face_value === $evidence->faceValue
            && $authority->claimed_currency === $evidence->currency
            && $authority->claimed_brand === $evidence->brand
            && (($authority->claimed_region === null) === ($evidence->region === null))
            && ($evidence->region === null || hash_equals((string) $authority->claimed_region, $evidence->region));
    }

    private function recordProviderEvent(Connection $connection, stdClass $authority, string $providerCode, GiftCardProviderEvidence $evidence): stdClass
    {
        if ($authority->provider_code !== $providerCode) {
            throw new RuntimeException('Gift-card provider evidence does not match type authority.');
        }
        $existing = $connection->table('gift_card_provider_events')
            ->where('provider_code', $providerCode)
            ->where('provider_event_id', $evidence->providerEventId)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if ((int) $existing->gift_card_submission_id !== (int) $authority->submission_id
                || $existing->operation !== $evidence->operation
                || $existing->outcome !== $evidence->outcome
                || $existing->provider_status !== $evidence->status
                || $existing->provider_transaction_id !== $evidence->providerTransactionId
                || (($existing->face_value === null) !== ($evidence->faceValue === null))
                || ($evidence->faceValue !== null && (int) $existing->face_value !== $evidence->faceValue)
                || $existing->currency !== $evidence->currency
                || $existing->brand !== $evidence->brand
                || $existing->region !== $evidence->region
                || ! hash_equals(strtolower((string) $existing->evidence_hash), strtolower($evidence->evidenceHash))) {
                throw new RuntimeException('Gift-card provider event replay conflicts with accepted evidence.');
            }

            return $existing;
        }
        $id = (int) $connection->table('gift_card_provider_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'provider_code' => $providerCode,
            'provider_event_id' => $evidence->providerEventId,
            'operation' => $evidence->operation,
            'outcome' => $evidence->outcome,
            'provider_status' => $evidence->status,
            'provider_transaction_id' => $evidence->providerTransactionId,
            'face_value' => $evidence->faceValue,
            'currency' => $evidence->currency,
            'brand' => $evidence->brand,
            'region' => $evidence->region,
            'evidence_hash' => strtolower($evidence->evidenceHash),
            'occurred_at' => $this->databaseDateTime($evidence->occurredAt),
            'created_at' => $this->timestamp(),
        ]);
        $row = $connection->table('gift_card_provider_events')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('Gift-card provider event persistence failed.');
        }

        return $row;
    }

    private function receipt(Connection $connection, stdClass $authority, bool $replayed): GiftCardProcessingReceipt
    {
        $review = $connection->table('gift_card_reviews')->where('gift_card_submission_id', $authority->submission_id)->first(['public_id']);
        $redemption = $connection->table('gift_card_redemptions')->where('gift_card_submission_id', $authority->submission_id)->first(['public_id', 'purchase_settlement_id']);
        $settlementPublicId = null;
        if ($redemption !== null && $redemption->purchase_settlement_id !== null) {
            $settlementPublicId = $connection->table('purchase_settlements')->where('id', $redemption->purchase_settlement_id)->value('public_id');
        }

        return new GiftCardProcessingReceipt(
            (string) $authority->submission_public_id,
            (string) $authority->state,
            $review === null ? null : (string) $review->public_id,
            $redemption === null ? null : (string) $redemption->public_id,
            $settlementPublicId === null ? null : (string) $settlementPublicId,
            $replayed,
        );
    }

    private function submissionAuthority(Connection $connection, string $publicId, bool $lock = false): ?stdClass
    {
        $query = $connection->table('gift_card_submissions as submission')
            ->join('gift_card_types as type', 'type.id', '=', 'submission.gift_card_type_id')
            ->join('payment_intents as intent', 'intent.id', '=', 'submission.payment_intent_id')
            ->where('submission.public_id', $publicId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first([
            'submission.id as submission_id', 'submission.public_id as submission_public_id',
            'submission.payment_intent_id', 'submission.encrypted_code', 'submission.private_image_reference',
            'submission.claimed_face_value', 'submission.claimed_currency', 'submission.claimed_brand',
            'submission.claimed_region', 'submission.state',
            'type.type_code', 'type.verification_mode', 'type.manual_approval_limit_face_value', 'type.provider_code',
            'intent.public_id as intent_public_id', 'intent.amount_irr', 'intent.currency', 'intent.state as intent_state',
            'intent.user_id', 'intent.source_quote_public_id',
        ]);
    }

    private function hasPersistedRedemption(string $submissionPublicId): bool
    {
        return $this->database->connection()->table('gift_card_redemptions as redemption')
            ->join('gift_card_submissions as submission', 'submission.id', '=', 'redemption.gift_card_submission_id')
            ->where('submission.public_id', $submissionPublicId)
            ->exists();
    }

    private function allowsManualFallback(string $verificationMode): bool
    {
        return in_array($verificationMode, [
            'automatic_then_manual',
            'manual_fallback_on_provider_failure',
            'automatic_with_manual_approval_above_limit',
        ], true);
    }

    private function recordFinding(
        string $submissionPublicId,
        string $type,
        string $severity,
        ?string $providerCode,
        ?string $providerEventId,
        ?string $providerTransactionId,
        ?string $evidenceHash,
        string $correlationId,
    ): void {
        $connection = $this->database->connection();
        $authority = $this->submissionAuthority($connection, $submissionPublicId);
        if ($authority !== null) {
            $this->recordFindingInConnection($connection, $authority, $type, $severity, $providerCode, null, $correlationId, $providerEventId, $providerTransactionId, $evidenceHash);
        }
    }

    private function recordFindingInConnection(
        Connection $connection,
        stdClass $authority,
        string $type,
        string $severity,
        ?string $providerCode,
        ?GiftCardProviderEvidence $evidence,
        string $correlationId,
        ?string $providerEventId = null,
        ?string $providerTransactionId = null,
        ?string $evidenceHash = null,
    ): void {
        $providerEventId ??= $evidence?->providerEventId;
        $providerTransactionId ??= $evidence?->providerTransactionId;
        $evidenceHash ??= $evidence?->evidenceHash;
        $key = hash('sha256', implode("\0", [
            (string) $authority->submission_public_id,
            $type,
            $providerCode ?? '',
            $providerEventId ?? '',
            $providerTransactionId ?? '',
            $evidenceHash ?? '',
        ]));
        $connection->table('gift_card_reconciliation_findings')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'gift_card_submission_id' => $authority->submission_id,
            'finding_key' => $key,
            'finding_type' => substr($type, 0, 64),
            'severity' => $severity,
            'provider_code' => $providerCode,
            'provider_event_id' => $providerEventId,
            'provider_transaction_id' => $providerTransactionId,
            'evidence_hash' => $evidenceHash === null ? null : strtolower($evidenceHash),
            'correlation_id' => $correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9:_.-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintable(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[\x21-\x7E]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            throw new DomainException($label.' must be a SHA-256 hex digest.');
        }
    }

    private function databaseDateTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function timestamp(): string
    {
        return $this->databaseDateTime($this->clock->now());
    }
}
