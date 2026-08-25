<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use Stringable;

/**
 * Persistable Phase 0.7 presentation text only. RESTRICTED data such as
 * credentials, subscription/config URLs, OTPs, tokens, and raw provider
 * payloads must use their existing protected authority instead.
 */
final readonly class NonRestrictedTelegramPresentation implements Stringable
{
    private function __construct(private string $text) {}

    public static function plainText(string $text): self
    {
        if ($text === '' || mb_strlen($text) > 4096 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram presentation text must contain 1-4096 safe characters.');
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Telegram presentation text must be valid UTF-8.');
        }

        return new self($text);
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
}
