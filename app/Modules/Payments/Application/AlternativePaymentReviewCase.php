<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

final readonly class AlternativePaymentReviewCase
{
    /**
     * @param list<string> $candidateReservationPublicIds
     */
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
