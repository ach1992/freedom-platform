<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\Contracts\SmsOtpMessageRenderer;
use App\Modules\Identity\Application\SmsOtpMessage;
use Illuminate\Contracts\Translation\Translator;
use RuntimeException;

final readonly class LocalizedSmsOtpMessageRenderer implements SmsOtpMessageRenderer
{
    public function __construct(private Translator $translator) {}

    public function render(SmsOtpMessage $message): string
    {
        $rendered = $this->translator->get(
            'identity.otp.sms_message',
            ['code' => $message->code],
            $message->locale,
        );

        if (! is_string($rendered)) {
            throw new RuntimeException('The OTP SMS translation is invalid.');
        }

        $rendered = str_replace(["\r\n", "\r"], "\n", trim($rendered));

        if ($rendered === ''
            || mb_strlen($rendered) > 480
            || substr_count($rendered, $message->code) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $rendered) === 1
        ) {
            throw new RuntimeException('The OTP SMS translation is invalid.');
        }

        return $rendered;
    }
}
