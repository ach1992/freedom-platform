<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Contracts;

interface BackupTelegramOwnerDestinationResolver
{
    /** @return array{user_id:int,recipient_chat_id:int} */
    public function resolve(): array;
}
