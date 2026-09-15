<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;

final readonly class WalletCorrectionReceipt
{
    public function __construct(
        public int $correctionId,
        public int $previewId,
        public int $ownerUserId,
        public int $ledgerAccountId,
        public string $walletBucket,
        public WalletCorrectionDirection $direction,
        public IrrMoney $amount,
        public IrrMoney $ledgerBalanceBefore,
        public IrrMoney $activeHolds,
        public IrrMoney $availableBalanceBefore,
        public IrrMoney $ledgerBalanceAfter,
        public IrrMoney $availableBalanceAfter,
        public ?string $approvalId,
        public int $ledgerTransactionId,
        public bool $replayed,
    ) {}
}
