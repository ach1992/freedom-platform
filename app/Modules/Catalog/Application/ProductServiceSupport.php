<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductServiceSupport
{
    private function lockedProduct(Connection $connection, int $productId): ProductRecord
    {
        /** @var object{id: int|string, category_id: int|string, code: string, name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string, state: string, visibility: string, sort_order: int|string, version: int|string}|null $product */
        $product = $connection->table('products')
            ->where('id', $productId)
            ->lockForUpdate()
            ->first([
                'id', 'category_id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
                'state', 'visibility', 'sort_order', 'version',
            ]);
        if ($product === null) {
            throw new RuntimeException('Product does not exist.');
        }

        return new ProductRecord(
            (int) $product->id,
            (int) $product->category_id,
            $product->code,
            $product->name_fa,
            $product->name_en,
            $product->description_fa,
            $product->description_en,
            $product->state,
            $product->visibility,
            (int) $product->sort_order,
            (int) $product->version,
        );
    }

    private function lockedCategory(Connection $connection, int $categoryId, bool $requireActive): void
    {
        /** @var object{id: int|string, state: string}|null $category */
        $category = $connection->table('product_categories')
            ->where('id', $categoryId)
            ->lockForUpdate()
            ->first(['id', 'state']);
        if ($category === null) {
            throw new RuntimeException('Category does not exist.');
        }

        $state = $this->storedState($category->state);
        if ($state === CatalogState::Archived || ($requireActive && $state !== CatalogState::Active)) {
            throw new DomainException('Category state does not allow this product operation.');
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
        return CatalogState::tryFrom($state) ?? throw new RuntimeException('Stored catalog state is invalid.');
    }

    private function storedVisibility(string $visibility): ProductVisibility
    {
        return ProductVisibility::tryFrom($visibility) ?? throw new RuntimeException('Stored product visibility is invalid.');
    }

    private function contentHash(ProductRecord $product): string
    {
        return CatalogPayloadHash::make([
            'name_fa' => $product->name_fa,
            'name_en' => $product->name_en,
            'description_fa' => $product->description_fa,
            'description_en' => $product->description_en,
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $productId,
        string $code,
        int $categoryId,
        CatalogState $state,
        ProductVisibility $visibility,
        int $sortOrder,
        int $version,
        string $contentHash,
        string $payloadHash,
    ): array {
        return [
            'request_payload_hash' => $payloadHash,
            'product_id' => $productId,
            'code' => $code,
            'category_id' => $categoryId,
            'state' => $state->value,
            'visibility' => $visibility->value,
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
        int $productId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        CatalogChangeContext $context,
    ): void {
        $connection->table('product_histories')->insert([
            'product_id' => $productId,
            'version' => $version,
            'action' => $action,
            'from_state' => $before['state'] ?? null,
            'to_state' => $after['state'],
            'from_visibility' => $before['visibility'] ?? null,
            'to_visibility' => $after['visibility'],
            'from_category_id' => $before['category_id'] ?? null,
            'to_category_id' => $after['category_id'],
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
