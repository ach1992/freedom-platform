<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionSessionStatus;
use DateTimeImmutable;

final readonly class TelegramInteractionSessionReceipt
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $publicId,
        public int $telegramAccountId,
        public int $userId,
        public string $flow,
        public string $state,
        public TelegramInteractionSessionStatus $status,
        public int $version,
        public array $payload,
        public DateTimeImmutable $expiresAt,
        public bool $replayed,
    ) {}
}
