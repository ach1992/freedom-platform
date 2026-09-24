<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramAlternativePaymentReviewCase
{
    /** @param list<string> $candidateReservationPublicIds */
    public function __construct(
        public string $kind,
        public string $reviewPublicId,
        public string $subjectPublicId,
        public string $state,
        public string $providerCode,
        public ?string $reference,
        public ?int $amount,
        public ?string $currency,
        public bool $privateEvidenceAvailable,
        public int $candidateCount,
        public array $candidateReservationPublicIds,
        public ?int $minimumConfirmations,
        public string $createdAt,
    ) {}
}
