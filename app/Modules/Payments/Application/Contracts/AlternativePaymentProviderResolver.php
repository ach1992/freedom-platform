<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Contracts;

use App\Modules\Payments\CardToCard\Application\Contracts\BankTransactionVerificationProvider;
use App\Modules\Payments\GiftCard\Application\Contracts\GiftCardVerificationProvider;
use App\Modules\Payments\Usdt\Application\Contracts\BlockchainTransactionVerificationProvider;

interface AlternativePaymentProviderResolver
{
    public function bank(string $providerCode): ?BankTransactionVerificationProvider;

    public function giftCard(string $providerCode): ?GiftCardVerificationProvider;

    public function blockchain(string $providerCode): ?BlockchainTransactionVerificationProvider;
}
