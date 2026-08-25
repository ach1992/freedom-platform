<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;

final readonly class TelegramInteractionCallbackReceipt
{
    /**
     * @param  array<string, mixed>  $sessionPayload
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $publicId,
        public string $token,
        public string $requestKey,
        public string $sessionPublicId,
        public int $telegramAccountId,
        public int $userId,
        public string $flow,
        public string $sessionState,
        public int $sessionVersion,
        public array $sessionPayload,
        public string $action,
        public array $payload,
        public DateTimeImmutable $expiresAt,
        public bool $accepted,
        public ?int $acceptedUpdateId,
        public bool $completed,
        public bool $replayed,
    ) {}
}
