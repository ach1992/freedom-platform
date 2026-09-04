<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use LogicException;
use Stringable;

final readonly class TelegramProtectedPresentationReference implements Stringable
{
    private const PREFIX = '[PROTECTED_TELEGRAM_REFERENCE:v1:';

    private const PURPOSE_CARD_TO_CARD_DESTINATION = 'card_to_card_destination';

    private function __construct(
        public string $purpose,
        public string $publicId,
        public string $locale,
    ) {}

    public static function cardToCardDestination(string $reservationPublicId, string $locale): self
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $reservationPublicId) !== 1) {
            throw new DomainException('Protected Telegram card-to-card reference identity is invalid.');
        }
        if (! in_array($locale, ['fa', 'en'], true)) {
            throw new DomainException('Protected Telegram reference locale is invalid.');
        }

        return new self(self::PURPOSE_CARD_TO_CARD_DESTINATION, strtoupper($reservationPublicId), $locale);
    }

    public static function restore(string $value): self
    {
        if (preg_match(
            '/\A\[PROTECTED_TELEGRAM_REFERENCE:v1:([a-z0-9_]+):([0-9A-HJKMNP-TV-Z]{26}):(fa|en)\]\z/',
            $value,
            $matches,
        ) !== 1) {
            throw new DomainException('Stored protected Telegram reference is invalid.');
        }
        if ($matches[1] !== self::PURPOSE_CARD_TO_CARD_DESTINATION) {
            throw new DomainException('Stored protected Telegram reference purpose is unsupported.');
        }

        return new self($matches[1], $matches[2], $matches[3]);
    }

    public function isCardToCardDestination(): bool
    {
        return $this->purpose === self::PURPOSE_CARD_TO_CARD_DESTINATION;
    }

    public function durableText(): string
    {
        return self::PREFIX.$this->purpose.':'.$this->publicId.':'.$this->locale.']';
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_REFERENCE]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'protected_reference'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Protected Telegram references cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Protected Telegram references cannot be unserialized.');
    }
}
