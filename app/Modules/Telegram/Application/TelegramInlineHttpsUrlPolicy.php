<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramInlineHttpsUrlPolicy
{
    private const MAXIMUM_URL_BYTES = 512;

    public static function assertAllowed(string $url, TelegramInlineHttpsUrlPurpose $purpose): void
    {
        if ($url === ''
            || strlen($url) > self::MAXIMUM_URL_BYTES
            || trim($url) !== $url
            || preg_match('/[^\x21-\x7E]/', $url) === 1) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is invalid.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is invalid.');
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path)) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is invalid.');
        }

        match ($purpose) {
            TelegramInlineHttpsUrlPurpose::ZarinpalStartPay => self::assertZarinpalStartPay($url, $parts['host'], $path),
        };
    }

    private static function assertZarinpalStartPay(string $url, string $host, string $path): void
    {
        if ($host !== 'payment.zarinpal.com'
            || preg_match('/\A\/pg\/StartPay\/A[A-Za-z0-9]{20,63}\z/', $path) !== 1
            || ! hash_equals('https://payment.zarinpal.com'.$path, $url)) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is not allowed for its purpose.');
        }
    }
}
