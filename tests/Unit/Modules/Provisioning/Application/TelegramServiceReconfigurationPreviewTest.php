<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning\Application;

use App\Modules\Telegram\Application\TelegramServiceReconfigurationPreview;
use PHPUnit\Framework\TestCase;

final class TelegramServiceReconfigurationPreviewTest extends TestCase
{
    public function test_requires_payment_only_when_total_is_positive(): void
    {
        self::assertFalse($this->preview(0, 0)->requiresPayment());

        $paid = $this->preview(25_000, 50_000);
        self::assertTrue($paid->requiresPayment());
        self::assertSame(75_000, $paid->totalPriceIrr);
    }

    private function preview(int $difference, int $fee): TelegramServiceReconfigurationPreview
    {
        return new TelegramServiceReconfigurationPreview(
            '01K6NQ6VZB7S0Z9VJQ3X7Y8A9B',
            '01K6NQ6VZB7S0Z9VJQ3X7Y8A9C',
            str_repeat('a', 40),
            'source-plan',
            'target-plan',
            'target-server',
            'target-profile',
            true,
            true,
            true,
            $difference,
            $fee,
            $difference + $fee,
            '2026-09-26 12:00:00.000000',
            false,
        );
    }
}
