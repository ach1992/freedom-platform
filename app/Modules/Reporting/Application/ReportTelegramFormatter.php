<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\IrrTomanFormatter;

final class ReportTelegramFormatter
{
    private const MAX_CHARACTERS = 3900;

    private const MAX_METRICS = 42;

    public function format(ReportSnapshot $snapshot): string
    {
        $lines = [
            '📊 Report — '.$snapshot->range->code,
            'UTC: '.($snapshot->range->databaseStart() ?? 'all-time').' → '.$snapshot->range->databaseEndExclusive(),
            'Generated: '.$snapshot->generatedAtUtc->format('Y-m-d H:i:s').' UTC',
            '',
        ];

        $rendered = 0;
        $currentSection = null;
        foreach ($snapshot->metrics as $metric) {
            if ($rendered >= self::MAX_METRICS) {
                break;
            }
            if ($currentSection !== $metric->section) {
                if ($currentSection !== null) {
                    $lines[] = '';
                }
                $currentSection = $metric->section;
                $lines[] = strtoupper($currentSection);
            }

            $dimension = $metric->dimension === null ? '' : ' ['.$metric->dimension.']';
            $value = $this->formatValue($metric->value, $metric->unit);
            $comparison = $metric->priorValue === null
                ? ''
                : ' | prior '.$this->formatValue($metric->priorValue, $metric->unit)
                    .' | Δ '.$this->signed($metric->value - $metric->priorValue, $metric->unit);
            $candidate = '• '.$metric->label.$dimension.': '.$value.$comparison;

            if (mb_strlen(implode("\n", [...$lines, $candidate])) > self::MAX_CHARACTERS - 120) {
                break;
            }
            $lines[] = $candidate;
            $rendered++;
        }

        if ($rendered < count($snapshot->metrics)) {
            $lines[] = '';
            $lines[] = '… '.(count($snapshot->metrics) - $rendered).' additional metrics omitted; use CSV/XLSX export.';
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_CHARACTERS);
    }

    private function formatValue(int $value, string $unit): string
    {
        if ($unit === 'IRR') {
            return IrrTomanFormatter::format($value).' Toman';
        }

        return number_format($value, 0, '.', ',').' '.$unit;
    }

    private function signed(int $value, string $unit): string
    {
        return ($value >= 0 ? '+' : '').$this->formatValue($value, $unit);
    }
}
