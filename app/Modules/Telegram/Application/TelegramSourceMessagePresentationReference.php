<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use LogicException;
use Stringable;

final readonly class TelegramSourceMessagePresentationReference implements Stringable
{
    private const PREFIX = '[TELEGRAM_SOURCE_MESSAGE_REFERENCE:v1:administrator_direct_message:';

    private function __construct(public string $directMessagePublicId) {}

    public static function administratorDirectMessage(string $directMessagePublicId): self
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $directMessagePublicId) !== 1) {
            throw new DomainException('Telegram source-message direct-message identity is invalid.');
        }

        return new self(strtoupper($directMessagePublicId));
    }

    public static function restore(string $value): self
    {
        if (preg_match(
            '/\A\[TELEGRAM_SOURCE_MESSAGE_REFERENCE:v1:administrator_direct_message:([0-9A-HJKMNP-TV-Z]{26})\]\z/',
            $value,
            $matches,
        ) !== 1) {
            throw new DomainException('Stored Telegram source-message reference is invalid.');
        }

        return self::administratorDirectMessage($matches[1]);
    }

    public function durableText(): string
    {
        return self::PREFIX.$this->directMessagePublicId.']';
    }

    public function __toString(): string
    {
        return '[TELEGRAM_SOURCE_MESSAGE_REFERENCE]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'source_message_reference'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Telegram source-message references cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Telegram source-message references cannot be unserialized.');
    }
}
