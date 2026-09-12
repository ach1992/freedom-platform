<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerTrialCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerTrialOffering;

interface TelegramCustomerTrialCatalog
{
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramCustomerTrialCatalogPage;

    public function offeringForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $selectionToken,
    ): TelegramCustomerTrialOffering;
}
