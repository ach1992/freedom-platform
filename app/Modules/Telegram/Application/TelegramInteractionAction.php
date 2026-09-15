<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use DateTimeImmutable;

final readonly class TelegramInteractionAction
{
    /**
     * @param  array<string, mixed>  $sessionPayload
     * @param  array<string, mixed>  $callbackPayload
     */
    public function __construct(
        public TelegramInteractionActionKind $kind,
        public string $requestKey,
        public string $botId,
        public int $updateId,
        public int $telegramAccountId,
        public int $userId,
        public int $telegramUserId,
        public string $sessionPublicId,
        public string $flow,
        public string $sessionState,
        public int $sessionVersion,
        public array $sessionPayload,
        public ?string $messageText,
        public ?string $callbackPublicId,
        public ?string $callbackAction,
        public array $callbackPayload,
        public bool $replayed,
        public ?DateTimeImmutable $callbackAcceptedAt = null,
        public ?DateTimeImmutable $messageAcceptedAt = null,
    ) {}
}
