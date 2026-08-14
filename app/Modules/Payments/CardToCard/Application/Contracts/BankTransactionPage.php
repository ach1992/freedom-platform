<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application\Contracts;

final readonly class BankTransactionPage
{
    /** @param list<BankTransactionObservation> $transactions */
    public function __construct(
        public array $transactions,
        public ?string $nextCursor,
    ) {}
}
