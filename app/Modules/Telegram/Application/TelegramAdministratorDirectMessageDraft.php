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
        public string $contentType = 'text',
        public ?string $mediaPublicId = null,
        public ?string $mediaDetectedMime = null,
        public ?int $mediaByteSize = null,
        public ?string $mediaContentSha256 = null,
    ) {
        $commonInvalid = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $targetAccountPublicId) !== 1
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $targetTelegramUserId) !== 1
            || ! mb_check_encoding($text, 'UTF-8')
            || str_contains($text, "\0")
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1;

        if ($commonInvalid || ! in_array($contentType, ['text', 'photo', 'video', 'document'], true)) {
            throw new InvalidArgumentException('Telegram administrator direct-message draft is invalid.');
        }

        if ($contentType === 'text') {
            if (trim($text) === ''
                || mb_strlen($text) > 3500
                || $mediaPublicId !== null
                || $mediaDetectedMime !== null
                || $mediaByteSize !== null
                || $mediaContentSha256 !== null) {
                throw new InvalidArgumentException('Telegram administrator direct-message draft is invalid.');
            }

            return;
        }

        if (mb_strlen($text) > 1024
            || $mediaPublicId === null
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $mediaPublicId) !== 1
            || $mediaDetectedMime === null
            || ! TelegramPrivateMediaContentValidator::isApprovedMime($mediaDetectedMime)
            || $mediaByteSize === null
            || $mediaByteSize < 1
            || $mediaByteSize > 20_000_000
            || $mediaContentSha256 === null
            || preg_match('/\A[0-9a-f]{64}\z/', $mediaContentSha256) !== 1
            || ($contentType === 'photo' && ! TelegramPrivateMediaContentValidator::isImageMime($mediaDetectedMime))
            || ($contentType === 'video' && $mediaDetectedMime !== 'video/mp4')
            || ($contentType !== 'photo' && $text !== '')) {
            throw new InvalidArgumentException('Telegram administrator direct-message media draft is invalid.');
        }
    }

    public function isMedia(): bool
    {
        return $this->contentType !== 'text';
    }
}
