<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class WalletTransferPolicySnapshot
{
    public function __construct(
        public int $minimum,
        public int $maximum,
        public int $dailyLimit,
        public int $fixedFee,
        public int $feeBasisPoints,
        public int $confirmationTtlSeconds,
        public ?string $feeAccountCode,
    ) {}
}
