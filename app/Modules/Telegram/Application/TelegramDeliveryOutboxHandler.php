<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramDeliveryOperationState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use Closure;
use DomainException;
use RuntimeException;
use Throwable;

final readonly class TelegramDeliveryOutboxHandler implements OutboxEventHandler
{
    /** @param Closure():TelegramDeliveryOperationExecutor $executorResolver */
    public function __construct(private Closure $executorResolver) {}

    public function eventType(): string
    {
        return TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION;
    }

    /** @requirement ARCH-004 SEC-002 SEC-008 OPS-003 QUA-001 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $publicId = $message->payload['telegram_delivery_operation_public_id'] ?? null;
        if ($message->eventType !== TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== TelegramDeliveryQueueService::OUTBOX_AGGREGATE_TYPE
            || ! is_string($publicId)
            || ! hash_equals($message->aggregateId, $publicId)
            || ! hash_equals(TelegramDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$publicId, $message->eventKey)) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $executor = $this->executor();
            $state = $executor->recover($publicId, $message->id, $message->correlationId);
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }

        if (! in_array($state, [TelegramDeliveryOperationState::Prepared, TelegramDeliveryOperationState::Retryable], true)) {
            return $this->outcome($state);
        }

        try {
            return $this->outcome($executor->execute($publicId, $message->id, $message->correlationId)->state);
        } catch (DomainException) {
            return $this->outcomeAfterFailure($publicId, $message->id, $message->correlationId, true);
        } catch (Throwable) {
            return $this->outcomeAfterFailure($publicId, $message->id, $message->correlationId, false);
        }
    }

    private function executor(): TelegramDeliveryOperationExecutor
    {
        $executor = ($this->executorResolver)();
        if (! $executor instanceof TelegramDeliveryOperationExecutor) {
            throw new RuntimeException('Telegram delivery executor resolver returned an invalid instance.');
        }

        return $executor;
    }

    private function outcomeAfterFailure(
        string $publicId,
        string $outboxEventId,
        string $correlationId,
        bool $domainFailure,
    ): OutboxDispatchOutcome {
        try {
            $state = $this->executor()->recover($publicId, $outboxEventId, $correlationId);
        } catch (Throwable) {
            return $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure;
        }

        if (in_array($state, [TelegramDeliveryOperationState::Prepared, TelegramDeliveryOperationState::Retryable], true)) {
            return $domainFailure
                ? OutboxDispatchOutcome::DefinitiveFailure
                : OutboxDispatchOutcome::RetryableFailure;
        }

        return $this->outcome($state);
    }

    private function outcome(TelegramDeliveryOperationState $state): OutboxDispatchOutcome
    {
        return match ($state) {
            TelegramDeliveryOperationState::Prepared,
            TelegramDeliveryOperationState::Retryable => OutboxDispatchOutcome::RetryableFailure,
            TelegramDeliveryOperationState::Sending,
            TelegramDeliveryOperationState::Uncertain => OutboxDispatchOutcome::UncertainResult,
            TelegramDeliveryOperationState::Succeeded => OutboxDispatchOutcome::Success,
            TelegramDeliveryOperationState::FailedFinal,
            TelegramDeliveryOperationState::ReviewRequired => OutboxDispatchOutcome::DefinitiveFailure,
        };
    }
}
