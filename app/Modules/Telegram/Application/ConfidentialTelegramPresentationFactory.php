<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use SensitiveParameter;

/**
 * Construction boundary for CONFIDENTIAL Telegram text. Reviewed production
 * source paths are intentionally separate from the non-restricted allowlist.
 */
final readonly class ConfidentialTelegramPresentationFactory
{
    public function fromSource(
        #[SensitiveParameter] ConfidentialTelegramPresentationSource $source,
    ): ConfidentialTelegramPresentation {
        TelegramConfidentialPresentationProvenanceGuard::assertReviewedSourceCaller();

        return ConfidentialTelegramPresentation::fromReviewedConfidentialSource($source);
    }
}
