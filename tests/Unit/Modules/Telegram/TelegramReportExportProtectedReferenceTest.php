<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramProtectedPresentationReference;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TelegramReportExportProtectedReferenceTest extends TestCase
{
    /** @requirement REP-003 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function test_report_export_reference_round_trips_only_bounded_query_identity(): void
    {
        $start = new DateTimeImmutable('2026-09-22T20:30:00+00:00');
        $end = new DateTimeImmutable('2026-09-30T02:47:00+00:00');
        $reference = TelegramProtectedPresentationReference::reportExport(
            'xlsx',
            'persian_month',
            $start,
            $end,
            'fa',
        );

        $durable = $reference->durableText();
        self::assertSame(
            '[PROTECTED_TELEGRAM_REFERENCE:v6:report_export:xlsx:persian_month:'
            .$start->getTimestamp().':'.$end->getTimestamp().':fa]',
            $durable,
        );
        self::assertStringNotContainsString('IRR', $durable);
        self::assertStringNotContainsString('phone', $durable);
        self::assertStringNotContainsString('wallet', $durable);

        $restored = TelegramProtectedPresentationReference::restore($durable);
        self::assertTrue($restored->isReportExport());
        self::assertSame([
            'format' => 'xlsx',
            'period' => 'persian_month',
            'start_epoch' => $start->getTimestamp(),
            'end_epoch' => $end->getTimestamp(),
        ], $restored->reportExportIdentity());
    }

    /** @requirement REP-003 SEC-001 QUA-001 */
    public function test_report_export_reference_accepts_all_time_and_rejects_invalid_identity(): void
    {
        $end = new DateTimeImmutable('2026-09-30T02:47:00+00:00');
        $allTime = TelegramProtectedPresentationReference::reportExport('csv', 'all_time', null, $end, 'en');
        self::assertStringContainsString(':all_time:-:'.$end->getTimestamp().':en]', $allTime->durableText());

        foreach ([
            static fn () => TelegramProtectedPresentationReference::reportExport('pdf', 'today', null, $end, 'fa'),
            static fn () => TelegramProtectedPresentationReference::reportExport('csv', 'bad:period', null, $end, 'fa'),
            static fn () => TelegramProtectedPresentationReference::reportExport(
                'csv',
                'custom',
                $end,
                $end,
                'fa',
            ),
            static fn () => TelegramProtectedPresentationReference::restore(
                '[PROTECTED_TELEGRAM_REFERENCE:v6:report_export:csv:today:not-an-epoch:1:fa]',
            ),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid report export reference must be rejected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
