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
        public ?int $sourceChatId = null,
        public ?int $sourceMessageId = null,
        public ?TelegramInlineKeyboardSnapshot $inlineKeyboard = null,
    ) {
        $commonInvalid = preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $targetAccountPublicId) !== 1
            || preg_match('/\A[1-9][0-9]{0,19}\z/', $targetTelegramUserId) !== 1
            || ! mb_check_encoding($text, 'UTF-8')
            || str_contains($text, "\0")
            || preg_match('/\A[A-Za-z0-9_.:-]{8,64}\z/', $correlationId) !== 1;

        if ($commonInvalid
            || ! in_array($contentType, ['text', 'photo', 'video', 'document', 'forward', 'copy'], true)) {
            throw new InvalidArgumentException('Telegram administrator direct-message draft is invalid.');
        }

        if ($contentType === 'text') {
            if (trim($text) === ''
                || mb_strlen($text) > 3500
                || $mediaPublicId !== null
                || $mediaDetectedMime !== null
                || $mediaByteSize !== null
                || $mediaContentSha256 !== null
                || $sourceChatId !== null
                || $sourceMessageId !== null) {
                throw new InvalidArgumentException('Telegram administrator direct-message draft is invalid.');
            }

            return;
        }

        if (in_array($contentType, ['forward', 'copy'], true)) {
            if ($text !== ''
                || $mediaPublicId !== null
                || $mediaDetectedMime !== null
                || $mediaByteSize !== null
                || $mediaContentSha256 !== null
                || $sourceChatId === null
                || $sourceChatId < 1
                || $sourceMessageId === null
                || $sourceMessageId < 1
                || ($contentType === 'forward' && $inlineKeyboard !== null)) {
                throw new InvalidArgumentException('Telegram administrator direct source-message draft is invalid.');
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
            || $sourceChatId !== null
            || $sourceMessageId !== null
            || ($contentType === 'photo' && ! TelegramPrivateMediaContentValidator::isImageMime($mediaDetectedMime))
            || ($contentType === 'video' && $mediaDetectedMime !== 'video/mp4')
            || ($contentType !== 'photo' && $text !== '')) {
            throw new InvalidArgumentException('Telegram administrator direct-message media draft is invalid.');
        }
    }

    public function isMedia(): bool
    {
        return in_array($this->contentType, ['photo', 'video', 'document'], true);
    }

    public function isSourceMessage(): bool
    {
        return in_array($this->contentType, ['forward', 'copy'], true);
    }

    public function supportsInlineKeyboard(): bool
    {
        return $this->contentType !== 'forward';
    }
}
