<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramOwnedServiceDetail;
use App\Modules\Telegram\Application\TelegramOwnedServicePage;

interface TelegramOwnedServiceProjection
{
    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramOwnedServicePage;

    public function detailForSelf(int $actorUserId, int $subjectUserId, string $selectionToken): TelegramOwnedServiceDetail;
}
