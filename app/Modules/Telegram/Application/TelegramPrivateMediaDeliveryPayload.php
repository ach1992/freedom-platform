<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;
use SensitiveParameter;
use Stringable;

final readonly class TelegramPrivateMediaDeliveryPayload implements Stringable
{
    public function __construct(
        #[SensitiveParameter]
        private string $contents,
        public string $detectedMime,
        public int $byteSize,
    ) {
        if ($byteSize < 1 || strlen($contents) !== $byteSize) {
            throw new LogicException('Telegram private-media delivery payload size is invalid.');
        }
    }

    public function bytes(): string
    {
        return $this->contents;
    }

    public function __toString(): string
    {
        return '[RESTRICTED_TELEGRAM_PRIVATE_MEDIA]';
    }

    /** @return array<string,string|int> */
    public function __debugInfo(): array
    {
        return [
            'contents' => '[REDACTED]',
            'detected_mime' => $this->detectedMime,
            'byte_size' => $this->byteSize,
        ];
    }

    /** @return array<never,never> */
    public function __serialize(): array
    {
        throw new LogicException('Telegram private-media delivery payload must not be serialized.');
    }
}
