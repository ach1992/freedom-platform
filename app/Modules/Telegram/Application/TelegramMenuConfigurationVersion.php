<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramMenuConfigurationVersion
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $menuKey,
        public int $version,
        public TelegramMenuConfigurationDefinition $definition,
        public int $createdByAdministratorId,
        public string $createdAt,
        public bool $active,
        public bool $replayed = false,
    ) {}
}
