<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramBroadcastLifecycleMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationResult;

interface TelegramBroadcastLifecycleTransport
{
    public function mutate(TelegramBroadcastLifecycleMutationRequest $request): TelegramMutationResult;
}
