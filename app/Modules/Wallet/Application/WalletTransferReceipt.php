<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletTransferStatus;

final readonly class WalletTransferReceipt
{
    public function __construct(
        public int $transferId,
        public WalletTransferStatus $status,
        public int $recipientUserId,
        public string $recipientPublicId,
        public string $walletBucket,
        public IrrMoney $amount,
        public IrrMoney $fee,
        public IrrMoney $totalDebit,
        public int $walletHoldId,
        public ?int $ledgerTransactionId,
        public bool $replayed,
    ) {}
}
