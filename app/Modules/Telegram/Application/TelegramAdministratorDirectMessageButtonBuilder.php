<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class TelegramAdministratorDirectMessageButtonBuilder
{
    private const MAXIMUM_INPUT_BYTES = 4096;

    private const MAXIMUM_BUTTONS = 8;

    /** @requirement COM-001 SEC-002 SEC-003 QUA-001 QUA-004 */
    public function fromAdministratorInput(
        #[SensitiveParameter] string $input,
    ): TelegramInlineKeyboardSnapshot {
        if ($input === ''
            || strlen($input) > self::MAXIMUM_INPUT_BYTES
            || ! mb_check_encoding($input, 'UTF-8')
            || str_contains($input, "\0")) {
            throw new DomainException('Telegram administrator direct-message button input is invalid.');
        }

        $lines = preg_split('/\R/u', $input);
        if (! is_array($lines)) {
            throw new DomainException('Telegram administrator direct-message button input is invalid.');
        }

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (count($rows) >= self::MAXIMUM_BUTTONS || substr_count($line, '|') !== 1) {
                throw new DomainException('Telegram administrator direct-message button input is invalid.');
            }

            [$label, $url] = array_map('trim', explode('|', $line, 2));
            if ($label === '' || $url === '') {
                throw new DomainException('Telegram administrator direct-message button input is invalid.');
            }

            try {
                $button = new TelegramInlineHttpsUrlButton(
                    $label,
                    $url,
                    TelegramInlineHttpsUrlPurpose::SupportContact,
                );
            } catch (InvalidArgumentException) {
                throw new DomainException(
                    'Telegram administrator direct-message button must use an approved Telegram contact URL.',
                );
            }

            $rows[] = [$button];
        }

        if ($rows === []) {
            throw new DomainException('Telegram administrator direct-message button input is empty.');
        }

        return new TelegramInlineKeyboardSnapshot($rows);
    }
}
