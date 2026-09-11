<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramRequiredChannelDefinition
{
    public string $channelKey;

    public string $displayTitle;

    public string $joinUrl;

    public function __construct(
        string $channelKey,
        public int $telegramChatId,
        public string $chatType,
        public string $visibility,
        string $displayTitle,
        string $joinUrl,
        public int $sortOrder,
    ) {
        $key = strtolower(trim($channelKey));
        if (preg_match('/\A[a-z][a-z0-9_.-]{2,63}\z/', $key) !== 1) {
            throw new InvalidArgumentException('Telegram required-channel key is invalid.');
        }
        if ($telegramChatId >= 0) {
            throw new InvalidArgumentException('Telegram required-channel chat ID must be negative.');
        }
        if (! in_array($chatType, ['group', 'supergroup', 'channel'], true)) {
            throw new InvalidArgumentException('Telegram required-channel chat type is invalid.');
        }
        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException('Telegram required-channel visibility is invalid.');
        }

        $title = trim($displayTitle);
        if ($title === '' || mb_strlen($title) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $title) === 1) {
            throw new InvalidArgumentException('Telegram required-channel display title is invalid.');
        }
        if ($sortOrder < 0 || $sortOrder > 1_000_000) {
            throw new InvalidArgumentException('Telegram required-channel sort order is invalid.');
        }

        $this->channelKey = $key;
        $this->displayTitle = $title;
        $this->joinUrl = self::normalizeJoinUrl($joinUrl, $visibility);
    }

    public static function normalizeJoinUrl(string $joinUrl, string $visibility): string
    {
        $value = trim($joinUrl);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Telegram required-channel join URL is invalid.');
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || ! is_string($parts['scheme'] ?? null)
            || strtolower($parts['scheme']) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Telegram required-channel join URL is invalid.');
        }

        $host = strtolower($parts['host']);
        if (! in_array($host, ['t.me', 'telegram.me'], true)) {
            throw new InvalidArgumentException('Telegram required-channel join URL host is invalid.');
        }

        $path = $parts['path'] ?? '';
        if (! is_string($path)) {
            throw new InvalidArgumentException('Telegram required-channel join URL path is invalid.');
        }

        $public = preg_match('/\A\/[A-Za-z][A-Za-z0-9_]{4,31}\z/', $path) === 1;
        $private = preg_match('/\A\/(?:\+[A-Za-z0-9_-]{5,128}|joinchat\/[A-Za-z0-9_-]{5,128})\z/', $path) === 1;
        if (($visibility === 'public' && ! $public) || ($visibility === 'private' && ! $private)) {
            throw new InvalidArgumentException('Telegram required-channel join URL does not match visibility.');
        }

        return 'https://'.$host.$path;
    }
}
