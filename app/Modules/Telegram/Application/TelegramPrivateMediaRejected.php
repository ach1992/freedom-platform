<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use InvalidArgumentException;

final class TelegramPrivateMediaRejected extends DomainException
{
    public function __construct(public readonly string $reasonCode)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Telegram private-media rejection code is invalid.');
        }

        parent::__construct('Telegram private media was rejected.');
    }
}
