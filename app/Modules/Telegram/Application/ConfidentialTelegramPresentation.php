<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Stringable;
use WeakMap;

/**
 * Transient CONFIDENTIAL Telegram text. Plaintext may be persisted only through
 * TelegramDeliveryConfidentialPresentationService encryption and must never be
 * adapted into NonRestrictedTelegramPresentation.
 */
final class ConfidentialTelegramPresentation implements Stringable
{
    /** @var WeakMap<self,string>|null */
    private static ?WeakMap $plaintextByInstance = null;

    private function __construct(#[SensitiveParameter] string $text)
    {
        self::plaintextByInstance()[$this] = $text;
    }

    /** @internal Use ConfidentialTelegramPresentationFactory for new application data. */
    public static function fromReviewedConfidentialSource(
        #[SensitiveParameter] ConfidentialTelegramPresentationSource $source,
    ): self {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            ConfidentialTelegramPresentationFactory::class,
            __DIR__.'/ConfidentialTelegramPresentationFactory.php',
        );

        return self::validated($source->confidentialTelegramText());
    }

    /** @internal Decrypted durable data restored only by the confidential companion service. */
    public static function restoreDecrypted(#[SensitiveParameter] string $text): self
    {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramDeliveryConfidentialPresentationService::class,
            __DIR__.'/TelegramDeliveryConfidentialPresentationService.php',
        );

        return self::validated($text);
    }

    /**
     * @internal Keyed semantic/integrity hashing is restricted to the reviewed hasher.
     */
    public function keyedHash(string $context, #[SensitiveParameter] string $keyMaterial): string
    {
        TelegramConfidentialPresentationProvenanceGuard::assertExactInternalCaller(
            TelegramConfidentialPresentationHasher::class,
            __DIR__.'/TelegramConfidentialPresentationHasher.php',
        );
        if ($context === '' || $keyMaterial === '') {
            throw new LogicException('Confidential Telegram presentation hashing context is invalid.');
        }

        $derivedKey = hash_hmac('sha256', $context, $keyMaterial, true);

        return hash_hmac('sha256', $this->plaintext(), $derivedKey);
    }

    /**
     * Explicit reveal for the Telegram-owned encrypted persistence/provider path.
     * Callers must not place this value in routine logs, Outbox payloads or the
     * non-restricted durable operation presentation column.
     */
    public function revealConfidentialText(): string
    {
        TelegramConfidentialPresentationProvenanceGuard::assertConfidentialPlaintextRevealCaller();

        return $this->plaintext();

    }

    public function __toString(): string
    {
        return '[CONFIDENTIAL_TELEGRAM_PRESENTATION]';
    }

    /** @return array{redacted:true,type:string} */
    public function __debugInfo(): array
    {
        return ['redacted' => true, 'type' => 'confidential_text'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Confidential Telegram presentation objects cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Confidential Telegram presentation objects cannot be unserialized.');
    }

    private static function validated(#[SensitiveParameter] string $text): self
    {
        if ($text === '' || mb_strlen($text) > 4096 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Confidential Telegram presentation text must contain 1-4096 safe characters.');
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Confidential Telegram presentation text must be valid UTF-8.');
        }

        return new self($text);
    }

    private function plaintext(): string
    {
        $map = self::$plaintextByInstance;
        if ($map === null || ! isset($map[$this])) {
            throw new LogicException('Confidential Telegram presentation plaintext is unavailable.');
        }

        return $map[$this];
    }

    /** @return WeakMap<self,string> */
    private static function plaintextByInstance(): WeakMap
    {
        return self::$plaintextByInstance ??= new WeakMap;
    }

    private function __clone(): void {}
}
