<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

final readonly class TelegramClientGuideDefinition
{
    public function __construct(
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
    ) {}
}
