<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

final readonly class CardToCardMatchReceipt
{
    public function __construct(
        public int $bankTransactionId,
        public string $bankTransactionPublicId,
        public ?int $matchId,
        public ?string $matchPublicId,
        public ?int $reservationId,
        public ?string $reservationPublicId,
        public ?int $reviewId,
        public ?string $reviewPublicId,
        public string $outcome,
        public int $candidateCount,
        public bool $replayed,
    ) {}
}
