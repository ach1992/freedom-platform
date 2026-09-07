<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtInstructions;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseUsdtSubmission;

interface TelegramCustomerPurchaseUsdtPayment
{
    public function initiateForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtInstructions;

    public function submitTxidForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $orderPublicId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $authorityPublicId,
        string $paymentIntentPublicId,
        string $amountQuotePublicId,
        string $txid,
        string $operationKey,
    ): TelegramCustomerPurchaseUsdtSubmission;
}
