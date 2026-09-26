<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramServiceAutoRenewPolicyNavigationHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TelegramServiceAutoRenewPolicyNavigationContractTest extends TestCase
{
    public function test_delivery_correlation_identity_stays_within_telegram_authority_limit(): void
    {
        $file = (new ReflectionClass(TelegramServiceAutoRenewPolicyNavigationHandler::class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Telegram Service auto-renew policy source file is unavailable.');
        }
        $source = file_get_contents($file);
        if (! is_string($source)) {
            throw new RuntimeException('Telegram Service auto-renew policy source cannot be read.');
        }

        $prefix = 'tg-admin-auto-policy:';

        self::assertLessThanOrEqual(64, strlen($prefix) + 40);
        self::assertStringContainsString("'".$prefix."'.substr(hash('sha256'", $source);
        self::assertStringContainsString('), 0, 40)', $source);
        self::assertStringNotContainsString('tg-admin-service-auto-policy:', $source);
    }
}
