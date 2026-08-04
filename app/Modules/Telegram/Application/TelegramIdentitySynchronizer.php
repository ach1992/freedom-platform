<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramIdentitySynchronizer
{
    /** @requirement ONB-001 ONB-005 USR-001 SEC-003 */
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
    ) {
    }

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

        return $this->database->connection()->transaction(function () use (
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
            $account = $this->database->connection()->table('telegram_accounts')
                ->where('bot_id', $botId)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first(['id', 'user_id']);

            if ($account !== null) {
                $userId = (int) $account->user_id;
                $this->touchExistingIdentity(
                    (int) $account->id,
                    $userId,
                    $username,
                    $languageCode,
                    $locale,
                    $isBot,
                    $now,
                );
                $this->recordStartAttribution($botId, $userId, $updateId, $update, $now);

                return $userId;
            }

            $userId = (int) $this->database->connection()->table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => $locale,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $inserted = $this->database->connection()->table('telegram_accounts')->insertOrIgnore([
                'user_id' => $userId,
                'bot_id' => $botId,
                'telegram_user_id' => $telegramUserId,
                'username' => $username,
                'language_code' => $languageCode,
                'is_bot' => $isBot,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted !== 1) {
                $this->database->connection()->table('users')->where('id', $userId)->delete();
                $winner = $this->database->connection()->table('telegram_accounts')
                    ->where('bot_id', $botId)
                    ->where('telegram_user_id', $telegramUserId)
                    ->lockForUpdate()
                    ->first(['id', 'user_id']);

                if ($winner === null) {
                    throw new RuntimeException('Telegram identity race could not be resolved.');
                }

                $userId = (int) $winner->user_id;
                $this->touchExistingIdentity(
                    (int) $winner->id,
                    $userId,
                    $username,
                    $languageCode,
                    $locale,
                    $isBot,
                    $now,
                );
            }

            $tierId = $this->database->connection()->table('customer_tiers')
                ->where('code', 'new')
                ->value('id');

            $this->database->connection()->table('customer_profiles')->insertOrIgnore([
                'user_id' => $userId,
                'current_tier_id' => is_numeric($tierId) ? (int) $tierId : null,
                'tier_locked' => false,
                'phone_verification_status' => 'unverified',
                'identity_verification_status' => 'unverified',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordStartAttribution($botId, $userId, $updateId, $update, $now);

            return $userId;
        });
    }

    private function touchExistingIdentity(
        int $accountId,
        int $userId,
        ?string $username,
        ?string $languageCode,
        string $locale,
        bool $isBot,
        string $now,
    ): void {
        $this->database->connection()->table('telegram_accounts')->where('id', $accountId)->update([
            'username' => $username,
            'language_code' => $languageCode,
            'is_bot' => $isBot,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);

        $this->database->connection()->table('users')->where('id', $userId)->update([
            'locale' => $locale,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $update
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

        if (! is_string($parameter) || $parameter === '') {
            return;
        }

        $this->database->connection()->table('telegram_start_attributions')->insertOrIgnore([
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
