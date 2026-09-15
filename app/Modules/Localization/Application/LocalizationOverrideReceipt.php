<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application;

final readonly class LocalizationOverrideReceipt
{
    public function __construct(
        public string $action,
        public int $overrideId,
        public string $translationKey,
        public string $locale,
        public int $version,
        public bool $changed,
        public bool $replayed = false,
    ) {}
}
