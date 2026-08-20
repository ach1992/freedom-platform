<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ServiceOperationalPanelAdapter implements PanelAdapter
{
    /** @var array<string, RemoteServiceSnapshot> */
    private array $services = [];

    /** @var list<int> */
    public array $lookupTransactionLevels = [];

    public bool $lookupUnavailable = false;

    public function seed(RemoteServiceSnapshot $snapshot): void
    {
        $this->services[$snapshot->remoteId] = $snapshot;
    }

    public function remove(string $remoteId): void
    {
        unset($this->services[$remoteId]);
    }

    public function testConnection(): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function capabilities(): PanelCapabilities
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        $this->lookupTransactionLevels[] = DB::connection()->transactionLevel();
        if ($this->lookupUnavailable) {
            throw new AuthoritativePanelLookupUnavailable('Test authoritative lookup is unavailable.');
        }

        return $this->services[$remoteId] ?? null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        foreach ($this->services as $service) {
            if ($service->username === $username) {
                return $service;
            }
        }

        return null;
    }

    public function createEquivalenceHash(PanelCreateServiceRequest $request): string
    {
        throw new LogicException('Service import/repair must not create provider Services.');
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        throw new LogicException('Service import/repair must not create provider Services.');
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, \DateTimeImmutable $expiresAt): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function updateDataAllowance(string $idempotencyKey, string $remoteId, int $bytes, DataAllowanceMode $mode): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by Service operational tests.');
    }

    public function listCompatibleTargets(): array
    {
        return [];
    }
}

final readonly class ServiceOperationalPanelAdapterFactory implements PanelAdapterFactory
{
    public function __construct(private ServiceOperationalPanelAdapter $adapter) {}

    public function providerType(): PanelProviderType
    {
        return PanelProviderType::Fake;
    }

    public function make(PanelAdapterSession $session): PanelAdapter
    {
        return $this->adapter;
    }
}
