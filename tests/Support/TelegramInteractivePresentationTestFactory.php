<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationService;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;

final class TelegramInteractivePresentationTestFactory
{
    public static function service(
        Clock $clock,
        TelegramDeliveryRuntime $runtime,
    ): TelegramDeliveryInteractivePresentationService {
        return new TelegramDeliveryInteractivePresentationService(
            $clock,
            app(StringEncrypter::class),
            $runtime,
            new TelegramDeliveryInteractivePresentationDatabaseCapability,
        );
    }
}
