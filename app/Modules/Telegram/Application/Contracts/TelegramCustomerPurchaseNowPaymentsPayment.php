<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseNowPaymentsReceipt;

interface TelegramCustomerPurchaseNowPaymentsPayment
{
    public function claimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): string;

    public function executeClaimForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $paymentIntentPublicId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseNowPaymentsReceipt;

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
