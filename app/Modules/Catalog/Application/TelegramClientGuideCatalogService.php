<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Telegram\Application\Contracts\TelegramClientGuideCatalog;
use App\Modules\Telegram\Application\TelegramClientGuideDefinition;
use App\Modules\Telegram\Application\TelegramClientGuidePage;
use App\Modules\Telegram\Application\TelegramClientGuideResource;

final readonly class TelegramClientGuideCatalogService implements TelegramClientGuideCatalog
{
    public function __construct(private ClientGuideCatalogService $catalog) {}

    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): TelegramClientGuidePage
    {
        return $this->page($this->catalog->pageForSelf($actorUserId, $subjectUserId, $page, $pageSize));
    }

    public function administratorPageForUser(int $userId, int $page, int $pageSize): TelegramClientGuidePage
    {
        return $this->page($this->catalog->administratorPageForUser($userId, $page, $pageSize));
    }

    public function administratorResourceForUser(int $userId, string $publicId): TelegramClientGuideResource
    {
        return $this->resource($this->catalog->administratorResourceForUser($userId, $publicId));
    }

    public function availableForAdministratorUser(int $userId): bool
    {
        return $this->catalog->availableForAdministratorUser($userId);
    }

    public function createForAdministratorUser(
        int $userId,
        TelegramClientGuideDefinition $definition,
        string $requestFingerprint,
        string $correlationId,
        string $reason,
    ): void {
        $administratorId = $this->catalog->administratorIdForUser($userId);
        $this->catalog->create(
            $this->definition($definition),
            new CatalogChangeContext(
                $requestFingerprint,
                $correlationId,
                'telegram_client_guide',
                $reason,
                $administratorId,
            ),
        );
    }

    public function updateForAdministratorUser(
        int $userId,
        string $publicId,
        int $expectedVersion,
        TelegramClientGuideDefinition $definition,
        string $requestFingerprint,
        string $correlationId,
        string $reason,
    ): void {
        $administratorId = $this->catalog->administratorIdForUser($userId);
        $this->catalog->updateByPublicId(
            $publicId,
            $expectedVersion,
            $this->definition($definition),
            new CatalogChangeContext(
                $requestFingerprint,
                $correlationId,
                'telegram_client_guide',
                $reason,
                $administratorId,
            ),
        );
    }

    private function definition(TelegramClientGuideDefinition $definition): ClientGuideResourceDefinition
    {
        return new ClientGuideResourceDefinition(
            $definition->code,
            $definition->titleFa,
            $definition->titleEn,
            $definition->descriptionFa,
            $definition->descriptionEn,
            $definition->platform,
            $definition->language,
            $definition->audience,
            $definition->tierCode,
            $definition->customerTagCode,
            $definition->resourceUrl,
            $definition->tutorialFa,
            $definition->tutorialEn,
            $definition->normalEmoji,
            $definition->premiumEmojiId,
            $definition->sortOrder,
            $definition->state,
        );
    }

    private function page(ClientGuideCatalogPage $page): TelegramClientGuidePage
    {
        $items = [];
        foreach ($page->items as $item) {
            $items[] = $this->resource($item);
        }

        return new TelegramClientGuidePage($items, $page->page, $page->totalPages, $page->totalItems);
    }

    private function resource(ClientGuideResourceView $resource): TelegramClientGuideResource
    {
        return new TelegramClientGuideResource(
            $resource->id,
            $resource->publicId,
            $resource->code,
            $resource->titleFa,
            $resource->titleEn,
            $resource->descriptionFa,
            $resource->descriptionEn,
            $resource->platform,
            $resource->language,
            $resource->audience,
            $resource->tierCode,
            $resource->customerTagCode,
            $resource->resourceUrl,
            $resource->tutorialFa,
            $resource->tutorialEn,
            $resource->normalEmoji,
            $resource->premiumEmojiId,
            $resource->sortOrder,
            $resource->state,
            $resource->version,
            $resource->lastValidatedAt,
            $resource->lastValidatedByAdministratorId,
        );
    }
}
