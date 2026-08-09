<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

enum PaymentMethodKind: string
{
    case Wallet = 'wallet';
    case CardToCard = 'card_to_card';
    case GiftCard = 'gift_card';
    case Crypto = 'crypto';
    case Gateway = 'gateway';
}
