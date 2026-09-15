<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletHoldStatus;

final readonly class WalletHoldReceipt
{
    public function __construct(
        public int $holdId,
        public WalletHoldStatus $status,
        public IrrMoney $amount,
        public ?int $capturedLedgerTransactionId,
        public bool $replayed,
    ) {}
}
