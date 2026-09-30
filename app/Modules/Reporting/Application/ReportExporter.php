<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use RuntimeException;

final readonly class ReportExporter
{
    public function __construct(private SpreadsheetCellSanitizer $sanitizer) {}

    public function csv(ReportExportDataset $dataset, string $basename = 'report'): ReportExportFile
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to allocate report export buffer.');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $this->sanitizeRow($dataset->headers), ',', '"', '');
            foreach ($dataset->rows as $row) {
                fputcsv($stream, $this->sanitizeRow($row), ',', '"', '');
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            if (! is_string($contents)) {
                throw new RuntimeException('Unable to read report export buffer.');
            }
        } finally {
            fclose($stream);
        }

        return new ReportExportFile($this->basename($basename).'.csv', 'text/csv; charset=UTF-8', $contents);
    }

    public function xlsx(ReportExportDataset $dataset, string $basename = 'report'): ReportExportFile
    {
        $zip = new StoredZipWriter;
        $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->add('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->add('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');
        $zip->add('xl/worksheets/sheet1.xml', $this->worksheetXml($dataset));

        return new ReportExportFile(
            $this->basename($basename).'.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $zip->finish(),
        );
    }

    /**
     * Sanitize one export row.
     *
     * @param  list<int|string|null>  $row
     * @return list<int|string|null>
     */
    private function sanitizeRow(array $row): array
    {
        return array_map(
            fn (int|string|null $value): int|string|null => is_string($value) ? $this->sanitizer->sanitize($value) : $value,
            $row,
        );
    }

    private function worksheetXml(ReportExportDataset $dataset): string
    {
        $rows = [$dataset->headers, ...$dataset->rows];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach ($rows as $rowIndex => $row) {
            $excelRow = $rowIndex + 1;
            $xml .= '<row r="'.$excelRow.'">';
            foreach ($this->sanitizeRow($row) as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex + 1).$excelRow;
                if (is_int($value)) {
                    $xml .= '<c r="'.$reference.'"><v>'.$value.'</v></c>';
                } elseif ($value === null) {
                    $xml .= '<c r="'.$reference.'"/>';
                } else {
                    $escaped = htmlspecialchars($value, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
                    $xml .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$escaped.'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function basename(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\A[a-zA-Z0-9._-]{1,80}\z/', $value) !== 1) {
            throw new RuntimeException('Report export basename is invalid.');
        }

        return $value;
    }
}
