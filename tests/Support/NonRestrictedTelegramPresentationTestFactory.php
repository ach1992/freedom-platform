<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationFactory;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationSource;

final class NonRestrictedTelegramPresentationTestFactory
{
    public static function plainText(string $text): NonRestrictedTelegramPresentation
    {
        $source = new readonly class($text) implements NonRestrictedTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function nonRestrictedTelegramText(): string
            {
                return $this->text;
            }
        };

        return (new NonRestrictedTelegramPresentationFactory)->fromSource($source);
    }
}
