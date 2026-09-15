<?php

declare(strict_types=1);

namespace App\Shared\Application;

interface OutboxMessageHandler
{
    /**
     * Implementations must perform no database transaction work on behalf of the
     * dispatcher and must classify provider/domain results without throwing away
     * an uncertain external-effect state.
     */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome;
}
