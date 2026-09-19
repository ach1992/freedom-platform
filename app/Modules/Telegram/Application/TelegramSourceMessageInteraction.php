<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramSourceMessageInteraction
{
    /** @param array<string,mixed> $sessionPayload */
    public function __construct(
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
        public int $sourceChatId,
        public int $sourceMessageId,
        public bool $replayed,
    ) {
        if ($requestKey === ''
            || preg_match('/\A[1-9][0-9]{5,19}\z/', $botId) !== 1
            || $updateId < 0
            || $telegramAccountId < 1
            || $userId < 1
            || $telegramUserId < 1
            || $sessionPublicId === ''
            || $flow === ''
            || $sessionState === ''
            || $sessionVersion < 1
            || $sourceChatId < 1
            || $sourceMessageId < 1
            || $sourceChatId !== $telegramUserId) {
            throw new InvalidArgumentException('Telegram source-message interaction snapshot is invalid.');
        }
    }
}
