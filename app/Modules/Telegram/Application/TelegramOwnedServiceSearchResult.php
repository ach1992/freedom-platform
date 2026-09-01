<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramOwnedServiceSearchResult
{
    public const MATCHED = 'matched';

    public const NOT_FOUND = 'not_found';

    public const AMBIGUOUS = 'ambiguous';

    public function __construct(
        public string $status,
        public ?string $selectionToken,
    ) {
        if (! in_array($status, [self::MATCHED, self::NOT_FOUND, self::AMBIGUOUS], true)) {
            throw new InvalidArgumentException('Telegram owned Service search status is invalid.');
        }
        if ($status === self::MATCHED) {
            if (! is_string($selectionToken) || preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
                throw new InvalidArgumentException('Telegram owned Service matched search requires an opaque selection token.');
            }

            return;
        }
        if ($selectionToken !== null) {
            throw new InvalidArgumentException('Telegram owned Service non-match search must not carry a selection token.');
        }
    }

    public static function matched(string $selectionToken): self
    {
        return new self(self::MATCHED, $selectionToken);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null);
    }

    public static function ambiguous(): self
    {
        return new self(self::AMBIGUOUS, null);
    }
}
