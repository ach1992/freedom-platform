<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;

interface TelegramCustomerPurchasePaymentMethods
{
    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision;
}
