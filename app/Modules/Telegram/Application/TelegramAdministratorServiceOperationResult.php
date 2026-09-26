<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramAdministratorServiceOperationResult
{
    public function __construct(public string $summary) {}
}
