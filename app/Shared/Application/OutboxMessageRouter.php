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
            $contractVersion = $handler->contractVersion();

            if ($eventType === '' || strlen($eventType) > 191) {
                throw new InvalidArgumentException('Outbox handler event type must contain 1-191 characters.');
            }

            if ($contractVersion < 1 || $contractVersion > 65_535) {
                throw new InvalidArgumentException('Outbox handler contract version must be between 1 and 65535.');
            }

            $route = $this->route($eventType, $contractVersion);
            if (isset($this->handlers[$route])) {
                throw new LogicException('Outbox event contract has more than one registered handler.');
            }

            $this->handlers[$route] = $handler;
        }
    }

    /** @requirement ARCH-004 OPS-003 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $handler = $this->handlers[$this->route($message->eventType, $message->contractVersion)] ?? null;

        if ($handler === null) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        }

        return $handler->handle($message);
    }

    private function route(string $eventType, int $contractVersion): string
    {
        return $eventType.'@v'.$contractVersion;
    }
}
