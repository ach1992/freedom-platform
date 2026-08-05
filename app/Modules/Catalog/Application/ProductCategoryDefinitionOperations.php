<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogCode;
use App\Modules\Catalog\Domain\CatalogState;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductCategoryDefinitionOperations
{
    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        string $code,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        ?int $parentId,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        $normalizedCode = CatalogCode::fromInput($code)->value;
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedParentId = CatalogInput::positiveId($parentId, 'Category parent ID', true);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'code' => $normalizedCode,
            'content' => $content,
            'parent_id' => $normalizedParentId,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.category.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $normalizedCode,
                $content,
                $normalizedParentId,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $this->assertParentChain($connection, $normalizedParentId, null, false);

                if ($connection->table('product_categories')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Category code already exists.');
                }

                $now = $this->timestamp();
                $categoryId = (int) $connection->table('product_categories')->insertGetId([
                    'parent_id' => $normalizedParentId,
                    'code' => $normalizedCode,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'state' => CatalogState::Draft->value,
                    'sort_order' => $normalizedSortOrder,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $after = $this->safeState(
                    $categoryId,
                    $normalizedCode,
                    $normalizedParentId,
                    CatalogState::Draft,
                    $normalizedSortOrder,
                    1,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $categoryId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $categoryId,
                    $context,
                    ['request_payload_hash' => $payloadHash],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function update(
        int $categoryId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        ?int $parentId,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedParentId = CatalogInput::positiveId($parentId, 'Category parent ID', true);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'category_id' => $categoryId,
            'expected_version' => $normalizedExpectedVersion,
            'content' => $content,
            'parent_id' => $normalizedParentId,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.category.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $categoryId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $categoryId,
                $normalizedExpectedVersion,
                $content,
                $normalizedParentId,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $category = $this->lockedCategory($connection, $categoryId);
                $this->assertVersion((int) $category->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $category->state);
                if ($state === CatalogState::Archived) {
                    throw new DomainException('Archived categories are immutable.');
                }

                $this->assertParentChain(
                    $connection,
                    $normalizedParentId,
                    $categoryId,
                    $state === CatalogState::Active,
                );

                $beforeContentHash = $this->contentHash($category);
                $before = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $category->parent_id === null ? null : (int) $category->parent_id,
                    $state,
                    (int) $category->sort_order,
                    (int) $category->version,
                    $beforeContentHash,
                    $payloadHash,
                );
                $changed = $beforeContentHash !== $content['content_hash']
                    || ($category->parent_id === null ? null : (int) $category->parent_id) !== $normalizedParentId
                    || (int) $category->sort_order !== $normalizedSortOrder;

                if (! $changed) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::TARGET_TYPE,
                        $categoryId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = (int) $category->version + 1;
                $connection->table('product_categories')->where('id', $categoryId)->update([
                    'parent_id' => $normalizedParentId,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'sort_order' => $normalizedSortOrder,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);

                $after = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $normalizedParentId,
                    $state,
                    $normalizedSortOrder,
                    $nextVersion,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $categoryId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $categoryId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }
}
