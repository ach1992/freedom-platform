<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;

trait ProductLifecycleOperations
{
    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function activate(int $productId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transitionState($productId, $expectedVersion, CatalogState::Active, 'catalog.product.activate', $context);
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function archive(int $productId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transitionState($productId, $expectedVersion, CatalogState::Archived, 'catalog.product.archive', $context);
    }

    /** @requirement CAT-001 CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function show(int $productId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transitionVisibility(
            $productId,
            $expectedVersion,
            ProductVisibility::Visible,
            'catalog.product.show',
            $context,
        );
    }

    /** @requirement CAT-001 CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function hide(int $productId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transitionVisibility(
            $productId,
            $expectedVersion,
            ProductVisibility::Hidden,
            'catalog.product.hide',
            $context,
        );
    }

    private function transitionState(
        int $productId,
        int $expectedVersion,
        CatalogState $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($productId, 'Product ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'product_id' => $productId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

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
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $product = $this->lockedProduct($connection, $productId);
                $this->assertVersion((int) $product->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $product->state);
                $visibility = $this->storedVisibility((string) $product->visibility);
                $state->assertCanTransitionTo($target);

                if ($target === CatalogState::Active) {
                    $this->lockedCategory($connection, (int) $product->category_id, true);
                } else {
                    if ($visibility !== ProductVisibility::Hidden) {
                        throw new DomainException('Visible products must be hidden before archival.');
                    }
                    $variant = $connection->table('product_variants')
                        ->where('product_id', $productId)
                        ->where('state', '<>', CatalogState::Archived->value)
                        ->lockForUpdate()
                        ->first(['id']);
                    if ($variant !== null) {
                        throw new DomainException('Product with non-archived variants cannot be archived.');
                    }
                }

                $contentHash = $this->contentHash($product);
                $before = $this->safeState(
                    $productId,
                    (string) $product->code,
                    (int) $product->category_id,
                    $state,
                    $visibility,
                    (int) $product->sort_order,
                    (int) $product->version,
                    $contentHash,
                    $payloadHash,
                );
                $nextVersion = (int) $product->version + 1;
                $connection->table('products')->where('id', $productId)->update([
                    'state' => $target->value,
                    'visibility' => $target === CatalogState::Archived
                        ? ProductVisibility::Hidden->value
                        : $visibility->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $afterVisibility = $target === CatalogState::Archived ? ProductVisibility::Hidden : $visibility;
                $after = $this->safeState(
                    $productId,
                    (string) $product->code,
                    (int) $product->category_id,
                    $target,
                    $afterVisibility,
                    (int) $product->sort_order,
                    $nextVersion,
                    $contentHash,
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

    private function transitionVisibility(
        int $productId,
        int $expectedVersion,
        ProductVisibility $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($productId, 'Product ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'product_id' => $productId,
            'expected_version' => $normalizedExpectedVersion,
            'target_visibility' => $target->value,
        ]);

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
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $product = $this->lockedProduct($connection, $productId);
                $this->assertVersion((int) $product->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $product->state);
                $visibility = $this->storedVisibility((string) $product->visibility);
                if ($visibility === $target) {
                    throw new DomainException('Product visibility transition is not allowed.');
                }
                if ($state !== CatalogState::Active) {
                    throw new DomainException('Only active products can change visibility.');
                }
                $this->lockedCategory($connection, (int) $product->category_id, true);

                $contentHash = $this->contentHash($product);
                $before = $this->safeState(
                    $productId,
                    (string) $product->code,
                    (int) $product->category_id,
                    $state,
                    $visibility,
                    (int) $product->sort_order,
                    (int) $product->version,
                    $contentHash,
                    $payloadHash,
                );
                $nextVersion = (int) $product->version + 1;
                $connection->table('products')->where('id', $productId)->update([
                    'visibility' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $productId,
                    (string) $product->code,
                    (int) $product->category_id,
                    $state,
                    $target,
                    (int) $product->sort_order,
                    $nextVersion,
                    $contentHash,
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
