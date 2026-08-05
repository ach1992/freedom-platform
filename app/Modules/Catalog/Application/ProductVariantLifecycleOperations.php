<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use Illuminate\Database\Connection;

trait ProductVariantLifecycleOperations
{
    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function activate(int $variantId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($variantId, $expectedVersion, CatalogState::Active, 'catalog.variant.activate', $context);
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function archive(int $variantId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($variantId, $expectedVersion, CatalogState::Archived, 'catalog.variant.archive', $context);
    }

    private function transition(
        int $variantId,
        int $expectedVersion,
        CatalogState $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($variantId, 'Variant ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'variant_id' => $variantId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

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
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $variant = $this->lockedVariant($connection, $variantId);
                $this->assertVersion((int) $variant->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $variant->state);
                $state->assertCanTransitionTo($target);
                $this->lockedProduct($connection, (int) $variant->product_id, $target === CatalogState::Active);

                $contentHash = $this->contentHash($variant);
                $before = $this->safeState(
                    $variantId,
                    (int) $variant->product_id,
                    (string) $variant->code,
                    (string) $variant->sku,
                    $state,
                    (int) $variant->sort_order,
                    (int) $variant->version,
                    $contentHash,
                    $payloadHash,
                );
                $nextVersion = (int) $variant->version + 1;
                $connection->table('product_variants')->where('id', $variantId)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $variantId,
                    (int) $variant->product_id,
                    (string) $variant->code,
                    (string) $variant->sku,
                    $target,
                    (int) $variant->sort_order,
                    $nextVersion,
                    $contentHash,
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
