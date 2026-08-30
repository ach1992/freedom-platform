<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramDeliveryOperationReceipt;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use LogicException;
use ReflectionClass;

final class NonRestrictedTelegramPresentationTestFactory
{
    public static function plainText(string $text): NonRestrictedTelegramPresentation
    {
        $reflection = new ReflectionClass(NonRestrictedTelegramPresentation::class);
        $validated = $reflection->getMethod('validated')->invoke(null, $text);
        if (! $validated instanceof NonRestrictedTelegramPresentation) {
            throw new LogicException('Unable to create Telegram presentation test fixture.');
        }

        return $validated;
    }

    public static function queue(
        TelegramDeliveryQueueService $queue,
        TelegramDeliveryAction $action,
        int $recipientChatId,
        ?int $targetMessageId,
        ?NonRestrictedTelegramPresentation $presentation,
        string $requestKey,
        string $correlationId,
    ): TelegramDeliveryOperationReceipt {
        return $queue->queue(
            $action,
            $recipientChatId,
            $targetMessageId,
            $presentation,
            $requestKey,
            $correlationId,
        );
    }
}
