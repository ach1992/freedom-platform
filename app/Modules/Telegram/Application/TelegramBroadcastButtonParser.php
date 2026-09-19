<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;

final readonly class TelegramBroadcastButtonParser
{
    /**
     * One button per line: purpose | label | https://...
     * Send "none" to remove the authored keyboard.
     *
     * @requirement COM-002 COM-003 SEC-002 SEC-008 QUA-001
     */
    public function parse(string $input): ?TelegramInlineKeyboardSnapshot
    {
        $input = trim($input);
        if (strcasecmp($input, 'none') === 0) {
            return null;
        }
        if ($input === '' || strlen($input) > 16_384 || ! mb_check_encoding($input, 'UTF-8')) {
            throw new DomainException('Broadcast button input is invalid.');
        }

        $rows = [];
        foreach (preg_split('/\R/u', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 3));
            if (count($parts) !== 3 || in_array('', $parts, true)) {
                throw new DomainException('Broadcast buttons must use purpose | label | https://url.');
            }

            [$purpose, $label, $url] = $parts;
            $rows[] = [new TelegramInlineHttpsUrlButton(
                $label,
                $url,
                $this->purpose(strtolower($purpose)),
            )];
        }

        if ($rows === []) {
            throw new DomainException('Broadcast button input contains no buttons.');
        }
        if (count($rows) > 8) {
            throw new DomainException('Broadcast button input exceeds 8 rows.');
        }

        return new TelegramInlineKeyboardSnapshot($rows);
    }

    private function purpose(string $purpose): TelegramInlineHttpsUrlPurpose
    {
        return match ($purpose) {
            'zarinpal_start_pay' => TelegramInlineHttpsUrlPurpose::ZarinpalStartPay,
            'support_contact' => TelegramInlineHttpsUrlPurpose::SupportContact,
            default => throw new DomainException('Broadcast button purpose is not supported: '.$purpose),
        };
    }
}
