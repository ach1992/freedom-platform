<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseCatalogPage;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseOffering;

interface TelegramCustomerPurchaseCatalog
{
    public function pageForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $page,
        int $pageSize,
    ): TelegramCustomerPurchaseCatalogPage;

    public function offeringForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $selectionToken,
    ): TelegramCustomerPurchaseOffering;
}
