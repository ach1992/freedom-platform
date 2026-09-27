<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use RuntimeException;

final class TelegramDeliveryRetryDirectiveReader
{
    public function retryNotBefore(
        Connection $connection,
        string $operationPublicId,
        mixed $providerAttempt,
    ): ?string {
        $attempt = filter_var($providerAttempt, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if ($attempt === false) {
            throw new RuntimeException('Telegram provider attempt is invalid for retry projection.');
        }

        $value = $connection->table('telegram_delivery_retry_directives')
            ->where('operation_public_id', $operationPublicId)
            ->where('provider_attempt', $attempt)
            ->value('retry_not_before');
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new RuntimeException('Telegram provider retry deadline is invalid.');
        }

        $deadline = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if ($deadline === false) {
            throw new RuntimeException('Telegram provider retry deadline is invalid.');
        }

        return $deadline->format('Y-m-d H:i:s.u');
    }
}
