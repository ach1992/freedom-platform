<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reporting;

use App\Modules\Reporting\Application\ReportExportDataset;
use App\Modules\Reporting\Application\ReportExporter;
use App\Modules\Reporting\Application\ReportValueMasker;
use App\Modules\Reporting\Application\SpreadsheetCellSanitizer;
use PHPUnit\Framework\TestCase;

final class ReportExporterTest extends TestCase
{
    public function test_csv_and_xlsx_neutralize_spreadsheet_formulas_and_preserve_integers(): void
    {
        $exporter = new ReportExporter(new SpreadsheetCellSanitizer);
        $dataset = new ReportExportDataset(
            ['label', 'value'],
            [
                ['=HYPERLINK("https://example.test")', 123],
                [" \t+SUM(1,2)", -45],
                ['normal', null],
            ],
        );

        $csv = $exporter->csv($dataset, 'safe-report');
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv->contents);
        self::assertStringContainsString("'=HYPERLINK", $csv->contents);
        self::assertStringContainsString("' \t+SUM", $csv->contents);
        self::assertStringContainsString(',123', $csv->contents);

        $xlsx = $exporter->xlsx($dataset, 'safe-report');
        self::assertStringStartsWith("PK\x03\x04", $xlsx->contents);
        self::assertStringContainsString('xl/worksheets/sheet1.xml', $xlsx->contents);
        self::assertStringContainsString("'=HYPERLINK", $xlsx->contents);
        self::assertStringContainsString('<v>123</v>', $xlsx->contents);
        self::assertSame('safe-report.xlsx', $xlsx->filename);
    }

    public function test_sensitive_report_values_are_masked_without_leaking_full_values(): void
    {
        $masker = new ReportValueMasker;
        $masked = $masker->maskRow([
            'phone_number' => '09123456789',
            'card_number' => '6037991234567890',
            'national_id' => '0012345678',
            'wallet_address' => '0x1234567890abcdef',
            'subscription_link' => 'https://secret.example/sub/abc',
            'status' => 'active',
        ]);

        self::assertSame('091******89', $masked['phone_number']);
        self::assertSame('6037********7890', $masked['card_number']);
        self::assertSame('00******78', $masked['national_id']);
        self::assertSame('0x1234********cdef', $masked['wallet_address']);
        self::assertSame('[MASKED_SUBSCRIPTION_LINK]', $masked['subscription_link']);
        self::assertSame('active', $masked['status']);

        $dataset = $masker->maskDataset(new ReportExportDataset(
            ['phone_number', 'card_number', 'status'],
            [['09123456789', '6037991234567890', 'active']],
        ));
        self::assertSame('091******89', $dataset->rows[0][0]);
        self::assertSame('6037********7890', $dataset->rows[0][1]);
        self::assertSame('active', $dataset->rows[0][2]);
    }
}
