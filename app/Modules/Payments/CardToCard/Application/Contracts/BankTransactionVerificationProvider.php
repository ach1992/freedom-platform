<?php

declare(strict_types=1);

namespace App\Modules\Payments\CardToCard\Application\Contracts;

interface BankTransactionVerificationProvider
{
    public function code(): string;

    public function fetch(?string $cursor): BankTransactionPage;
}
