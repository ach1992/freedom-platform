<?php

declare(strict_types=1);

namespace App\Modules\Payments\Usdt\Application;

final class UsdtBep20Asset
{
    public const NETWORK = 'BEP20';
    public const CHAIN_ID = 56;
    public const TOKEN_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';
    public const TOKEN_DECIMALS = 18;

    private function __construct() {}
}
