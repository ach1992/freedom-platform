<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;

interface TelegramMutationTransport
{
    /**
     * Perform exactly one Telegram mutation attempt. Implementations must not
     * hide retries because a timeout after provider acceptance is ambiguous.
     */
    public function mutate(TelegramMutationRequest $request): TelegramMutationResult;
}
