<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Identity\Application\Contracts\CustomerIdentityProfileWriter;
use App\Modules\Identity\Application\TelegramIdentityAccountService;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

final readonly class TelegramIdentitySynchronizer
{
    /** @requirement ONB-001 ONB-005 USR-001 SEC-003 */
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private TelegramIdentityAccountService $identityAccounts,
        private CustomerIdentityProfileWriter $customerProfiles,
    ) {}

    /** @param array<string, mixed> $update */
    public function synchronize(string $botId, int $updateId, array $update): ?int
    {
        $telegramUser = $this->extractTelegramUser($update);

        if ($telegramUser === null) {
            return null;
        }

        $telegramUserId = $telegramUser['id'] ?? null;

        if (! is_int($telegramUserId) || $telegramUserId < 1) {
            return null;
        }

        $username = $this->optionalString($telegramUser['username'] ?? null, 64);
        $languageCode = $this->optionalString($telegramUser['language_code'] ?? null, 16);
        $locale = $languageCode === 'en' ? 'en' : 'fa';
        $isBot = ($telegramUser['is_bot'] ?? false) === true;
        $now = now('UTC')->format('Y-m-d H:i:s.u');

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $botId,
            $updateId,
            $telegramUserId,
            $username,
            $languageCode,
            $locale,
            $isBot,
            $update,
            $now,
        ): int {
            $identity = $this->identityAccounts->synchronize(
                $connection,
                $botId,
                $telegramUserId,
                $username,
                $languageCode,
                $locale,
                $isBot,
                $now,
            );

            if ($identity->profileBootstrapRequired) {
                $this->customerProfiles->ensure($connection, $identity->userId, $now);
            }

            $this->recordStartAttribution(
                $connection,
                $botId,
                $identity->userId,
                $updateId,
                $update,
                $now,
            );

            return $identity->userId;
        });
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>|null
     */
    private function extractTelegramUser(array $update): ?array
    {
        foreach ([
            ['message', 'from'],
            ['edited_message', 'from'],
            ['channel_post', 'from'],
            ['edited_channel_post', 'from'],
            ['callback_query', 'from'],
            ['inline_query', 'from'],
            ['chosen_inline_result', 'from'],
            ['my_chat_member', 'from'],
            ['chat_member', 'from'],
            ['chat_join_request', 'from'],
        ] as [$containerKey, $userKey]) {
            $container = $update[$containerKey] ?? null;
            $user = is_array($container) ? ($container[$userKey] ?? null) : null;

            if (is_array($user) && ! array_is_list($user)) {
                return $user;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $update */
    private function recordStartAttribution(
        Connection $connection,
        string $botId,
        int $userId,
        int $updateId,
        array $update,
        string $now,
    ): void {
        $message = $update['message'] ?? null;
        $text = is_array($message) ? ($message['text'] ?? null) : null;

        if (! is_string($text) || preg_match('/\A\/start(?:@[A-Za-z0-9_]+)?(?:\s+([A-Za-z0-9_-]{1,64}))?\z/u', trim($text), $matches) !== 1) {
            return;
        }

        $parameter = $matches[1] ?? null;

        if (! is_string($parameter)) {
            return;
        }

        $connection->table('telegram_start_attributions')->insertOrIgnore([
            'user_id' => $userId,
            'bot_id' => $botId,
            'payload_hash' => hash('sha256', $parameter),
            'payload_ciphertext' => $this->encrypter->encryptString($parameter),
            'first_update_id' => $updateId,
            'created_at' => $now,
        ]);
    }

    private function optionalString(mixed $value, int $maximumLength): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maximumLength);
    }
}
