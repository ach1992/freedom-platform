<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogCode;
use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductDefinitionOperations
{
    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        int $categoryId,
        string $code,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedCode = CatalogCode::fromInput($code)->value;
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'category_id' => $categoryId,
            'code' => $normalizedCode,
            'content' => $content,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.product.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $categoryId,
                $normalizedCode,
                $content,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $this->lockedCategory($connection, $categoryId, false);

                if ($connection->table('products')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Product code already exists.');
                }

                $now = $this->timestamp();
                $productId = (int) $connection->table('products')->insertGetId([
                    'category_id' => $categoryId,
                    'code' => $normalizedCode,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'state' => CatalogState::Draft->value,
                    'visibility' => ProductVisibility::Hidden->value,
                    'sort_order' => $normalizedSortOrder,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $after = $this->safeState(
                    $productId,
                    $normalizedCode,
                    $categoryId,
                    CatalogState::Draft,
                    ProductVisibility::Hidden,
                    $normalizedSortOrder,
                    1,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $productId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $productId,
                    $context,
                    ['request_payload_hash' => $payloadHash],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function update(
        int $productId,
        int $expectedVersion,
        int $categoryId,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($productId, 'Product ID');
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'product_id' => $productId,
            'expected_version' => $normalizedExpectedVersion,
            'category_id' => $categoryId,
            'content' => $content,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.product.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $productId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $productId,
                $normalizedExpectedVersion,
                $categoryId,
                $content,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $product = $this->lockedProduct($connection, $productId);
                $this->assertVersion((int) $product->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $product->state);
                $visibility = $this->storedVisibility((string) $product->visibility);
                if ($state === CatalogState::Archived) {
                    throw new DomainException('Archived products are immutable.');
                }

                $currentCategoryId = (int) $product->category_id;
                if ($categoryId !== $currentCategoryId
                    && ($state !== CatalogState::Draft || $visibility !== ProductVisibility::Hidden)
                ) {
                    throw new DomainException('Only hidden draft products can change category.');
                }

                $this->lockedCategory($connection, $categoryId, $state === CatalogState::Active);
                $beforeContentHash = $this->contentHash($product);
                $before = $this->safeState(
                    $productId,
                    (string) $product->code,
                    $currentCategoryId,
                    $state,
                    $visibility,
                    (int) $product->sort_order,
                    (int) $product->version,
                    $beforeContentHash,
                    $payloadHash,
                );
                $changed = $currentCategoryId !== $categoryId
                    || $beforeContentHash !== $content['content_hash']
                    || (int) $product->sort_order !== $normalizedSortOrder;

                if (! $changed) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::TARGET_TYPE,
                        $productId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = (int) $product->version + 1;
                $connection->table('products')->where('id', $productId)->update([
                    'category_id' => $categoryId,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'sort_order' => $normalizedSortOrder,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $productId,
                    (string) $product->code,
                    $categoryId,
                    $state,
                    $visibility,
                    $normalizedSortOrder,
                    $nextVersion,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $productId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $productId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }
}
