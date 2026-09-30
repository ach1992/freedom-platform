<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use DateTimeImmutable;

final readonly class ReportSnapshot
{
    /** @param list<ReportMetric> $metrics */
    public function __construct(
        public ReportDateRange $range,
        public ?ReportDateRange $priorRange,
        public DateTimeImmutable $generatedAtUtc,
        public array $metrics,
    ) {}

    public function exportDataset(): ReportExportDataset
    {
        $rows = [];
        foreach ($this->metrics as $metric) {
            $rows[] = [
                $metric->section,
                $metric->code,
                $metric->label,
                $metric->dimension,
                $metric->value,
                $metric->priorValue,
                $metric->priorValue === null ? null : $metric->value - $metric->priorValue,
                $metric->unit,
            ];
        }

        return new ReportExportDataset(
            ['section', 'metric', 'label', 'dimension', 'current', 'prior', 'delta', 'unit'],
            $rows,
        );
    }

    public function fingerprint(): string
    {
        $payload = [
            'range' => [
                'code' => $this->range->code,
                'start' => $this->range->databaseStart(),
                'end_exclusive' => $this->range->databaseEndExclusive(),
            ],
            'prior' => $this->priorRange === null ? null : [
                'start' => $this->priorRange->databaseStart(),
                'end_exclusive' => $this->priorRange->databaseEndExclusive(),
            ],
            'metrics' => array_map(
                static fn (ReportMetric $metric): array => [
                    $metric->section,
                    $metric->code,
                    $metric->dimension,
                    $metric->value,
                    $metric->priorValue,
                    $metric->unit,
                ],
                $this->metrics,
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
