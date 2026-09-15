<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

/**
 * Marker contract for a reviewed Telegram-owned source whose text is known not
 * to contain RESTRICTED data. Implementations/callers are constrained by the
 * repository architecture policy to exact reviewed source paths.
 */
interface NonRestrictedTelegramPresentationSource
{
    public function nonRestrictedTelegramText(): string;
}
