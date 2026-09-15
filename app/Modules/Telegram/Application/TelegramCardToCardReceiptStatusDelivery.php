<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use RuntimeException;

final readonly class TelegramCardToCardReceiptStatusDelivery
{
    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
    ) {}

    public function queue(int $telegramUserId, string $requestKey, string $locale, string $status): void
    {
        if ($telegramUserId < 1 || $requestKey === '' || ! in_array($status, ['received', 'invalid', 'unavailable'], true)) {
            throw new RuntimeException('Telegram card-to-card receipt status delivery identity is invalid.');
        }

        $text = $this->translation('telegram_c2c_receipt.'.$status, $locale);
        $presentation = $this->presentations->fromSource(
            new TelegramCardToCardReceiptStatusPresentation($text),
        );
        $this->delivery->send(
            $telegramUserId,
            $presentation,
            'telegram-c2c-receipt-status:'.$status.':'.$requestKey,
            hash('sha256', 'telegram-c2c-receipt-status:'.$status.':'.$requestKey),
        );
    }

    private function translation(string $key, string $locale): string
    {
        $text = $this->localization->resolve($key, [], $locale);
        if ($text === '' || $text === '['.$key.']') {
            throw new RuntimeException('Telegram card-to-card receipt translation is unavailable.');
        }

        return $text;
    }
}
