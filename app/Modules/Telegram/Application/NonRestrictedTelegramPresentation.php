<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use stdClass;
use Stringable;

/**
 * Persistable Phase 0.7 presentation text only. RESTRICTED data such as
 * credentials, subscription/config URLs, OTPs, tokens, and raw provider
 * payloads must use their existing protected authority instead.
 *
 * New application construction is accepted only from the reviewed source
 * contract through NonRestrictedTelegramPresentationFactory. Persisted text is
 * restored only by the delivery executor after DB authority has already fenced
 * creation/replay. Each instance carries a process-local identity capability so
 * forged/reflected/unserialized objects fail again at the request boundary.
 */
final class NonRestrictedTelegramPresentation implements Stringable
{
    private static ?object $sourceCapability = null;

    private static ?object $restoreCapability = null;

    private function __construct(
        private readonly string $text,
        private readonly object $provenanceCapability,
    ) {}

    /** @internal Use NonRestrictedTelegramPresentationFactory for new application data. */
    public static function fromReviewedSource(NonRestrictedTelegramPresentationSource $source): self
    {
        self::assertExactCaller(
            NonRestrictedTelegramPresentationFactory::class,
            __DIR__.'/NonRestrictedTelegramPresentationFactory.php',
        );
        $capability = self::$sourceCapability ??= new stdClass;

        return self::validated($source->nonRestrictedTelegramText(), $capability);
    }

    /** @internal Existing trusted durable operation data only. */
    public static function restorePersisted(string $text): self
    {
        self::assertExactCaller(
            TelegramDeliveryOperationExecutor::class,
            __DIR__.'/TelegramDeliveryOperationExecutor.php',
        );
        $capability = self::$restoreCapability ??= new stdClass;

        return self::validated($text, $capability);
    }

    public function assertTrustedProvenance(): void
    {
        $sourceTrusted = self::$sourceCapability !== null
            && $this->provenanceCapability === self::$sourceCapability;
        $restoreTrusted = self::$restoreCapability !== null
            && $this->provenanceCapability === self::$restoreCapability;

        if (! $sourceTrusted && ! $restoreTrusted) {
            throw new LogicException('Telegram presentation provenance is not trusted.');
        }
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

    private static function validated(string $text, object $provenanceCapability): self
    {
        if ($text === '' || mb_strlen($text) > 4096 || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram presentation text must contain 1-4096 safe characters.');
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Telegram presentation text must be valid UTF-8.');
        }

        return new self($text, $provenanceCapability);
    }

    private static function assertExactCaller(string $expectedClass, string $expectedFile): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $directInvocation = $trace[1] ?? null;
        $gatewayFrame = $trace[2] ?? null;
        $callerClass = is_array($gatewayFrame) ? ($gatewayFrame['class'] ?? null) : null;
        $callerFile = is_array($directInvocation) ? ($directInvocation['file'] ?? null) : null;
        $resolvedExpected = realpath($expectedFile);
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;

        if ($callerClass !== $expectedClass
            || $resolvedExpected === false
            || $resolvedCaller === false
            || $resolvedCaller !== $resolvedExpected
        ) {
            throw new LogicException('Telegram presentation construction is restricted to its reviewed provenance gateway.');
        }
    }
}
