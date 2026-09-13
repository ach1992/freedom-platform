<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialFallbackDisclosure
{
    public function __construct(
        public string $serverNameFa,
        public ?string $serverNameEn,
        public string $disclosureFa,
        public ?string $disclosureEn,
    ) {
        foreach ([$serverNameFa, $disclosureFa] as $value) {
            if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Telegram Trial fallback disclosure is invalid.');
            }
        }
        foreach ([$serverNameEn, $disclosureEn] as $value) {
            if ($value !== null && ($value === '' || ! mb_check_encoding($value, 'UTF-8'))) {
                throw new InvalidArgumentException('Telegram Trial fallback optional disclosure is invalid.');
            }
        }
    }
}
