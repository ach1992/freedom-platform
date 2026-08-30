<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

/**
 * Application construction boundary for new persistable generic Telegram text.
 * The factory and queue authority independently require an exact reviewed source
 * file. Each production journey must add only its exact reviewed Telegram-owned
 * source path to the runtime/architecture allowlist after data-classification
 * review.
 */
final readonly class NonRestrictedTelegramPresentationFactory
{
    public function fromSource(NonRestrictedTelegramPresentationSource $source): NonRestrictedTelegramPresentation
    {
        TelegramPresentationProvenanceGuard::assertReviewedSourceCaller();

        return NonRestrictedTelegramPresentation::fromReviewedSource($source);
    }
}
