<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class TelegramMenuPresentationCapabilities
{
    public bool $styleSupported;

    public bool $premiumEmojiSupported;

    public function __construct(Repository $config)
    {
        $style = $config->get('telegram.inline_button_style_supported', true);
        $premium = $config->get('telegram.inline_button_premium_emoji_supported', false);

        if (! is_bool($style) || ! is_bool($premium)) {
            throw new RuntimeException('Telegram menu presentation capability configuration is invalid.');
        }

        $this->styleSupported = $style;
        $this->premiumEmojiSupported = $premium;
    }

    public function style(?TelegramInlineButtonStyle $style): ?TelegramInlineButtonStyle
    {
        return $this->styleSupported ? $style : null;
    }

    public function premiumEmojiId(TelegramMenuItemDefinition $item): ?string
    {
        return $this->premiumEmojiSupported ? $item->premiumEmojiId : null;
    }

    public function label(TelegramMenuItemDefinition $item, string $baseLabel): string
    {
        if ($item->normalEmoji === null) {
            return $baseLabel;
        }

        if ($item->premiumEmojiId !== null && $this->premiumEmojiSupported) {
            return $baseLabel;
        }

        return $item->normalEmoji.' '.$baseLabel;
    }
}
