<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use DomainException;

final readonly class ReportExportDataset
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<int|string|null>>  $rows
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {
        if ($headers === [] || count($headers) > 256) {
            throw new DomainException('Report export requires 1-256 columns.');
        }
        if (count($rows) > 100_000) {
            throw new DomainException('Report export exceeds the row safety bound.');
        }

        $columnCount = count($headers);
        foreach ($headers as $header) {
            if (trim($header) === '') {
                throw new DomainException('Report export headers cannot be empty.');
            }
        }
        foreach ($rows as $row) {
            if (count($row) !== $columnCount) {
                throw new DomainException('Report export rows must match the header width.');
            }
        }
    }
}
