<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application;

final readonly class SalesServerRecord
{
    public function __construct(
        public int $id,
        public string $code,
        public string $nameFa,
        public ?string $nameEn,
        public ?string $descriptionFa,
        public ?string $descriptionEn,
        public string $state,
        public string $visibility,
        public int $sortOrder,
        public int $version,
    ) {}
}
