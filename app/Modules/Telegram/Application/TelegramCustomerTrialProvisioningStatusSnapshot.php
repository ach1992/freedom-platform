<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialProvisioningStatusSnapshot
{
    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED_FINAL = 'failed_final';

    public const NEEDS_REVIEW = 'needs_review';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(public string $status)
    {
        if (! in_array($status, [
            self::PENDING,
            self::SUCCEEDED,
            self::FAILED_FINAL,
            self::NEEDS_REVIEW,
            self::UNAVAILABLE,
        ], true)) {
            throw new InvalidArgumentException('Telegram Trial provisioning status is invalid.');
        }
    }
}
