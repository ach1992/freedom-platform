<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use Illuminate\Database\Connection;

interface TelegramDeliveryEffectGuard
{
    /**
     * Return a stable result code to reject the mutation before provider effect,
     * or null when this operation may enter the provider boundary.
     *
     * @requirement COM-002 COM-003 ACL-002 SEC-002 SEC-008 QUA-001 QUA-004
     */
    public function rejectionCode(
        Connection $connection,
        string $operationPublicId,
        string $correlationId,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
    ): ?string;
}
