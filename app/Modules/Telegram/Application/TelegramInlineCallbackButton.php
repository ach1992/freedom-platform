<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramInlineCallbackButton
{
    public const MAXIMUM_TEXT_CHARACTERS = 64;

    public function __construct(
        public string $text,
        public string $callbackPublicId,
        public ?TelegramInlineButtonStyle $style = null,
        public ?string $iconCustomEmojiId = null,
    ) {
        if ($text === '' || trim($text) === '' || mb_strlen($text) > self::MAXIMUM_TEXT_CHARACTERS) {
            throw new InvalidArgumentException('Telegram inline button text must contain 1-64 visible characters.');
        }
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram inline button text must be safe UTF-8.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $callbackPublicId) !== 1) {
            throw new InvalidArgumentException('Telegram inline callback identity is invalid.');
        }
        if ($iconCustomEmojiId !== null && preg_match('/\A[0-9]{1,64}\z/', $iconCustomEmojiId) !== 1) {
            throw new InvalidArgumentException('Telegram inline button custom emoji ID is invalid.');
        }
    }

    /** @return array{text:string,callback_public_id:string,style:?string,icon_custom_emoji_id?:string} */
    public function snapshot(): array
    {
        $snapshot = [
            'text' => $this->text,
            'callback_public_id' => $this->callbackPublicId,
            'style' => $this->style?->value,
        ];
        if ($this->iconCustomEmojiId !== null) {
            $snapshot['icon_custom_emoji_id'] = $this->iconCustomEmojiId;
        }

        return $snapshot;
    }
}
