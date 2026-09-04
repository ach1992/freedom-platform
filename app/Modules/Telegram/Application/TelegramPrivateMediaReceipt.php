<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use Illuminate\Support\Str;

final readonly class TelegramPrivateMediaReceipt
{
    public function __construct(
        public string $publicId,
        public string $privateReference,
        public string $contentSha256,
        public string $detectedMime,
        public int $byteSize,
        public bool $replayed,
    ) {
        if (! Str::isUlid($publicId)
            || ! hash_equals('telegram-private-media:'.strtoupper($publicId), $privateReference)
            || preg_match('/\A[0-9a-f]{64}\z/', $contentSha256) !== 1
            || ! in_array($detectedMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $byteSize < 1) {
            throw new InvalidArgumentException('Telegram private-media receipt is invalid.');
        }
    }
}
