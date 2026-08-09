<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

final readonly class UsdtDestinationWalletReceipt
{
    public function __construct(
        public int $versionId,
        public string $walletCode,
        public int $version,
        public string $network,
        public string $address,
        public bool $enabled,
        public string $configurationSnapshotHash,
        public bool $replayed,
    ) {}
}
