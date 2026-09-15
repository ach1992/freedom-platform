<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductCategoryServiceSupport
{
    private function lockedCategory(Connection $connection, int $categoryId): ProductCategoryRecord
    {
        /** @var object{id: int|string, parent_id: int|string|null, code: string, name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string, state: string, sort_order: int|string, version: int|string}|null $category */
        $category = $connection->table('product_categories')
            ->where('id', $categoryId)
            ->lockForUpdate()
            ->first([
                'id', 'parent_id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
                'state', 'sort_order', 'version',
            ]);

        if ($category === null) {
            throw new RuntimeException('Category does not exist.');
        }

        return new ProductCategoryRecord(
            (int) $category->id,
            $category->parent_id === null ? null : (int) $category->parent_id,
            $category->code,
            $category->name_fa,
            $category->name_en,
            $category->description_fa,
            $category->description_en,
            $category->state,
            (int) $category->sort_order,
            (int) $category->version,
        );
    }

    private function assertParentChain(
        Connection $connection,
        ?int $parentId,
        ?int $movingCategoryId,
        bool $requireActive,
    ): void {
        $seen = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if ($movingCategoryId !== null && $currentId === $movingCategoryId) {
                throw new DomainException('Category hierarchy cycle is not allowed.');
            }
            if (isset($seen[$currentId])) {
                throw new RuntimeException('Stored category hierarchy is invalid.');
            }
            $seen[$currentId] = true;

            /** @var object{id: int|string, parent_id: int|string|null, state: string}|null $parent */
            $parent = $connection->table('product_categories')
                ->where('id', $currentId)
                ->lockForUpdate()
                ->first(['id', 'parent_id', 'state']);
            if ($parent === null) {
                throw new RuntimeException('Category parent does not exist.');
            }

            $parentState = $this->storedState($parent->state);
            if ($parentState === CatalogState::Archived || ($requireActive && $parentState !== CatalogState::Active)) {
                throw new DomainException('Category parent state does not allow this operation.');
            }

            $currentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }
    }

    private function assertArchiveDependencies(Connection $connection, int $categoryId): void
    {
        $child = $connection->table('product_categories')
            ->where('parent_id', $categoryId)
            ->where('state', '<>', CatalogState::Archived->value)
            ->lockForUpdate()
            ->first(['id']);
        if ($child !== null) {
            throw new DomainException('Category with non-archived child categories cannot be archived.');
        }

        $product = $connection->table('products')
            ->where('category_id', $categoryId)
            ->where('state', '<>', CatalogState::Archived->value)
            ->lockForUpdate()
            ->first(['id']);
        if ($product !== null) {
            throw new DomainException('Category with non-archived products cannot be archived.');
        }
    }

    private function assertVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw new RuntimeException('Catalog version conflict.');
        }
    }

    private function storedState(string $state): CatalogState
    {
        return CatalogState::tryFrom($state) ?? throw new RuntimeException('Stored category state is invalid.');
    }

    private function contentHash(ProductCategoryRecord $category): string
    {
        return CatalogPayloadHash::make([
            'name_fa' => $category->name_fa,
            'name_en' => $category->name_en,
            'description_fa' => $category->description_fa,
            'description_en' => $category->description_en,
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $categoryId,
        string $code,
        ?int $parentId,
        CatalogState $state,
        int $sortOrder,
        int $version,
        string $contentHash,
        string $payloadHash,
    ): array {
        return [
            'request_payload_hash' => $payloadHash,
            'category_id' => $categoryId,
            'code' => $code,
            'parent_id' => $parentId,
            'state' => $state->value,
            'sort_order' => $sortOrder,
            'version' => $version,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>|null  $before
     * @param  array<string, bool|int|string|null>  $after
     */
    private function history(
        Connection $connection,
        int $categoryId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        CatalogChangeContext $context,
    ): void {
        $connection->table('product_category_histories')->insert([
            'category_id' => $categoryId,
            'version' => $version,
            'action' => $action,
            'from_state' => $before['state'] ?? null,
            'to_state' => $after['state'],
            'from_parent_id' => $before['parent_id'] ?? null,
            'to_parent_id' => $after['parent_id'],
            'from_sort_order' => $before['sort_order'] ?? null,
            'to_sort_order' => $after['sort_order'],
            'from_content_hash' => $before['content_hash'] ?? null,
            'to_content_hash' => $after['content_hash'],
            'before_safe_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
