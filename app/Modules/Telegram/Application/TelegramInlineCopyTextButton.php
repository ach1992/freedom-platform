<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramInlineCopyTextButton
{
    private const MAXIMUM_TEXT_CHARACTERS = 64;

    private const MAXIMUM_COPY_TEXT_CHARACTERS = 256;

    public function __construct(
        public string $text,
        public string $copyText,
        public ?TelegramInlineButtonStyle $style = null,
        public ?string $iconCustomEmojiId = null,
    ) {
        if ($text === '' || trim($text) === '' || mb_strlen($text) > self::MAXIMUM_TEXT_CHARACTERS) {
            throw new InvalidArgumentException('Telegram inline button text must contain 1-64 visible characters.');
        }
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram inline button text must be safe UTF-8.');
        }
        if ($copyText === ''
            || mb_strlen($copyText) > self::MAXIMUM_COPY_TEXT_CHARACTERS
            || ! mb_check_encoding($copyText, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $copyText) === 1) {
            throw new InvalidArgumentException('Telegram inline copy text must contain 1-256 safe characters.');
        }
        if ($iconCustomEmojiId !== null && preg_match('/\A[0-9]{1,64}\z/', $iconCustomEmojiId) !== 1) {
            throw new InvalidArgumentException('Telegram inline button custom emoji ID is invalid.');
        }
    }

    /** @return array{text:string,copy_text:string,style:?string,icon_custom_emoji_id:?string} */
    public function snapshot(): array
    {
        $snapshot = [
            'text' => $this->text,
            'copy_text' => $this->copyText,
            'style' => $this->style?->value,
        ];
        if ($this->iconCustomEmojiId !== null) {
            $snapshot['icon_custom_emoji_id'] = $this->iconCustomEmojiId;
        }

        return $snapshot;
    }
}
