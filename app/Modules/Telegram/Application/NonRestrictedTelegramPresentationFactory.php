<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

/**
 * Application construction boundary for new persistable generic Telegram text.
 * The factory and queue authority independently require an exact reviewed source
 * file. Phase #179 intentionally has no production source yet; later Phase 0.7
 * journeys must add their exact file to the runtime/architecture allowlist after
 * classification review.
 */
final readonly class NonRestrictedTelegramPresentationFactory
{
    public function fromSource(NonRestrictedTelegramPresentationSource $source): NonRestrictedTelegramPresentation
    {
        TelegramPresentationProvenanceGuard::assertReviewedSourceCaller();

        return NonRestrictedTelegramPresentation::fromReviewedSource($source);
    }
}
