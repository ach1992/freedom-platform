<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use InvalidArgumentException;

final readonly class SmsDispatchResult
{
    /** @param non-empty-list<SmsDeliveryAttempt> $attempts */
    public function __construct(public array $attempts)
    {
        if ($attempts === []) {
            throw new InvalidArgumentException('At least one SMS delivery attempt is required.');
        }
    }

    public function finalAttempt(): SmsDeliveryAttempt
    {
        return $this->attempts[array_key_last($this->attempts)];
    }
}
