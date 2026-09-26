<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceAutoRenewPolicySnapshot
{
    public function __construct(
        public string $offeringCode,
        public ?string $mode,
        public ?int $absoluteIncreaseLimitIrr,
        public ?int $percentageIncreaseLimitBps,
        public ?int $version,
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{1,63}\z/', $offeringCode) !== 1
            || ($mode !== null && ! in_array($mode, ['stop', 'continue', 'within_limit'], true))
            || ($absoluteIncreaseLimitIrr !== null && $absoluteIncreaseLimitIrr < 0)
            || ($percentageIncreaseLimitBps !== null && ($percentageIncreaseLimitBps < 0 || $percentageIncreaseLimitBps > 1_000_000))
            || ($version !== null && $version < 1)) {
            throw new InvalidArgumentException('Telegram Service auto-renew policy snapshot is invalid.');
        }
    }
}
