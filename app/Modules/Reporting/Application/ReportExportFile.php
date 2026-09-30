<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final readonly class ReportExportFile
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $contents,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->contents);
    }
}
