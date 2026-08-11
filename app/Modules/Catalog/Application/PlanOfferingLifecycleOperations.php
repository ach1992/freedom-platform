<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\ProductVisibility;
use DomainException;
use Illuminate\Database\Connection;

trait PlanOfferingLifecycleOperations
{
    /** @requirement CAT-002 CAT-003 CAT-004 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function activate(
        int $offeringId,
        int $expectedVersion,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        return $this->transition(
            $offeringId,
            $expectedVersion,
            CatalogState::Active,
            'catalog.plan_offering.activate',
            $context,
        );
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function archive(
        int $offeringId,
        int $expectedVersion,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        return $this->transition(
            $offeringId,
            $expectedVersion,
            CatalogState::Archived,
            'catalog.plan_offering.archive',
            $context,
        );
    }

    /** @requirement CAT-002 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function setVisibility(
        int $offeringId,
        int $expectedVersion,
        ProductVisibility $visibility,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($offeringId, 'Plan offering ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'offering_id' => $offeringId,
            'expected_version' => $normalizedExpectedVersion,
            'visibility' => $visibility->value,
        ]);
        $action = 'catalog.plan_offering.visibility';

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
                $visibility,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $record = $this->lockedOffering($connection, $offeringId);
                $this->assertOfferingVersion($record->version, $normalizedExpectedVersion);
                $state = $this->storedOfferingState($record->state);
                $storedVisibility = $this->storedOfferingVisibility($record->visibility);
                if ($state === CatalogState::Archived) {
                    throw new DomainException('Archived plan offerings are immutable.');
                }
                if ($visibility === ProductVisibility::Visible) {
                    if ($state !== CatalogState::Active) {
                        throw new DomainException('Only an active plan offering may be visible.');
                    }
                    $this->assertOperationalOfferingDependencies($connection, $record);
                }

                $configurationHash = $this->currentConfigurationHash($connection, $record->id, $record->version);
                $before = $this->safeOfferingState($connection, $record, $configurationHash, $payloadHash);
                if ($storedVisibility === $visibility) {
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
                $connection->table('plan_offerings')->where('id', $record->id)->update([
                    'visibility' => $visibility->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->offeringTimestamp(),
                ]);
                $updated = new PlanOfferingRecord(
                    $record->id,
                    $record->code,
                    $record->productId,
                    $record->variantId,
                    $record->salesServerId,
                    $record->serviceTargetId,
                    $record->serviceModeCode,
                    $record->serviceModeLabelFa,
                    $record->serviceModeLabelEn,
                    $record->audience,
                    $record->serverSelectionMode,
                    $record->protocolSelectionMode,
                    $record->tagMatchMode,
                    $record->basePriceIrr,
                    $record->durationDays,
                    $record->dataAllowanceBytes,
                    $record->deviceLimit,
                    $record->sortOrder,
                    $record->minPurchaseQuantity,
                    $record->maxPurchaseQuantity,
                    $record->discountEligible,
                    $record->autoRenewAllowed,
                    $record->customPlanAllowed,
                    $record->trialAllowed,
                    $record->state,
                    $visibility->value,
                    $nextVersion,
                );
                $after = $this->safeOfferingState($connection, $updated, $configurationHash, $payloadHash);
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

    private function transition(
        int $offeringId,
        int $expectedVersion,
        CatalogState $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($offeringId, 'Plan offering ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'offering_id' => $offeringId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

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
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $record = $this->lockedOffering($connection, $offeringId);
                $this->assertOfferingVersion($record->version, $normalizedExpectedVersion);
                $state = $this->storedOfferingState($record->state);
                $state->assertCanTransitionTo($target);
                if ($target === CatalogState::Active) {
                    $this->assertOperationalOfferingDependencies($connection, $record);
                }
                if ($target === CatalogState::Archived
                    && $this->storedOfferingVisibility($record->visibility) !== ProductVisibility::Hidden
                ) {
                    throw new DomainException('Plan offering must be hidden before archival.');
                }

                $configurationHash = $this->currentConfigurationHash($connection, $record->id, $record->version);
                $before = $this->safeOfferingState($connection, $record, $configurationHash, $payloadHash);
                $nextVersion = $record->version + 1;
                $connection->table('plan_offerings')->where('id', $record->id)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->offeringTimestamp(),
                ]);

                $updated = new PlanOfferingRecord(
                    $record->id,
                    $record->code,
                    $record->productId,
                    $record->variantId,
                    $record->salesServerId,
                    $record->serviceTargetId,
                    $record->serviceModeCode,
                    $record->serviceModeLabelFa,
                    $record->serviceModeLabelEn,
                    $record->audience,
                    $record->serverSelectionMode,
                    $record->protocolSelectionMode,
                    $record->tagMatchMode,
                    $record->basePriceIrr,
                    $record->durationDays,
                    $record->dataAllowanceBytes,
                    $record->deviceLimit,
                    $record->sortOrder,
                    $record->minPurchaseQuantity,
                    $record->maxPurchaseQuantity,
                    $record->discountEligible,
                    $record->autoRenewAllowed,
                    $record->customPlanAllowed,
                    $record->trialAllowed,
                    $target->value,
                    $record->visibility,
                    $nextVersion,
                );
                $after = $this->safeOfferingState($connection, $updated, $configurationHash, $payloadHash);
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
