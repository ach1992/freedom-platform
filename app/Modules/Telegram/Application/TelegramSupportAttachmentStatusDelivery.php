<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class TelegramSupportAttachmentStatusDelivery
{
    public function __construct(
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
    ) {}

    public function queue(
        int $telegramUserId,
        string $requestKey,
        string $locale,
        string $status,
        ?string $attachmentPublicId = null,
    ): void {
        if ($telegramUserId < 1
            || $requestKey === ''
            || ! in_array($status, ['received', 'invalid', 'unavailable'], true)
            || ($status === 'received' && ($attachmentPublicId === null || ! Str::isUlid($attachmentPublicId)))
            || ($status !== 'received' && $attachmentPublicId !== null)) {
            throw new RuntimeException('Telegram Support attachment status delivery identity is invalid.');
        }

        $text = $this->translation(
            'telegram_support.attachment_'.$status,
            $locale,
            $attachmentPublicId === null ? [] : ['attachment' => strtoupper($attachmentPublicId)],
        );
        $presentation = $this->presentations->fromSource(
            new TelegramSupportAttachmentStatusPresentation($text),
        );
        $key = 'telegram-support-attachment-status:'.$status.':'.$requestKey;
        $this->delivery->send(
            $telegramUserId,
            $presentation,
            $key,
            hash('sha256', $key),
        );
    }

    /** @param array<string,string|int> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->localization->resolve($key, $replace, $locale);
        if ($text === '' || $text === '['.$key.']') {
            throw new RuntimeException('Telegram Support attachment translation is unavailable.');
        }

        return $text;
    }
}
