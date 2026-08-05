<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductVariantServiceSupport
{
    private function lockedVariant(Connection $connection, int $variantId): object
    {
        $variant = $connection->table('product_variants')
            ->where('id', $variantId)
            ->lockForUpdate()
            ->first([
                'id', 'product_id', 'code', 'sku', 'name_fa', 'name_en', 'description_fa',
                'description_en', 'state', 'sort_order', 'version',
            ]);
        if ($variant === null) {
            throw new RuntimeException('Variant does not exist.');
        }

        return $variant;
    }

    private function lockedProduct(Connection $connection, int $productId, bool $requireActive): object
    {
        /** @var object{id: int|string, category_id: int|string, state: string}|null $product */
        $product = $connection->table('products')
            ->where('id', $productId)
            ->lockForUpdate()
            ->first(['id', 'category_id', 'state']);
        if ($product === null) {
            throw new RuntimeException('Product does not exist.');
        }

        $state = $this->storedState($product->state);
        if ($state === CatalogState::Archived || ($requireActive && $state !== CatalogState::Active)) {
            throw new DomainException('Product state does not allow this variant operation.');
        }

        if ($requireActive) {
            /** @var object{state: string}|null $category */
            $category = $connection->table('product_categories')
                ->where('id', (int) $product->category_id)
                ->lockForUpdate()
                ->first(['state']);
            if ($category === null || $this->storedState($category->state) !== CatalogState::Active) {
                throw new DomainException('Product category must be active before variant activation.');
            }
        }

        return $product;
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

    /** @param object{name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string} $variant */
    private function contentHash(object $variant): string
    {
        return CatalogPayloadHash::make([
            'name_fa' => $variant->name_fa,
            'name_en' => $variant->name_en,
            'description_fa' => $variant->description_fa,
            'description_en' => $variant->description_en,
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $variantId,
        int $productId,
        string $code,
        string $sku,
        CatalogState $state,
        int $sortOrder,
        int $version,
        string $contentHash,
        string $payloadHash,
    ): array {
        return [
            'request_payload_hash' => $payloadHash,
            'variant_id' => $variantId,
            'product_id' => $productId,
            'code' => $code,
            'sku' => $sku,
            'state' => $state->value,
            'sort_order' => $sortOrder,
            'version' => $version,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param array<string, bool|int|string|null>|null $before
     * @param array<string, bool|int|string|null> $after
     */
    private function history(
        Connection $connection,
        int $variantId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        CatalogChangeContext $context,
    ): void {
        $connection->table('product_variant_histories')->insert([
            'variant_id' => $variantId,
            'version' => $version,
            'action' => $action,
            'from_state' => $before['state'] ?? null,
            'to_state' => $after['state'],
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
