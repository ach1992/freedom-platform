<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use RuntimeException;

final class PhoneVerificationMethodNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The selected phone verification method is not allowed by the active policy.');
    }
}
