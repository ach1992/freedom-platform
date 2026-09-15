<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use Throwable;

final class TelegramManagedUsdtRateInputRejected extends DomainException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Telegram managed USDT rate input was rejected.', 0, $previous);
    }
}
