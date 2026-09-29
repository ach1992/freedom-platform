<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;

interface TelegramBackupArtifactPresentationSource
{
    public function resolveForOwner(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation;
}
