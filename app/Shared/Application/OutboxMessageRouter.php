<?php

declare(strict_types=1);

namespace App\Shared\Application;

use InvalidArgumentException;
use LogicException;

final class OutboxMessageRouter implements OutboxMessageHandler
{
    /** @var array<string, OutboxEventHandler> */
    private array $handlers = [];

    /**
     * @param  iterable<OutboxEventHandler>  $handlers
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $eventType = $handler->eventType();

            if ($eventType === '' || strlen($eventType) > 191) {
                throw new InvalidArgumentException('Outbox handler event type must contain 1-191 characters.');
            }

            if (isset($this->handlers[$eventType])) {
                throw new LogicException('Outbox event type has more than one registered handler.');
            }

            $this->handlers[$eventType] = $handler;
        }
    }

    /** @requirement ARCH-004 OPS-003 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $handler = $this->handlers[$message->eventType] ?? null;

        if ($handler === null) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        return $handler->handle($message);
    }
}
