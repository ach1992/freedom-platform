<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramPrivateMediaDeliveryQueue;
use App\Modules\Telegram\Application\TelegramPrivateMediaPresentationReference;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TelegramPrivateMediaDeliveryProvenanceTest extends TestCase
{
    public function test_unrelated_caller_cannot_queue_private_media_through_public_facade(): void
    {
        $delivery = (new ReflectionClass(TelegramDeliveryQueueService::class))
            ->newInstanceWithoutConstructor();
        $queue = new TelegramPrivateMediaDeliveryQueue($delivery);
        $reference = TelegramPrivateMediaPresentationReference::administratorDirectMessage(
            '01J7Y2ZZZZZZZZZZZZZZZZZZZZ',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Private Telegram media delivery queue requires the exact administrator direct-media authority.',
        );

        $queue->send(
            123456789,
            $reference,
            'unrelated-private-media-request',
            'unrelated-private-media-correlation',
        );
    }
}
