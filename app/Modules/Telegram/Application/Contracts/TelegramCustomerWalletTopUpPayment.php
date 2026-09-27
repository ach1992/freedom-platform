<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerWalletTopUpRedirect;

interface TelegramCustomerWalletTopUpPayment
{
    public function prepareForSelf(
        int $actorUserId,
        int $subjectUserId,
        int $amountIrr,
        string $operationKey,
    ): TelegramCustomerWalletTopUpRedirect;
}
