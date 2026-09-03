<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use DateTimeImmutable;

interface TelegramCustomerPurchaseQuote
{
    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview;
}
