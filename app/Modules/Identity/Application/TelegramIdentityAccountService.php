<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramIdentityAccountService
{
    public function synchronize(
        Connection $connection,
        string $botId,
        int $telegramUserId,
        ?string $username,
        ?string $languageCode,
        string $locale,
        bool $isBot,
        string $timestamp,
    ): TelegramIdentityAccountResult {
        /** @var object{id:int|string,user_id:int|string}|null $account */
        $account = $connection->table('telegram_accounts')
            ->where('bot_id', $botId)
            ->where('telegram_user_id', $telegramUserId)
            ->lockForUpdate()
            ->first(['id', 'user_id']);

        if ($account !== null) {
            $userId = (int) $account->user_id;
            $this->touchExistingIdentity(
                $connection,
                (int) $account->id,
                $userId,
                $username,
                $languageCode,
                $locale,
                $isBot,
                $timestamp,
            );

            return new TelegramIdentityAccountResult($userId, false);
        }

        $userId = (int) $connection->table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => 'customer',
            'account_status' => 'active',
            'locale' => $locale,
            'first_seen_at' => $timestamp,
            'last_seen_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $inserted = $connection->table('telegram_accounts')->insertOrIgnore([
            'user_id' => $userId,
            'bot_id' => $botId,
            'telegram_user_id' => $telegramUserId,
            'username' => $username,
            'language_code' => $languageCode,
            'is_bot' => $isBot,
            'first_seen_at' => $timestamp,
            'last_seen_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        if ($inserted !== 1) {
            $connection->table('users')->where('id', $userId)->delete();
            /** @var object{id:int|string,user_id:int|string}|null $winner */
            $winner = $connection->table('telegram_accounts')
                ->where('bot_id', $botId)
                ->where('telegram_user_id', $telegramUserId)
                ->lockForUpdate()
                ->first(['id', 'user_id']);

            if ($winner === null) {
                throw new RuntimeException('Telegram identity race could not be resolved.');
            }

            $userId = (int) $winner->user_id;
            $this->touchExistingIdentity(
                $connection,
                (int) $winner->id,
                $userId,
                $username,
                $languageCode,
                $locale,
                $isBot,
                $timestamp,
            );
        }

        return new TelegramIdentityAccountResult($userId, true);
    }

    private function touchExistingIdentity(
        Connection $connection,
        int $accountId,
        int $userId,
        ?string $username,
        ?string $languageCode,
        string $locale,
        bool $isBot,
        string $timestamp,
    ): void {
        $connection->table('telegram_accounts')->where('id', $accountId)->update([
            'username' => $username,
            'language_code' => $languageCode,
            'is_bot' => $isBot,
            'last_seen_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $connection->table('users')->where('id', $userId)->update([
            'locale' => $locale,
            'last_seen_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
