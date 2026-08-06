<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;

abstract class DelegatingPanelAdapter implements PanelAdapter
{
    public function __construct(private readonly PanelAdapter $gateway) {}

    public function testConnection(): PanelOperationResult
    {
        return $this->gateway->testConnection();
    }

    public function capabilities(): PanelCapabilities
    {
        return $this->gateway->capabilities();
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        return $this->gateway->findByRemoteId($remoteId);
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        return $this->gateway->findByDeterministicUsername($username);
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        return $this->gateway->createService($request);
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        return $this->gateway->fetchStatus($remoteId);
    }

    public function updateExpiry(
        string $idempotencyKey,
        string $remoteId,
        DateTimeImmutable $expiresAt,
    ): PanelOperationResult {
        return $this->gateway->updateExpiry($idempotencyKey, $remoteId, $expiresAt);
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        return $this->gateway->updateDataAllowance($idempotencyKey, $remoteId, $bytes, $mode);
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->gateway->resetUsage($idempotencyKey, $remoteId);
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->gateway->suspend($idempotencyKey, $remoteId);
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->gateway->activate($idempotencyKey, $remoteId);
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->gateway->delete($idempotencyKey, $remoteId);
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->gateway->rotateSubscriptionLink($idempotencyKey, $remoteId);
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        return $this->gateway->getDeliveryArtifacts($remoteId);
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        return $this->gateway->synchronize($remoteId);
    }

    public function listCompatibleTargets(): array
    {
        return $this->gateway->listCompatibleTargets();
    }
}
