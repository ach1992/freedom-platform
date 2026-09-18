<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramAdministratorDirectMessageDraft
{
    public function __construct(
        public string $publicId,
        public string $targetAccountPublicId,
        public string $targetTelegramUserId,
        public string $text,
        public string $correlationId,
        public bool $replayed,
    ) {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $targetAccountPublicId) !== 1
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $targetTelegramUserId) !== 1
            || trim($text) === ''
            || mb_strlen($text) > 4096
            || ! mb_check_encoding($text, 'UTF-8')
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1) {
            throw new InvalidArgumentException('Telegram administrator direct-message draft is invalid.');
        }
    }
}
