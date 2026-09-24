<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class TelegramPaymentPrivateEvidenceStatusPresentation implements ConfidentialTelegramPresentationSource
{
    public function __construct(#[SensitiveParameter] private string $text)
    {
        if ($text === '' || mb_strlen($text) > 4096) {
            throw new InvalidArgumentException('Telegram payment private-evidence status text is invalid.');
        }
    }

    public function confidentialTelegramText(): string
    {
        return $this->text;
    }
}
