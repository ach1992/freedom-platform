<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramServiceNotificationPreferenceSnapshot
{
    /** @param list<TelegramServiceNotificationPreferenceOption> $options */
    public function __construct(
        public ?string $servicePublicId,
        public array $options,
    ) {
        if ($servicePublicId !== null && preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $servicePublicId) !== 1) {
            throw new InvalidArgumentException('Telegram Service notification preference Service public ID is invalid.');
        }
        if ($options === []) {
            throw new InvalidArgumentException('Telegram Service notification preference options are empty.');
        }
    }

    public function option(string $type, string $threshold): ?TelegramServiceNotificationPreferenceOption
    {
        foreach ($this->options as $option) {
            if (hash_equals($option->notificationType, $type) && hash_equals($option->thresholdCode, $threshold)) {
                return $option;
            }
        }

        return null;
    }
}
