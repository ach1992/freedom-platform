<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use RuntimeException;

final readonly class TelegramPaymentPrivateEvidenceStatusDelivery
{
    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
    ) {}

    public function queue(int $telegramUserId, string $requestKey, string $locale, string $status): void
    {
        if ($telegramUserId < 1
            || $requestKey === ''
            || ! in_array($status, ['received', 'invalid', 'unavailable'], true)) {
            throw new RuntimeException('Telegram payment private-evidence status delivery identity is invalid.');
        }

        $text = $this->translation('telegram.private_payment_evidence.'.$status, $locale);
        $presentation = $this->presentations->fromSource(
            new TelegramPaymentPrivateEvidenceStatusPresentation($text),
        );
        $key = 'telegram-payment-private-evidence-status:'.$status.':'.$requestKey;
        $this->delivery->send(
            $telegramUserId,
            $presentation,
            $key,
            hash('sha256', $key),
        );
    }

    private function translation(string $key, string $locale): string
    {
        $text = $this->localization->resolve($key, [], $locale);
        if ($text === '' || $text === '['.$key.']') {
            throw new RuntimeException('Telegram payment private-evidence translation is unavailable.');
        }

        return $text;
    }
}
