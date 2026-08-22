<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

final readonly class ServiceAutoRenewBatchReceipt
{
    public function __construct(
        public int $candidates,
        public int $attempted,
        public int $queued,
        public int $succeeded,
        public int $blocked,
        public int $insufficientWallet,
        public int $failed,
    ) {}

    public function requiresAttention(): bool
    {
        return $this->failed > 0;
    }
}
