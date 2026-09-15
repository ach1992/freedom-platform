<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;

final readonly class TelegramCustomerTrialProtocolOption
{
    public function __construct(
        public string $selectionToken,
        public string $nameFa,
        public ?string $nameEn,
    ) {
        if (preg_match('/\A[0-9a-f]{40}\z/', $selectionToken) !== 1) {
            throw new InvalidArgumentException('Telegram Trial protocol selection token is invalid.');
        }
        $this->assertLabel($nameFa, 'Telegram Trial protocol Persian label');
        if ($nameEn !== null) {
            $this->assertLabel($nameEn, 'Telegram Trial protocol English label');
        }
    }

    private function assertLabel(string $value, string $label): void
    {
        if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
