<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseNowPaymentsReceipt;

interface TelegramCustomerPurchaseNowPaymentsPayment
{
    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt;

    public function refreshForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt;
}
