<?php

declare(strict_types=1);

namespace App\Modules\Payments\NowPayments\Application\Contracts;

use RuntimeException;
use Throwable;

final class NowPaymentsTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $uncertain,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
