<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramCustomerPurchaseCardToCardSubmission;
use DateTimeImmutable;

interface TelegramCustomerPurchaseCardToCardReceiptSubmission
{
    public function submitReceiptForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $reservationPublicId,
        DateTimeImmutable $submittedAt,
        string $evidenceHash,
        string $privateReceiptReference,
        string $operationKey,
    ): TelegramCustomerPurchaseCardToCardSubmission;
}
