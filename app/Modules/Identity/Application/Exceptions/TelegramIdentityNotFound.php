<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class TelegramIdentityNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Telegram sender is not bound to the requested customer account.');
    }
}
