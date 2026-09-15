<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodsDecision;
use App\Modules\Telegram\Application\TelegramCustomerPurchasePaymentMethodSelection;

interface TelegramCustomerPurchasePaymentMethods
{
    public function discoverForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionKey,
    ): TelegramCustomerPurchasePaymentMethodsDecision;

    public function currentForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
    ): TelegramCustomerPurchasePaymentMethodsDecision;

    public function selectForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $quotePublicId,
        string $quoteConfigurationHash,
        string $decisionPublicId,
        string $decisionConfigurationHash,
        string $methodCode,
    ): TelegramCustomerPurchasePaymentMethodSelection;
}
