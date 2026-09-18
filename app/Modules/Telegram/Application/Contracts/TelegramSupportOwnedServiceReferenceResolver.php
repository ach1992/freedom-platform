<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceResolution;

interface TelegramSupportOwnedServiceReferenceResolver
{
    public function resolveForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramSupportBusinessReferenceResolution;
}
