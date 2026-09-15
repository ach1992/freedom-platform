<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogText;
use InvalidArgumentException;

final class CatalogInput
{
    /** @return array{name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string, content_hash: string} */
    public static function localized(
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
    ): array {
        $content = [
            'name_fa' => CatalogText::requiredName($nameFa),
            'name_en' => CatalogText::optionalName($nameEn),
            'description_fa' => CatalogText::optionalDescription($descriptionFa),
            'description_en' => CatalogText::optionalDescription($descriptionEn),
        ];

        return [
            ...$content,
            'content_hash' => CatalogPayloadHash::make($content),
        ];
    }

    public static function sortOrder(int $sortOrder): int
    {
        if ($sortOrder < 0 || $sortOrder > 4_294_967_295) {
            throw new InvalidArgumentException('Catalog sort order is invalid.');
        }

        return $sortOrder;
    }

    public static function expectedVersion(int $expectedVersion): int
    {
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('Catalog expected version is invalid.');
        }

        return $expectedVersion;
    }

    public static function positiveId(?int $id, string $label, bool $nullable = false): ?int
    {
        if ($id === null && $nullable) {
            return null;
        }

        if ($id === null || $id < 1) {
            throw new InvalidArgumentException($label.' must be positive.');
        }

        return $id;
    }
}
