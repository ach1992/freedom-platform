<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

final readonly class AdministratorWalletCapabilities
{
    public function __construct(
        public bool $canRefund,
        public bool $canCorrect,
    ) {}

    public function available(): bool
    {
        return $this->canRefund || $this->canCorrect;
    }
}
