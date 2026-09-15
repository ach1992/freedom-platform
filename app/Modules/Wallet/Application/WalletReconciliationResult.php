<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\WalletReconciliationStatus;

final readonly class WalletReconciliationResult
{
    public function __construct(
        public int $snapshotId,
        public WalletReconciliationStatus $status,
        public WalletBalanceSnapshot $balance,
        public ?int $comparedSnapshotId,
        public string $sourceFingerprint,
    ) {}
}
