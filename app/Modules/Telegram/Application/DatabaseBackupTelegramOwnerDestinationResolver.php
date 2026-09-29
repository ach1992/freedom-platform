<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Operations\Application\Contracts\BackupTelegramOwnerDestinationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class DatabaseBackupTelegramOwnerDestinationResolver implements BackupTelegramOwnerDestinationResolver
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    /** @return array{user_id:int,recipient_chat_id:int} */
    public function resolve(): array
    {
        $botId = $this->runtime->botId();

        $rows = $this->database->connection()
            ->table('administrators as administrator')
            ->join('telegram_accounts as telegram', function ($join) use ($botId): void {
                $join->on('telegram.user_id', '=', 'administrator.user_id')
                    ->where('telegram.bot_id', '=', $botId)
                    ->where('telegram.is_bot', '=', false);
            })
            ->where('administrator.status', 'active')
            ->where('administrator.is_owner', true)
            ->get([
                'administrator.user_id',
                'telegram.telegram_user_id',
            ])
            ->all();

        if (count($rows) !== 1) {
            throw new RuntimeException('Backup Telegram export requires exactly one active Owner Telegram account.');
        }

        $userId = (int) ($rows[0]->user_id ?? 0);
        $telegramUserId = (int) ($rows[0]->telegram_user_id ?? 0);
        if ($userId < 1 || $telegramUserId < 1) {
            throw new RuntimeException('Backup Telegram export Owner identity is invalid.');
        }

        return [
            'user_id' => $userId,
            'recipient_chat_id' => $telegramUserId,
        ];
    }
}
