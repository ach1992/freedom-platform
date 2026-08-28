<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use LogicException;

/**
 * The only application construction boundary for new persistable generic
 * Telegram text. Both CI and this runtime gate require the caller to be an exact
 * reviewed source file. Phase #179 intentionally has no production source yet;
 * later Phase 0.7 journeys must add their exact file here and to the architecture allowlist during classification review.
 */
final readonly class NonRestrictedTelegramPresentationFactory
{
    /** @var list<string> */
    private const REVIEWED_SOURCE_FILES = [];

    public function fromSource(NonRestrictedTelegramPresentationSource $source): NonRestrictedTelegramPresentation
    {
        $this->assertReviewedCaller();

        return NonRestrictedTelegramPresentation::fromReviewedSource($source);
    }

    private function assertReviewedCaller(): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1] ?? null;
        $callerFile = is_array($caller) ? ($caller['file'] ?? null) : null;
        $resolvedCaller = is_string($callerFile) ? realpath($callerFile) : false;
        if ($resolvedCaller === false) {
            throw new LogicException('Telegram presentation source provenance is unavailable.');
        }

        $root = dirname(__DIR__, 4);
        foreach (self::REVIEWED_SOURCE_FILES as $relativePath) {
            $reviewed = realpath($root.'/'.$relativePath);
            if ($reviewed !== false && $reviewed === $resolvedCaller) {
                return;
            }
        }

        throw new LogicException('Telegram presentation source is not an exact reviewed production gateway.');
    }
}
