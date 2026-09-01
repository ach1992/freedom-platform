<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

/**
 * Explicit source contract for CONFIDENTIAL Telegram text.
 *
 * Implementing this interface grants no delivery authority. Construction and
 * queueing remain guarded by TelegramConfidentialPresentationProvenanceGuard.
 */
interface ConfidentialTelegramPresentationSource
{
    public function confidentialTelegramText(): string;
}
