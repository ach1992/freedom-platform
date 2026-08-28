<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

/**
 * The only application construction boundary for new persistable generic
 * Telegram text. CI permits callers only from exact reviewed Telegram source
 * paths so raw RESTRICTED owners cannot adapt arbitrary strings into this path.
 */
final readonly class NonRestrictedTelegramPresentationFactory
{
    public function fromSource(NonRestrictedTelegramPresentationSource $source): NonRestrictedTelegramPresentation
    {
        return NonRestrictedTelegramPresentation::fromReviewedSource($source);
    }
}
