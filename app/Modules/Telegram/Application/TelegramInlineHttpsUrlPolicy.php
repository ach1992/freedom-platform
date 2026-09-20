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
            || isset($parts['port'])) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is invalid.');
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path)) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is invalid.');
        }

        match ($purpose) {
            TelegramInlineHttpsUrlPurpose::ZarinpalStartPay => self::assertZarinpalStartPay($url, $parts['host'], $path),
            TelegramInlineHttpsUrlPurpose::SupportContact => self::assertSupportContact($url, $parts['host'], $path),
            TelegramInlineHttpsUrlPurpose::ClientGuideResource => self::assertClientGuideResource($url, $parts['host']),
        };
    }

    private static function assertClientGuideResource(string $url, string $host): void
    {
        if (strtolower($host) !== $host
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || ! str_contains($host, '.')
            || str_contains($url, '\\')) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is not allowed for its purpose.');
        }
    }

    private static function assertZarinpalStartPay(string $url, string $host, string $path): void
    {
        if ($host !== 'payment.zarinpal.com'
            || preg_match('/\A\/pg\/StartPay\/A[A-Za-z0-9]{20,63}\z/', $path) !== 1
            || ! hash_equals('https://payment.zarinpal.com'.$path, $url)) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is not allowed for its purpose.');
        }
    }

    private static function assertSupportContact(string $url, string $host, string $path): void
    {
        if ($host !== 't.me'
            || preg_match('/\A\/[A-Za-z0-9_]{5,32}\z/', $path) !== 1
            || ! hash_equals('https://t.me'.$path, $url)) {
            throw new InvalidArgumentException('Telegram inline HTTPS URL is not allowed for its purpose.');
        }
    }
}
