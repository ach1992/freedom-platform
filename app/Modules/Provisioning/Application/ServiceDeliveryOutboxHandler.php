<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceDeliveryEffectState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use Closure;
use DomainException;
use RuntimeException;
use Throwable;

final readonly class ServiceDeliveryOutboxHandler implements OutboxEventHandler
{
    /** @param Closure():ServiceDeliveryEffectExecutor $executorResolver */
    public function __construct(
        private Closure $executorResolver,
    ) {}

    public function eventType(): string
    {
        return ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return ServiceDeliveryAttemptQueueService::OUTBOX_CONTRACT_VERSION;
    }

    /** @requirement SVC-002 SVC-014 ARCH-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $attemptPublicId = $message->payload['service_delivery_attempt_public_id'] ?? null;
        if ($message->eventType !== ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== ServiceDeliveryAttemptQueueService::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== ServiceDeliveryAttemptQueueService::OUTBOX_AGGREGATE_TYPE
            || ! is_string($attemptPublicId)
            || ! hash_equals($message->aggregateId, $attemptPublicId)
            || ! hash_equals(
                ServiceDeliveryAttemptQueueService::OUTBOX_EVENT_KEY_PREFIX.$attemptPublicId,
                $message->eventKey,
            )) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $executor = $this->executor();
            $preparedState = $executor->recover($attemptPublicId);
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        $preparedOutcome = $this->outcomeForPreparedState($preparedState);
        if ($preparedOutcome !== null) {
            return $preparedOutcome;
        }

        try {
            $receipt = $executor->execute($attemptPublicId);
        } catch (DomainException) {
            return $this->outcomeAfterFailure($executor, $attemptPublicId, true);
        } catch (Throwable) {
            return $this->outcomeAfterFailure($executor, $attemptPublicId, false);
        }

        return $this->outcomeForState($receipt->state);
    }

    private function executor(): ServiceDeliveryEffectExecutor
    {
        $executor = ($this->executorResolver)();
        if (! $executor instanceof ServiceDeliveryEffectExecutor) {
            throw new RuntimeException('Service delivery executor resolver returned an invalid instance.');
        }

        return $executor;
    }

    private function outcomeForPreparedState(?ServiceDeliveryEffectState $state): ?OutboxDispatchOutcome
    {
        if ($state === null || $state === ServiceDeliveryEffectState::Prepared) {
            return null;
        }

        return $this->outcomeForState($state);
    }

    private function outcomeForState(ServiceDeliveryEffectState $state): OutboxDispatchOutcome
    {
        return match ($state) {
            ServiceDeliveryEffectState::Prepared => OutboxDispatchOutcome::RetryableFailure,
            ServiceDeliveryEffectState::Sending,
            ServiceDeliveryEffectState::Uncertain => OutboxDispatchOutcome::UncertainResult,
            ServiceDeliveryEffectState::Succeeded => OutboxDispatchOutcome::Success,
            ServiceDeliveryEffectState::FailedFinal => OutboxDispatchOutcome::DefinitiveFailure,
        };
    }

    private function outcomeAfterFailure(
        ServiceDeliveryEffectExecutor $executor,
        string $attemptPublicId,
        bool $domainFailure,
    ): OutboxDispatchOutcome {
        try {
            $state = $executor->recover($attemptPublicId);
        } catch (Throwable) {
            return $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure;
        }

        if ($state === null || $state === ServiceDeliveryEffectState::Prepared) {
            return $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure;
        }

        return $this->outcomeForState($state);
    }
}
