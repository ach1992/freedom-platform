<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseDiscountQuotePreview;
use DateTimeImmutable;

interface TelegramCustomerPurchaseDiscountQuote
{
    public function requoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $sourceQuotePublicId,
        string $sourceQuoteConfigurationHash,
        string $code,
        DateTimeImmutable $acceptedAt,
        string $operationKey,
    ): TelegramCustomerPurchaseDiscountQuotePreview;
}
