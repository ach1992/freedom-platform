<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Reporting\Application\ReportDateRange;
use App\Modules\Reporting\Application\ReportMetric;
use App\Modules\Reporting\Application\ReportSnapshot;
use App\Modules\Reporting\Application\ReportTelegramFormatter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReportTelegramFormatterTest extends TestCase
{
    public function test_formatter_is_bounded_and_preserves_exact_integer_financial_values(): void
    {
        $metrics = [
            new ReportMetric('financial', 'sales.net_irr', 'Net captured sales', 123456789, 'IRR', priorValue: 100000000),
        ];
        for ($index = 0; $index < 80; $index++) {
            $metrics[] = new ReportMetric(
                'users',
                'users.dimension_'.$index,
                str_repeat('Metric '.$index.' ', 8),
                $index,
                'count',
                'dimension-'.$index,
                $index - 1,
            );
        }

        $snapshot = new ReportSnapshot(
            new ReportDateRange(
                'today',
                new DateTimeImmutable('2026-09-29T20:30:00+00:00'),
                new DateTimeImmutable('2026-09-30T02:47:00+00:00'),
            ),
            null,
            new DateTimeImmutable('2026-09-30T02:47:00+00:00'),
            $metrics,
        );

        $text = (new ReportTelegramFormatter)->format($snapshot);

        self::assertLessThanOrEqual(3900, mb_strlen($text));
        self::assertStringContainsString('12,345,678.9 Toman', $text);
        self::assertStringContainsString('prior 10,000,000 Toman', $text);
        self::assertStringContainsString('Δ +2,345,678.9 Toman', $text);
        self::assertStringContainsString('additional metrics omitted', $text);
        self::assertStringNotContainsString('%', $text);
    }
}
