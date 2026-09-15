<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramInlineHttpsUrlButton
{
    private const MAXIMUM_TEXT_CHARACTERS = 64;

    public function __construct(
        public string $text,
        public string $url,
        public TelegramInlineHttpsUrlPurpose $purpose,
        public ?TelegramInlineButtonStyle $style = null,
    ) {
        if ($text === '' || trim($text) === '' || mb_strlen($text) > self::MAXIMUM_TEXT_CHARACTERS) {
            throw new InvalidArgumentException('Telegram inline button text must contain 1-64 visible characters.');
        }
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new InvalidArgumentException('Telegram inline button text must be safe UTF-8.');
        }

        TelegramInlineHttpsUrlPolicy::assertAllowed($url, $purpose);
    }

    /** @return array{text:string,https_url:string,https_url_purpose:string,style:?string} */
    public function snapshot(): array
    {
        return [
            'text' => $this->text,
            'https_url' => $this->url,
            'https_url_purpose' => $this->purpose->value,
            'style' => $this->style?->value,
        ];
    }

    /** @return array{redacted:true,type:string,purpose:string} */
    public function __debugInfo(): array
    {
        return [
            'redacted' => true,
            'type' => 'inline_https_url_button',
            'purpose' => $this->purpose->value,
        ];
    }
}
