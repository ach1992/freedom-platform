<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewCase;
use App\Modules\Telegram\Application\TelegramAlternativePaymentReviewEvidence;

interface TelegramAlternativePaymentReview
{
    public const PERMISSION = 'access.sensitive_actions.approve';

    public function availableFor(int $actorUserId): bool;

    /** @return list<TelegramAlternativePaymentReviewCase> */
    public function pending(int $actorUserId, int $limit = 15): array;

    public function find(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
    ): TelegramAlternativePaymentReviewCase;

    public function privateEvidence(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
    ): TelegramAlternativePaymentReviewEvidence;

    public function approveC2c(
        int $actorUserId,
        string $reviewPublicId,
        string $reservationPublicId,
        string $reason,
        string $requestKey,
    ): void;

    public function approveGiftCard(
        int $actorUserId,
        string $reviewPublicId,
        string $externalRedemptionId,
        string $reason,
        string $requestKey,
    ): void;

    public function approveUsdt(
        int $actorUserId,
        string $reviewPublicId,
        int $confirmations,
        string $transactionAt,
        string $reason,
        string $requestKey,
    ): void;

    public function reject(
        int $actorUserId,
        string $kind,
        string $reviewPublicId,
        string $reason,
        string $requestKey,
    ): void;
}
