<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\MarzbanGateway;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PasarGuardGateway;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;
use RuntimeException;

final class UnavailablePanelGateway implements MarzbanGateway, PasarGuardGateway
{
    public function testConnection(): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities('unconfigured', 'unknown', [], []);
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        return null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        return null;
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function updateExpiry(
        string $idempotencyKey,
        string $remoteId,
        DateTimeImmutable $expiresAt,
    ): PanelOperationResult {
        return $this->unavailable();
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        return $this->unavailable();
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new RuntimeException('Panel gateway is not configured.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        return $this->unavailable();
    }

    public function listCompatibleTargets(): array
    {
        return [];
    }

    private function unavailable(): PanelOperationResult
    {
        return new PanelOperationResult(
            PanelOperationOutcome::DefinitiveFailure,
            null,
            'panel_gateway_unconfigured',
            'Panel gateway is not configured.',
        );
    }
}
