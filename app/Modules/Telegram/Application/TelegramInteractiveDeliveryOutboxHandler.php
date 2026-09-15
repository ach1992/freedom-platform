<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use Closure;

final readonly class TelegramInteractiveDeliveryOutboxHandler implements OutboxEventHandler
{
    private TelegramDeliveryOutboxHandler $delegate;

    /** @param Closure():TelegramDeliveryOperationExecutor $executorResolver */
    public function __construct(Closure $executorResolver)
    {
        $this->delegate = new TelegramDeliveryOutboxHandler(
            $executorResolver,
            TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE,
        );
    }

    public function eventType(): string
    {
        return TelegramDeliveryQueueService::OUTBOX_EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_INTERACTIVE;
    }

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        return $this->delegate->handle($message);
    }
}
