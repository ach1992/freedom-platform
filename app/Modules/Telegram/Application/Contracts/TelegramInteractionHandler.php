<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramInteractionAction;

interface TelegramInteractionHandler
{
    public function flow(): string;

    /**
     * Handle one typed interaction action.
     *
     * The supplied requestKey is stable across recovery/replay. Any owning-domain
     * mutation invoked here must consume that identity through its own idempotent
     * Application contract instead of treating transport delivery as authority.
     */
    public function handle(TelegramInteractionAction $action): void;
}
