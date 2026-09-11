<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use LogicException;
use Stringable;

/**
 * Restricted URL button kept only in the transient protected provider payload.
 */
final readonly class ProtectedTelegramHttpsUrlButton implements Stringable
{
    public function __construct(
        public string $text,
        private string $httpsUrl,
    ) {
        if ($text === ''
            || mb_strlen($text) > 64
            || ! mb_check_encoding($text, 'UTF-8')
            || str_contains($text, "\0")
            || ! str_starts_with($httpsUrl, 'https://')
            || strlen($httpsUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $httpsUrl) === 1) {
            throw new InvalidArgumentException('Protected Telegram HTTPS URL button is invalid.');
        }
    }

    public function httpsUrl(): string
    {
        return $this->httpsUrl;
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
}
