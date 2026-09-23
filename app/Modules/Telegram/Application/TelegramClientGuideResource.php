<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramClientGuideResource
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $code,
        public string $titleFa,
        public ?string $titleEn,
        public ?string $descriptionFa,
        public ?string $descriptionEn,
        public string $platform,
        public string $language,
        public string $audience,
        public ?string $tierCode,
        public ?string $customerTagCode,
        public string $resourceUrl,
        public ?string $tutorialFa,
        public ?string $tutorialEn,
        public ?string $normalEmoji,
        public ?string $premiumEmojiId,
        public int $sortOrder,
        public string $state,
        public int $version,
        public string $lastValidatedAt,
        public int $lastValidatedByAdministratorId,
    ) {}

    public function title(string $locale): string
    {
        return $locale === 'en' && $this->titleEn !== null ? $this->titleEn : $this->titleFa;
    }

    public function description(string $locale): ?string
    {
        return $locale === 'en' && $this->descriptionEn !== null ? $this->descriptionEn : $this->descriptionFa;
    }

    public function tutorial(string $locale): ?string
    {
        return $locale === 'en' && $this->tutorialEn !== null ? $this->tutorialEn : $this->tutorialFa;
    }
}
