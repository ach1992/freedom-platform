<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardSubmission;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseGiftCardType;

interface TelegramCustomerPurchaseGiftCardPayment
{
    /** @return list<TelegramCustomerPurchaseGiftCardType> */
    public function availableTypesForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): array;

    public function submitCodeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $typeCode,
        string $typeConfigurationHash,
        int $claimedFaceValue,
        string $code,
        string $operationKey,
    ): TelegramCustomerPurchaseGiftCardSubmission;
}
