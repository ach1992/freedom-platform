<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Throwable;

final readonly class InitialProvisioningOutboxHandler implements OutboxEventHandler
{
    private const EVENT_TYPE = 'provisioning.initial.requested';

    public function __construct(
        private InitialProvisioningExecutor $executor,
        private InitialProvisioningRecoveryService $recovery,
        private InitialProvisioningDeliveryScheduler $delivery,
    ) {}

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    /** @requirement PAY-003 PRV-002 PRV-003 SVC-002 ARCH-004 SEC-002 QUA-001 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $operationPublicId = $message->payload['provisioning_operation_public_id'] ?? null;
        if ($message->eventType !== self::EVENT_TYPE
            || $message->aggregateType !== 'provisioning_operation'
            || ! is_string($operationPublicId)
            || ! hash_equals($message->aggregateId, $operationPublicId)
            || ! str_ends_with($message->eventKey, ':'.$operationPublicId)
        ) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            // Establish the durable initial-delivery admission fence before provisioning can
            // become externally visible as succeeded. The fence survives worker interruption
            // until the deterministic initial Delivery Attempt has been materialized.
            $this->delivery->establishFence($operationPublicId);

            // A live running attempt may outlast the shared Outbox lease. Never let a reclaimed
            // message reinterpret that live provider call as interrupted or enter the executor.
            // A stale running attempt first becomes durably uncertain and is retried on a later
            // dispatch so the coordinator re-enters through authoritative lookup before create.
            $preparedState = $this->recovery->prepare($operationPublicId);
            if ($preparedState === ProvisioningState::Running
                || $preparedState === ProvisioningState::UncertainRemoteResult) {
                return OutboxDispatchOutcome::RetryableFailure;
            }

            $receipt = $this->executor->execute($operationPublicId);
        } catch (DomainException) {
            // Domain invalidity before a remote attempt is definitive, but a DomainException can
            // also escape from local persistence after the provider has already returned. Re-read
            // durable state before deciding whether the delivery fence can be released.
            try {
                $stateAfterFailure = $this->recovery->prepare($operationPublicId);
            } catch (Throwable) {
                // The fence may already exist and current provisioning state is unknown. Preserve
                // retry/review so the control cannot become orphaned behind a definitive Outbox result.
                return OutboxDispatchOutcome::RetryableFailure;
            }

            if ($stateAfterFailure === ProvisioningState::Succeeded) {
                try {
                    $this->delivery->schedule($operationPublicId, $message->correlationId);
                } catch (Throwable) {
                    return OutboxDispatchOutcome::RetryableFailure;
                }

                return OutboxDispatchOutcome::Success;
            }

            if (in_array($stateAfterFailure, [
                ProvisioningState::Queued,
                ProvisioningState::Running,
                ProvisioningState::RetryScheduled,
                ProvisioningState::UncertainRemoteResult,
                ProvisioningState::Compensating,
            ], true)) {
                return OutboxDispatchOutcome::RetryableFailure;
            }

            try {
                $this->delivery->releaseFence($operationPublicId);
            } catch (Throwable) {
                return OutboxDispatchOutcome::RetryableFailure;
            }

            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            // Escaping executor failures occur outside the guarded provider-call boundary and are safe to retry.
            return OutboxDispatchOutcome::RetryableFailure;
        }

        if ($receipt->state === ProvisioningState::Succeeded) {
            try {
                $this->delivery->schedule($operationPublicId, $message->correlationId);
            } catch (Throwable) {
                // A succeeded Service must not lose its deterministic initial delivery intent.
                // Keep the durable fence and retry/review rather than finalizing the Outbox early.
                return OutboxDispatchOutcome::RetryableFailure;
            }

            return OutboxDispatchOutcome::Success;
        }

        if (in_array($receipt->state, [
            ProvisioningState::FailedFinal,
            ProvisioningState::NeedsReview,
            ProvisioningState::Compensated,
        ], true)) {
            try {
                $this->delivery->releaseFence($operationPublicId);
            } catch (Throwable) {
                return OutboxDispatchOutcome::RetryableFailure;
            }

            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        return OutboxDispatchOutcome::RetryableFailure;
    }
}
