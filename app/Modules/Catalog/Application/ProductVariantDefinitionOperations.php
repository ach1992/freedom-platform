<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogCode;
use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\ProductSku;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ProductVariantDefinitionOperations
{
    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        int $productId,
        string $code,
        string $sku,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($productId, 'Product ID');
        $normalizedCode = CatalogCode::fromInput($code)->value;
        $normalizedSku = ProductSku::fromInput($sku)->value;
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'product_id' => $productId,
            'code' => $normalizedCode,
            'sku' => $normalizedSku,
            'content' => $content,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.variant.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $productId,
                $normalizedCode,
                $normalizedSku,
                $content,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $this->lockedProduct($connection, $productId, false);

                $skuExists = $connection->table('product_variants')
                    ->where('sku', $normalizedSku)
                    ->lockForUpdate()
                    ->exists();
                $codeExists = $connection->table('product_variants')
                    ->where('product_id', $productId)
                    ->where('code', $normalizedCode)
                    ->lockForUpdate()
                    ->exists();
                if ($skuExists || $codeExists) {
                    throw new RuntimeException('Variant code or SKU already exists.');
                }

                $now = $this->timestamp();
                $variantId = (int) $connection->table('product_variants')->insertGetId([
                    'product_id' => $productId,
                    'code' => $normalizedCode,
                    'sku' => $normalizedSku,
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
                    $variantId,
                    $productId,
                    $normalizedCode,
                    $normalizedSku,
                    CatalogState::Draft,
                    $normalizedSortOrder,
                    1,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $variantId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $variantId,
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
        int $variantId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($variantId, 'Variant ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'variant_id' => $variantId,
            'expected_version' => $normalizedExpectedVersion,
            'content' => $content,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.variant.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $variantId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $variantId,
                $normalizedExpectedVersion,
                $content,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $variant = $this->lockedVariant($connection, $variantId);
                $this->assertVersion((int) $variant->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $variant->state);
                if ($state === CatalogState::Archived) {
                    throw new DomainException('Archived variants are immutable.');
                }
                $this->lockedProduct($connection, (int) $variant->product_id, false);

                $beforeContentHash = $this->contentHash($variant);
                $before = $this->safeState(
                    $variantId,
                    (int) $variant->product_id,
                    (string) $variant->code,
                    (string) $variant->sku,
                    $state,
                    (int) $variant->sort_order,
                    (int) $variant->version,
                    $beforeContentHash,
                    $payloadHash,
                );
                $changed = $beforeContentHash !== $content['content_hash']
                    || (int) $variant->sort_order !== $normalizedSortOrder;

                if (! $changed) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::TARGET_TYPE,
                        $variantId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = (int) $variant->version + 1;
                $connection->table('product_variants')->where('id', $variantId)->update([
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'sort_order' => $normalizedSortOrder,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $variantId,
                    (int) $variant->product_id,
                    (string) $variant->code,
                    (string) $variant->sku,
                    $state,
                    $normalizedSortOrder,
                    $nextVersion,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $variantId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $variantId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

}
