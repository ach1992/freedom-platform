<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;

interface TelegramReportingExportPresentationSource
{
    public function resolveForAdministratorSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation;
}
