<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\PlanOfferingDefinition;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait PlanOfferingDefinitionOperations
{
    /** @requirement CAT-002 CAT-003 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        PlanOfferingDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        $payloadHash = CatalogPayloadHash::make($definition->payload());
        $action = 'catalog.plan_offering.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($action, $definition, $payloadHash, $context): CatalogMutationReceipt {
                if ($connection->table('plan_offerings')->where('code', $definition->code)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Plan offering code already exists.');
                }

                $this->lockDefinitionDependencies($connection, $definition, false);
                $now = $this->offeringTimestamp();
                $offeringId = (int) $connection->table('plan_offerings')->insertGetId([
                    ...$definition->scalarPayload(),
                    'state' => CatalogState::Draft->value,
                    'visibility' => ProductVisibility::Hidden->value,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->insertOfferingChildren($connection, $offeringId, $definition, $now);

                $record = $this->recordFromDefinition(
                    $offeringId,
                    $definition,
                    CatalogState::Draft,
                    ProductVisibility::Hidden,
                    1,
                );
                $configurationHash = $this->definitionHash($definition);
                $after = $this->safeOfferingState($connection, $record, $configurationHash, $payloadHash);
                $this->offeringHistory($connection, $offeringId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $offeringId,
                    $context,
                    [],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-002 CAT-003 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function update(
        int $offeringId,
        int $expectedVersion,
        PlanOfferingDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($offeringId, 'Plan offering ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'offering_id' => $offeringId,
            'expected_version' => $normalizedExpectedVersion,
            'definition' => $definition->payload(),
        ]);
        $action = 'catalog.plan_offering.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $offeringId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $offeringId,
                $normalizedExpectedVersion,
                $definition,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $record = $this->lockedOffering($connection, $offeringId);
                $this->assertOfferingVersion($record->version, $normalizedExpectedVersion);
                if ($this->storedOfferingState($record->state) !== CatalogState::Draft) {
                    throw new DomainException('Only a draft plan offering may be edited.');
                }
                if ($record->code !== $definition->code) {
                    throw new DomainException('Plan offering code is immutable.');
                }

                $this->lockDefinitionDependencies($connection, $definition, false);
                $currentHash = $this->currentConfigurationHash($connection, $record->id, $record->version);
                $nextHash = $this->definitionHash($definition);
                $before = $this->safeOfferingState($connection, $record, $currentHash, $payloadHash);
                if (hash_equals($currentHash, $nextHash)) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::TARGET_TYPE,
                        $record->id,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = $record->version + 1;
                $now = $this->offeringTimestamp();
                $values = $definition->scalarPayload();
                unset($values['code']);
                $connection->table('plan_offerings')->where('id', $record->id)->update([
                    ...$values,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
                $this->replaceOfferingChildren($connection, $record->id, $definition, $now);

                $updated = $this->recordFromDefinition(
                    $record->id,
                    $definition,
                    CatalogState::Draft,
                    ProductVisibility::Hidden,
                    $nextVersion,
                );
                $after = $this->safeOfferingState($connection, $updated, $nextHash, $payloadHash);
                $this->offeringHistory(
                    $connection,
                    $record->id,
                    $nextVersion,
                    $action,
                    $before,
                    $after,
                    $context,
                );

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $record->id,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }
}
