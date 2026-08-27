<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application\Contracts;

interface TelegramRuntime extends ProtectedTelegramDeliveryRuntime
{
    public function webhookUrl(): string;

    public function webhookSecret(): string;

    public function maximumBodyBytes(): int;

    public function queue(): string;

    public function processingLeaseSeconds(): int;
}
