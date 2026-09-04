<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramCardToCardProtectedDeliveryState;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use Closure;
use RuntimeException;
use Throwable;

final readonly class TelegramCardToCardProtectedDeliveryOutboxHandler implements OutboxEventHandler
{
    /** @param Closure():TelegramCardToCardProtectedDeliveryExecutor $executorResolver */
    public function __construct(private Closure $executorResolver) {}

    public function eventType(): string
    {
        return TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_CONTRACT_VERSION;
    }

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $publicId = $message->payload['delivery_public_id'] ?? null;
        if ($message->eventType !== TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_EVENT_TYPE
            || $message->contractVersion !== TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_CONTRACT_VERSION
            || $message->aggregateType !== TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_AGGREGATE_TYPE
            || ! is_string($publicId)
            || ! hash_equals($message->aggregateId, $publicId)
            || ! hash_equals(TelegramCardToCardProtectedDeliveryQueueService::OUTBOX_EVENT_KEY_PREFIX.$publicId, $message->eventKey)) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        try {
            $executor = $this->executor();
            $state = $executor->recover($publicId, $message->id, $message->correlationId);
            if ($state === TelegramCardToCardProtectedDeliveryState::Prepared) {
                $state = $executor->execute($publicId, $message->id, $message->correlationId);
            }

            return $this->outcome($state);
        } catch (Throwable) {
            try {
                $state = $this->executor()->recover($publicId, $message->id, $message->correlationId);
            } catch (Throwable) {
                return OutboxDispatchOutcome::RetryableFailure;
            }

            return $state === TelegramCardToCardProtectedDeliveryState::Prepared
                ? OutboxDispatchOutcome::RetryableFailure
                : $this->outcome($state);
        }
    }

    private function executor(): TelegramCardToCardProtectedDeliveryExecutor
    {
        $executor = ($this->executorResolver)();
        if (! $executor instanceof TelegramCardToCardProtectedDeliveryExecutor) {
            throw new RuntimeException('Telegram card-to-card protected delivery executor resolver returned an invalid instance.');
        }

        return $executor;
    }

    private function outcome(TelegramCardToCardProtectedDeliveryState $state): OutboxDispatchOutcome
    {
        return match ($state) {
            TelegramCardToCardProtectedDeliveryState::Prepared => OutboxDispatchOutcome::RetryableFailure,
            TelegramCardToCardProtectedDeliveryState::Sending,
            TelegramCardToCardProtectedDeliveryState::Uncertain => OutboxDispatchOutcome::UncertainResult,
            TelegramCardToCardProtectedDeliveryState::Succeeded => OutboxDispatchOutcome::Success,
            TelegramCardToCardProtectedDeliveryState::FailedFinal,
            TelegramCardToCardProtectedDeliveryState::ReviewRequired => OutboxDispatchOutcome::DefinitiveFailure,
        };
    }
}
