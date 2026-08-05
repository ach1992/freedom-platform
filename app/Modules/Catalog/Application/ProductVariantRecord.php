<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

final readonly class ProductVariantRecord
{
    public function __construct(
        public int $id,
        public int $product_id,
        public string $code,
        public string $sku,
        public string $name_fa,
        public ?string $name_en,
        public ?string $description_fa,
        public ?string $description_en,
        public string $state,
        public int $sort_order,
        public int $version,
    ) {}
}
