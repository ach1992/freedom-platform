<?php

declare(strict_types=1);

namespace App\Modules\Panels\Application\Contracts;

interface PanelAdapter
{
    public function testConnection(): PanelOperationResult;

    public function capabilities(): PanelCapabilities;

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot;

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot;

    /**
     * Return the provider-specific canonical hash of create intent fields that
     * the provider preserves and can later prove through authoritative lookup.
     */
    public function createEquivalenceHash(PanelCreateServiceRequest $request): string;

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult;

    public function fetchStatus(string $remoteId): PanelOperationResult;

    public function updateExpiry(string $idempotencyKey, string $remoteId, \DateTimeImmutable $expiresAt): PanelOperationResult;

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult;

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult;

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult;

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult;

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult;

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult;

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts;

    public function synchronize(string $remoteId): PanelOperationResult;

    /** @return list<array{id: string, type: string, name: string, capabilities: list<string>}> */
    public function listCompatibleTargets(): array;
}
