<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Throwable;

final readonly class ServiceMutationOutboxHandler implements OutboxEventHandler
{
    public function __construct(
        private ServiceMutationExecutor $executor,
        private ServiceMutationRecoveryService $recovery,
    ) {}

    public function eventType(): string
    {
        return ServiceMutationQueueService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return ServiceMutationQueueService::OUTBOX_CONTRACT_VERSION;
    }

    /** @requirement SVC-004 PRV-002 PRV-003 ARCH-004 SEC-002 QUA-001 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $operationPublicId = $message->payload['provisioning_operation_public_id'] ?? null;
        if ($message->eventType !== ServiceMutationQueueService::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== ServiceMutationQueueService::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== ServiceMutationQueueService::OUTBOX_AGGREGATE_TYPE
            || ! is_string($operationPublicId)
            || ! hash_equals($message->aggregateId, $operationPublicId)
            || ! hash_equals(
                ServiceMutationQueueService::OUTBOX_EVENT_KEY_PREFIX.$operationPublicId,
                $message->eventKey,
            )) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $preparedState = $this->recovery->prepare($operationPublicId);
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        $preparedOutcome = $this->preparedOutcome($preparedState);
        if ($preparedOutcome !== null) {
            return $preparedOutcome;
        }

        try {
            $receipt = $this->executor->execute($operationPublicId);
        } catch (DomainException) {
            return $this->outcomeAfterFailure($operationPublicId, true);
        } catch (Throwable) {
            return $this->outcomeAfterFailure($operationPublicId, false);
        }

        return $this->outcomeForState($receipt->state);
    }

    private function preparedOutcome(ProvisioningState $state): ?OutboxDispatchOutcome
    {
        return match ($state) {
            ProvisioningState::Queued, ProvisioningState::RetryScheduled => null,
            ProvisioningState::Running, ProvisioningState::Compensating => OutboxDispatchOutcome::RetryableFailure,
            ProvisioningState::Succeeded => OutboxDispatchOutcome::Success,
            ProvisioningState::UncertainRemoteResult => OutboxDispatchOutcome::UncertainResult,
            ProvisioningState::FailedFinal, ProvisioningState::NeedsReview, ProvisioningState::Compensated => OutboxDispatchOutcome::DefinitiveFailure,
        };
    }

    private function outcomeForState(ProvisioningState $state): OutboxDispatchOutcome
    {
        return match ($state) {
            ProvisioningState::Succeeded => OutboxDispatchOutcome::Success,
            ProvisioningState::UncertainRemoteResult => OutboxDispatchOutcome::UncertainResult,
            ProvisioningState::FailedFinal, ProvisioningState::NeedsReview, ProvisioningState::Compensated => OutboxDispatchOutcome::DefinitiveFailure,
            ProvisioningState::Queued, ProvisioningState::RetryScheduled, ProvisioningState::Running, ProvisioningState::Compensating => OutboxDispatchOutcome::RetryableFailure,
        };
    }

    private function outcomeAfterFailure(string $operationPublicId, bool $domainFailure): OutboxDispatchOutcome
    {
        try {
            $state = $this->recovery->prepare($operationPublicId);
        } catch (Throwable) {
            return $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure;
        }

        return match ($state) {
            ProvisioningState::Succeeded => OutboxDispatchOutcome::Success,
            ProvisioningState::UncertainRemoteResult => OutboxDispatchOutcome::UncertainResult,
            ProvisioningState::Running => OutboxDispatchOutcome::RetryableFailure,
            ProvisioningState::FailedFinal, ProvisioningState::NeedsReview, ProvisioningState::Compensated => OutboxDispatchOutcome::DefinitiveFailure,
            ProvisioningState::Queued, ProvisioningState::RetryScheduled, ProvisioningState::Compensating => $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure,
        };
    }
}
