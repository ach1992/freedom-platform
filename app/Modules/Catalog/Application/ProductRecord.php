<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class ProductRecord
{
    public function __construct(
        public int $id,
        public int $category_id,
        public string $code,
        public string $name_fa,
        public ?string $name_en,
        public ?string $description_fa,
        public ?string $description_en,
        public string $state,
        public string $visibility,
        public int $sort_order,
        public int $version,
    ) {}
}
