<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Shared\Application\RestrictedValue;
use InvalidArgumentException;

final readonly class TelegramPrivateMediaInput
{
    public function __construct(
        public string $sourceKind,
        public RestrictedValue $fileId,
        public RestrictedValue $fileUniqueId,
        public ?int $reportedFileSize,
    ) {
        if (! in_array($sourceKind, ['photo', 'document'], true)) {
            throw new InvalidArgumentException('Telegram private-media source kind is invalid.');
        }
        if ($reportedFileSize !== null && $reportedFileSize < 1) {
            throw new InvalidArgumentException('Telegram private-media reported size is invalid.');
        }
    }
}
