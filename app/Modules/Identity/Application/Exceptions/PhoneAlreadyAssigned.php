<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;
use Throwable;

final class PhoneAlreadyAssigned extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The mobile number is already assigned to another active account.', 0, $previous);
    }
}
