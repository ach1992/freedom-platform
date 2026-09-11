<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Stringable;
use WeakMap;

/**
 * Restricted URL button kept only in the transient protected provider payload.
 * Invite plaintext is held outside inspectable object properties and can be
 * revealed only by the exact protected Telegram provider gateway.
 */
final class ProtectedTelegramHttpsUrlButton implements Stringable
{
    /** @var WeakMap<self,string>|null */
    private static ?WeakMap $httpsUrlByInstance = null;

    public function __construct(
        public readonly string $text,
        #[SensitiveParameter] string $httpsUrl,
    ) {
        if ($text === ''
            || trim($text) === ''
            || mb_strlen($text) > 64
            || ! mb_check_encoding($text, 'UTF-8')
            || str_contains($text, "\0")
            || ! str_starts_with($httpsUrl, 'https://')
            || strlen($httpsUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $httpsUrl) === 1) {
            throw new InvalidArgumentException('Protected Telegram HTTPS URL button is invalid.');
        }

        self::httpsUrlByInstance()[$this] = $httpsUrl;
    }

    /**
     * @internal Restricted to HttpProtectedTelegramMessageSender by the runtime guard.
     */
    public function revealHttpsUrlForProvider(): string
    {
        TelegramProtectedPresentationProvenanceGuard::assertHttpsUrlRevealCaller();
        $map = self::$httpsUrlByInstance;
        if ($map === null || ! isset($map[$this])) {
            throw new LogicException('Protected Telegram HTTPS URL is unavailable.');
        }

        return $map[$this];
    }

    public function __toString(): string
    {
        return '[PROTECTED_TELEGRAM_HTTPS_URL_BUTTON]';
    }

    /** @return array{redacted:true,type:string,text:string} */
    public function __debugInfo(): array
    {
        return [
            'redacted' => true,
            'type' => 'https_url_button',
            'text' => $this->text,
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Protected Telegram HTTPS URL buttons cannot be serialized.');
    }

    /** @param array<array-key,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Protected Telegram HTTPS URL buttons cannot be unserialized.');
    }

    /** @return WeakMap<self,string> */
    private static function httpsUrlByInstance(): WeakMap
    {
        return self::$httpsUrlByInstance ??= new WeakMap;
    }

    private function __clone(): void {}
}
