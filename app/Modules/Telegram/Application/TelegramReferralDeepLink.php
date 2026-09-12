<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure builder for the public Telegram referral entry link. Referral identity,
 * attribution and reward authority remain owned by the Promotions module.
 */
final readonly class TelegramReferralDeepLink
{
    private function __construct(private ?string $botUsername) {}

    public static function fromConfiguration(mixed $botUsername): self
    {
        if ($botUsername === null || $botUsername === '') {
            return new self(null);
        }
        if (! is_string($botUsername)
            || trim($botUsername) !== $botUsername
            || preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $botUsername) !== 1) {
            throw new RuntimeException('Telegram bot username configuration is invalid.');
        }

        return new self($botUsername);
    }

    public function forReferralToken(string $referralToken): ?string
    {
        if (preg_match('/\A[0-9a-f]{32}\z/', $referralToken) !== 1) {
            throw new InvalidArgumentException('Telegram referral token is invalid.');
        }
        if ($this->botUsername === null) {
            return null;
        }

        return 'https://t.me/'.$this->botUsername.'?start='.$referralToken;
    }
}
