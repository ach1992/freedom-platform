<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Stringable;

final readonly class TelegramResolvedSourceMessagePresentation implements JsonSerializable, Stringable
{
    public function __construct(
        public TelegramSourceMessageMode $mode,
        public int $sourceChatId,
        public int $sourceMessageId,
    ) {
        if ($sourceChatId < 1 || $sourceMessageId < 1) {
            throw new InvalidArgumentException('Resolved Telegram source-message presentation is invalid.');
        }
    }

    public function __toString(): string
    {
        return '[RESOLVED_TELEGRAM_SOURCE_MESSAGE_PRESENTATION]';
    }

    /** @return array{redacted:true,type:string,mode:string} */
    public function __debugInfo(): array
    {
        return $this->redactedMetadata();
    }

    /** @return array{redacted:true,type:string,mode:string} */
    public function jsonSerialize(): array
    {
        return $this->redactedMetadata();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Resolved Telegram source-message presentations cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved Telegram source-message presentations cannot be unserialized.');
    }

    /** @return array{redacted:true,type:string,mode:string} */
    private function redactedMetadata(): array
    {
        return [
            'redacted' => true,
            'type' => 'source_message',
            'mode' => $this->mode->value,
        ];
    }
}
