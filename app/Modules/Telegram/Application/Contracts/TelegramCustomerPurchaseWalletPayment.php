<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletPaid;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseWalletReservation;

interface TelegramCustomerPurchaseWalletPayment
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
    ): TelegramCustomerPurchaseWalletReservation;

    public function captureForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $paymentIntentPublicId,
        string $operationKey,
    ): TelegramCustomerPurchaseWalletPaid;

    public function cancelForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $decisionPublicId,
        string $paymentIntentPublicId,
        string $operationKey,
    ): void;
}
