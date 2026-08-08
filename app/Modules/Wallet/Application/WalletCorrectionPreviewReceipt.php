<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;

final readonly class WalletCorrectionPreviewReceipt
{
    public function __construct(
        public int $previewId,
        public int $ownerUserId,
        public int $ledgerAccountId,
        public string $walletBucket,
        public WalletCorrectionDirection $direction,
        public IrrMoney $amount,
        public IrrMoney $ledgerBalance,
        public IrrMoney $activeHolds,
        public IrrMoney $availableBalance,
        public IrrMoney $resultingLedgerBalance,
        public IrrMoney $resultingAvailableBalance,
        public bool $approvalRequired,
        public string $confirmationToken,
        public bool $replayed,
    ) {}
}
