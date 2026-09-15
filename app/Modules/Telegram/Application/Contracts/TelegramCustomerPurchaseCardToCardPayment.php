<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardDestination;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardReservation;

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

    public function destinationForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
    ): TelegramCustomerPurchaseCardToCardDestination;
}
