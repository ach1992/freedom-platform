<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Domain;

final class WalletSystemAccountCode
{
    public const CORRECTION_OFFSET = 'system.wallet.correction.offset';

    public const EXTERNAL_TOP_UP_CLEARING = 'system.payment.wallet-topup.clearing';

    public const PURCHASE_CLEARING = 'system.payment.wallet-purchase.clearing';

    public const REFERRAL_REWARD_EXPENSE = 'system.referral.reward.expense';

    private function __construct() {}
}
