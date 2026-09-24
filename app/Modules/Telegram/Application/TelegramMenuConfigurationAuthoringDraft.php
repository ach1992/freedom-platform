<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramMenuConfigurationAuthoringDraft
{
    public function __construct(
        public string $menuKey,
        public string $reason,
        public TelegramMenuConfigurationDefinition $definition,
    ) {}
}
