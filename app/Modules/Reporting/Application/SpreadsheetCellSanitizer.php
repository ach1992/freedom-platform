<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final class SpreadsheetCellSanitizer
{
    public function sanitize(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $candidate = ltrim($value, " \t\r\n");
        if ($candidate !== '' && str_contains('=+-@', $candidate[0])) {
            return "'".$value;
        }

        return $value;
    }
}
