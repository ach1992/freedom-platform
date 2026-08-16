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

    public function __construct(private InitialProvisioningExecutor $executor) {}

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    /** @requirement PAY-003 PRV-002 PRV-003 ARCH-004 SEC-002 QUA-001 */
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
            $receipt = $this->executor->execute($operationPublicId);
        } catch (DomainException) {
            // Financial/domain invalidity before any provider attempt is definitive for this command.
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            // Escaping executor failures occur outside the guarded provider-call boundary and are safe to retry.
            return OutboxDispatchOutcome::RetryableFailure;
        }

        return match ($receipt->state) {
            ProvisioningState::Succeeded => OutboxDispatchOutcome::Success,
            ProvisioningState::RetryScheduled, ProvisioningState::Running => OutboxDispatchOutcome::RetryableFailure,
            ProvisioningState::UncertainRemoteResult => OutboxDispatchOutcome::UncertainResult,
            ProvisioningState::FailedFinal, ProvisioningState::NeedsReview, ProvisioningState::Compensated => OutboxDispatchOutcome::DefinitiveFailure,
            ProvisioningState::Queued, ProvisioningState::Compensating => OutboxDispatchOutcome::RetryableFailure,
        };
    }
}
