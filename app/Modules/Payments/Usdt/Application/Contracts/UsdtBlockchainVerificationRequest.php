<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application\Contracts;

final readonly class UsdtBlockchainVerificationRequest
{
    public function __construct(
        public string $txid,
        public string $network,
        public int $chainId,
        public string $tokenContract,
        public string $destinationAddress,
        public string $expectedAmountBaseUnits,
        public int $tokenDecimals,
        public int $minimumConfirmations,
    ) {}
}
