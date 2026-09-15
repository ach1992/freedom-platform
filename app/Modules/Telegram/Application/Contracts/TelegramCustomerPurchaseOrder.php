<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseOrderReceipt;

interface TelegramCustomerPurchaseOrder
{
    public function openForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $correlationId,
    ): TelegramCustomerPurchaseOrderReceipt;

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
    ): TelegramCustomerPurchaseOrderReceipt;
}
