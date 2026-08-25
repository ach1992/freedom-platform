<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use LogicException;

final readonly class TelegramInteractionHandlerRegistry
{
    /** @var array<string, TelegramInteractionHandler> */
    private array $handlers;

    /** @param iterable<TelegramInteractionHandler> $handlers */
    public function __construct(iterable $handlers)
    {
        $indexed = [];
        foreach ($handlers as $handler) {
            $flow = $handler->flow();
            if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/', $flow) !== 1) {
                throw new LogicException('Telegram interaction handler flow is invalid.');
            }
            if (isset($indexed[$flow])) {
                throw new LogicException("Duplicate Telegram interaction handler flow: {$flow}");
            }
            $indexed[$flow] = $handler;
        }

        $this->handlers = $indexed;
    }

    public function forFlow(string $flow): ?TelegramInteractionHandler
    {
        return $this->handlers[$flow] ?? null;
    }
}
