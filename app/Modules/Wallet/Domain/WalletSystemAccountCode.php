<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

final class WalletSystemAccountCode
{
    public const CORRECTION_OFFSET = 'system.wallet.correction.offset';

    public const EXTERNAL_TOP_UP_CLEARING = 'system.payment.wallet-topup.clearing';

    private function __construct() {}
}
