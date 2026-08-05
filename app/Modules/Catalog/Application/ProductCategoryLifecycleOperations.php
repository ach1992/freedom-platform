<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use Illuminate\Database\Connection;

trait ProductCategoryLifecycleOperations
{
    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function activate(int $categoryId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($categoryId, $expectedVersion, CatalogState::Active, 'catalog.category.activate', $context);
    }

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function archive(int $categoryId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($categoryId, $expectedVersion, CatalogState::Archived, 'catalog.category.archive', $context);
    }

    private function transition(
        int $categoryId,
        int $expectedVersion,
        CatalogState $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'category_id' => $categoryId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

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
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $category = $this->lockedCategory($connection, $categoryId);
                $this->assertVersion((int) $category->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $category->state);
                $state->assertCanTransitionTo($target);
                $parentId = $category->parent_id === null ? null : (int) $category->parent_id;

                if ($target === CatalogState::Active) {
                    $this->assertParentChain($connection, $parentId, $categoryId, true);
                } else {
                    $this->assertArchiveDependencies($connection, $categoryId);
                }

                $contentHash = $this->contentHash($category);
                $before = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $parentId,
                    $state,
                    (int) $category->sort_order,
                    (int) $category->version,
                    $contentHash,
                    $payloadHash,
                );
                $nextVersion = (int) $category->version + 1;
                $connection->table('product_categories')->where('id', $categoryId)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $parentId,
                    $target,
                    (int) $category->sort_order,
                    $nextVersion,
                    $contentHash,
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
