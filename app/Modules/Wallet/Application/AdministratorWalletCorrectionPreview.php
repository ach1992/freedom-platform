<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use DomainException;

final readonly class AdministratorWalletCorrectionPreview
{
    public function __construct(
        public int $previewId,
        public WalletCorrectionDirection $direction,
        public int $amountIrr,
        public int $ledgerBalanceBeforeIrr,
        public int $availableBalanceBeforeIrr,
        public int $ledgerBalanceAfterIrr,
        public int $availableBalanceAfterIrr,
        public bool $approvalRequired,
        public string $confirmationToken,
        public ?string $approvalId,
        public bool $replayed,
    ) {
        if ($previewId < 1
            || $amountIrr < 1
            || preg_match('/\A[0-9a-f]{64}\z/', $confirmationToken) !== 1
            || ($approvalRequired && ($approvalId === null || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $approvalId) !== 1))
            || (! $approvalRequired && $approvalId !== null)) {
            throw new DomainException('Administrator wallet correction preview is invalid.');
        }
    }
}
