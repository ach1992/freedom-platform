<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramSupportCustomerRateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public ?int $retryAfterSeconds,
    ) {
        if (($allowed && $retryAfterSeconds !== null)
            || (! $allowed && ($retryAfterSeconds === null || $retryAfterSeconds < 1 || $retryAfterSeconds > 86_400))) {
            throw new InvalidArgumentException('Telegram Support rate-limit decision is invalid.');
        }
    }

    public static function allowed(): self
    {
        return new self(true, null);
    }

    public static function limited(int $retryAfterSeconds): self
    {
        return new self(false, $retryAfterSeconds);
    }
}
