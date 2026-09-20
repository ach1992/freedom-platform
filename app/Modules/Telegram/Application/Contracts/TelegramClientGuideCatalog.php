<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Application\TelegramClientGuideDefinition;
use App\Modules\Telegram\Application\TelegramClientGuidePage;
use App\Modules\Telegram\Application\TelegramClientGuideResource;

interface TelegramClientGuideCatalog
{
    public const MANAGE_PERMISSION = 'catalog.manage';

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramClientGuidePage;

    public function administratorPageForUser(int $userId, int $page, int $pageSize): TelegramClientGuidePage;

    public function administratorResourceForUser(int $userId, string $publicId): TelegramClientGuideResource;

    public function availableForAdministratorUser(int $userId): bool;

    public function createForAdministratorUser(
        int $userId,
        TelegramClientGuideDefinition $definition,
        string $requestFingerprint,
        string $correlationId,
        string $reason,
    ): void;

    public function updateForAdministratorUser(
        int $userId,
        string $publicId,
        int $expectedVersion,
        TelegramClientGuideDefinition $definition,
        string $requestFingerprint,
        string $correlationId,
        string $reason,
    ): void;
}
