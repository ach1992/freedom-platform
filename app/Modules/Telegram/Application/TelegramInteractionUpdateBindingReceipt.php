<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;

final readonly class TelegramInteractionUpdateBindingReceipt
{
    /** @param array<string, mixed> $sessionPayload */
    public function __construct(
        public string $kind,
        public string $requestKey,
        public int $telegramAccountId,
        public int $userId,
        public int $telegramUserId,
        public ?string $sessionPublicId,
        public ?string $flow,
        public ?string $sessionState,
        public ?int $sessionVersion,
        public array $sessionPayload,
        public bool $replayed,
        public DateTimeImmutable $acceptedAt,
    ) {}
}
