<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;
use RuntimeException;

abstract class AbstractPinnedReadOnlyPanelGateway implements PanelAdapter
{
    final public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        return $this->mutationDisabled('create_service');
    }

    final public function updateExpiry(
        string $idempotencyKey,
        string $remoteId,
        DateTimeImmutable $expiresAt,
    ): PanelOperationResult {
        return $this->mutationDisabled('update_expiry');
    }

    final public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        return $this->mutationDisabled('update_data_allowance');
    }

    final public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutationDisabled('reset_usage');
    }

    final public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutationDisabled('suspend');
    }

    final public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutationDisabled('activate');
    }

    final public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutationDisabled('delete');
    }

    final public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->mutationDisabled('rotate_subscription_link');
    }

    final public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new RuntimeException($this->providerCode().' delivery is disabled until the mutation contract is verified.');
    }

    abstract protected function providerCode(): string;

    private function mutationDisabled(string $operation): PanelOperationResult
    {
        return new PanelOperationResult(
            PanelOperationOutcome::DefinitiveFailure,
            null,
            $this->providerCode().'_source_contract_mutation_disabled',
            'Provider mutation is disabled until the pinned source contract is fully verified.',
        );
    }
}
