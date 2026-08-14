<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class CardToCardReviewDecisionService
{
    public function __construct(
        private DatabaseManager $database,
        private CardToCardMatchingService $matching,
        private CardToCardSettlementService $settlements,
    ) {}

    public function approve(
        string $reviewPublicId,
        string $reservationPublicId,
        int $administratorId,
        string $reason,
        string $correlationId,
    ): CardToCardSettlementReceipt {
        return $this->database->connection()->transaction(function (Connection $connection) use (
            $reviewPublicId,
            $reservationPublicId,
            $administratorId,
            $reason,
            $correlationId,
        ): CardToCardSettlementReceipt {
            unset($connection);
            $match = $this->matching->acceptReview(
                $reviewPublicId,
                $reservationPublicId,
                $administratorId,
                $reason,
                $correlationId,
            );
            if ($match->matchPublicId === null) {
                throw new RuntimeException('Accepted C2C review did not produce a transaction match.');
            }

            return $this->settlements->capture($match->matchPublicId, $correlationId);
        }, 3);
    }

    public function reject(
        string $reviewPublicId,
        int $administratorId,
        string $reason,
    ): CardToCardMatchReceipt {
        return $this->matching->rejectReview($reviewPublicId, $administratorId, $reason);
    }
}
