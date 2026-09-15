<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

final readonly class TelegramIdentityAccountResult
{
    public function __construct(
        public int $userId,
        public bool $profileBootstrapRequired,
    ) {}
}
