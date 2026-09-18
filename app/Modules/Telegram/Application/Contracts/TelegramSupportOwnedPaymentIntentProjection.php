<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramSupportBusinessReferencePage;
use App\Modules\Telegram\Application\TelegramSupportBusinessReferenceResolution;

interface TelegramSupportOwnedPaymentIntentProjection
{
    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramSupportBusinessReferencePage;

    public function resolveForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramSupportBusinessReferenceResolution;
}
