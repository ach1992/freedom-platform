<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramNavigationEntryGateway
{
    public const FLOW = 'navigation.home';

    public const STATE = 'home';

    public function __construct(private TelegramInteractionSessionService $sessions) {}

    /** @param array<string, mixed> $message */
    public function startIfEligible(
        string $botId,
        int $updateId,
        int $telegramAccountId,
        int $telegramUserId,
        array $message,
        string $text,
    ): bool {
        if (! $this->isEntryCommand($text) || ! $this->isPrivateActorChat($message, $telegramUserId)) {
            return false;
        }

        if ($this->sessions->activeForAccount($telegramAccountId) !== null) {
            return false;
        }

        $this->sessions->start(
            $telegramAccountId,
            self::FLOW,
            self::STATE,
            [],
            "telegram-entry:{$botId}:{$updateId}:navigation-home",
        );

        return true;
    }

    private function isEntryCommand(string $text): bool
    {
        $trimmed = trim($text);

        return preg_match('/\A\/menu(?:@[A-Za-z0-9_]+)?\z/u', $trimmed) === 1
            || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+[A-Za-z0-9_-]{1,64})?\z/u', $trimmed) === 1;
    }

    /** @param array<string, mixed> $message */
    private function isPrivateActorChat(array $message, int $telegramUserId): bool
    {
        $chat = $message['chat'] ?? null;
        if (! is_array($chat) || array_is_list($chat) || ($chat['type'] ?? null) !== 'private') {
            return false;
        }

        $chatId = $chat['id'] ?? null;

        return is_int($chatId) && $chatId > 0 && $chatId === $telegramUserId;
    }
}
