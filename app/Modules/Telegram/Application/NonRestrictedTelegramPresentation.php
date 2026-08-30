<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * Persistable Phase 0.7 presentation text only. RESTRICTED data such as
 * credentials, subscription/config URLs, OTPs, tokens, and raw provider
 * payloads must use their existing protected authority instead.
 *
 * This value object does not carry security authority. The actual generic queue
 * and provider transport boundaries independently authorize their engine-visible
 * source call paths through TelegramPresentationProvenanceGuard.
 */
final class NonRestrictedTelegramPresentation implements Stringable
{
    private function __construct(private readonly string $text) {}

    /** @internal Use NonRestrictedTelegramPresentationFactory for new application data. */
    public static function fromReviewedSource(NonRestrictedTelegramPresentationSource $source): self
    {
        TelegramPresentationProvenanceGuard::assertExactInternalCaller(
            NonRestrictedTelegramPresentationFactory::class,
            __DIR__.'/NonRestrictedTelegramPresentationFactory.php',
        );

        return self::validated($source->nonRestrictedTelegramText());
    }

    /** @internal Existing trusted durable operation data only. */
    public static function restorePersisted(string $text): self
    {
        TelegramPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramDeliveryOperationExecutor::class,
            __DIR__.'/TelegramDeliveryOperationExecutor.php',
        );

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

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Telegram presentation objects cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Telegram presentation objects cannot be unserialized.');
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
