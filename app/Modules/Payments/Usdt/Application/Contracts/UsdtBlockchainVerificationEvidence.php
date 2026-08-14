<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application\Contracts;

use DateTimeImmutable;

final readonly class UsdtBlockchainVerificationEvidence
{
    /** @param array<string,scalar|null> $safeEvidence */
    public function __construct(
        public string $outcome,
        public string $transactionStatus,
        public string $providerEventId,
        public string $txid,
        public ?string $network,
        public ?int $chainId,
        public ?string $tokenContract,
        public ?string $destinationAddress,
        public ?string $amountBaseUnits,
        public ?int $tokenDecimals,
        public ?int $confirmations,
        public ?int $blockNumber,
        public ?DateTimeImmutable $transactionAt,
        public DateTimeImmutable $observedAt,
        public string $evidenceHash,
        public array $safeEvidence = [],
    ) {}
}
