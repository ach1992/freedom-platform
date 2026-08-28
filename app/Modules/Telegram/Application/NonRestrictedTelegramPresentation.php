<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use Stringable;

/**
 * Persistable Phase 0.7 presentation text only. RESTRICTED data such as
 * credentials, subscription/config URLs, OTPs, tokens, and raw provider
 * payloads must use their existing protected authority instead.
 *
 * New application construction is accepted only from the reviewed source
 * contract through NonRestrictedTelegramPresentationFactory. Persisted text is
 * restored only by the delivery executor after DB authority has already fenced
 * creation/replay.
 */
final readonly class NonRestrictedTelegramPresentation implements Stringable
{
    private function __construct(private string $text) {}

    /** @internal Use NonRestrictedTelegramPresentationFactory for new application data. */
    public static function fromReviewedSource(NonRestrictedTelegramPresentationSource $source): self
    {
        return self::validated($source->nonRestrictedTelegramText());
    }

    /** @internal Existing trusted durable operation data only. */
    public static function restorePersisted(string $text): self
    {
        return self::validated($text);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function __toString(): string
    {
        return '[NON_RESTRICTED_TELEGRAM_PRESENTATION]';
    }

    /** @return array{redacted: true, type: string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'plain_text'];
    }

    private static function validated(string $text): self
    {
        if ($text === '' || mb_strlen($text) > 4096 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram presentation text must contain 1-4096 safe characters.');
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Telegram presentation text must be valid UTF-8.');
        }

        return new self($text);
    }
}
