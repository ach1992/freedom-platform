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
     * DefinitiveNoEffectRetryable is reserved for authoritative evidence that
     * no external mutation occurred; generic HTTP 5xx/timeouts are uncertain.
     */
    public function mutate(TelegramMutationRequest $request): TelegramMutationResult;
}
