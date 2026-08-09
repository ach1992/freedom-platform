<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Domain;

enum BenefitCodeType: string
{
    case WalletCredit = 'wallet_credit';
    case FreeService = 'free_service';
    case DiscountGrant = 'discount_grant';
}
