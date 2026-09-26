<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramAdministratorServiceOperationPreview
{
    /** @param array<string,mixed> $confirmationPayload */
    public function __construct(
        public string $summary,
        public bool $requiresConfirmation,
        public array $confirmationPayload = [],
    ) {}
}
