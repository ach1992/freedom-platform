<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final class ReportValueMasker
{
    /** @var array<string, true> */
    private const SENSITIVE_FIELDS = [
        'phone' => true,
        'phone_number' => true,
        'card' => true,
        'card_number' => true,
        'national_id' => true,
        'wallet_address' => true,
        'subscription_link' => true,
    ];

    /**
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public function maskRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (! isset(self::SENSITIVE_FIELDS[$key]) || $value === null) {
                continue;
            }

            $row[$key] = $this->mask($key, (string) $value);
        }

        return $row;
    }

    public function maskDataset(ReportExportDataset $dataset): ReportExportDataset
    {
        $rows = [];
        foreach ($dataset->rows as $row) {
            $masked = [];
            foreach ($row as $index => $value) {
                $field = $dataset->headers[$index];
                $masked[] = is_string($value) && isset(self::SENSITIVE_FIELDS[$field])
                    ? $this->mask($field, $value)
                    : $value;
            }
            $rows[] = $masked;
        }

        return new ReportExportDataset($dataset->headers, $rows);
    }

    public function mask(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        return match ($field) {
            'phone', 'phone_number' => $this->keepEdges($value, 3, 2),
            'card', 'card_number' => $this->keepEdges(preg_replace('/\s+/', '', $value) ?? $value, 4, 4),
            'national_id' => $this->keepEdges($value, 2, 2),
            'wallet_address' => $this->keepEdges($value, 6, 4),
            'subscription_link' => '[MASKED_SUBSCRIPTION_LINK]',
            default => '[MASKED]',
        };
    }

    private function keepEdges(string $value, int $prefix, int $suffix): string
    {
        $length = mb_strlen($value);
        if ($length <= $prefix + $suffix) {
            return str_repeat('*', max(4, $length));
        }

        return mb_substr($value, 0, $prefix)
            .str_repeat('*', max(4, $length - $prefix - $suffix))
            .mb_substr($value, -$suffix);
    }
}
