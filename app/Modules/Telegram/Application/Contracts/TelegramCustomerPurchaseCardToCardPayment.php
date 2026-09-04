<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;
use App\Shared\Application\RestrictedValue;

interface TelegramCustomerPurchaseCardToCardPayment
{
    public function reserveForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardReservation;

    public function destinationPanForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): RestrictedValue;
}
