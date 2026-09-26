<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelAdapterFactory;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceReconfigurationAdapter;
use App\Modules\Panels\Application\Contracts\PanelServiceReconfigurationRequest;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Application\PanelAdapterSession;
use App\Modules\Panels\Domain\PanelProviderType;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ServiceOperationalPanelAdapter implements PanelAdapter, PanelServiceReconfigurationAdapter
{
    /** @var array<string, RemoteServiceSnapshot> */
    private array $services = [];

    /** @var list<int> */
    public array $lookupTransactionLevels = [];

    public bool $lookupUnavailable = false;

    public ?RemoteServiceSnapshot $forcedLookupSnapshot = null;

    public ?Closure $afterLookup = null;

    /** @var list<int> */
    public array $reconfigurationTransactionLevels = [];

    /** @var list<PanelServiceReconfigurationRequest> */
    public array $reconfigurationRequests = [];

    public bool $reconfigurationThrows = false;

    public PanelOperationOutcome $reconfigurationOutcome = PanelOperationOutcome::Success;

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
        return new PanelCapabilities(
            'fake',
            'service-operational-test-1',
            ['create_service', 'fetch_status', 'reconfigure_service'],
            [],
        );
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        $this->lookupTransactionLevels[] = DB::connection()->transactionLevel();
        if ($this->lookupUnavailable) {
            throw new AuthoritativePanelLookupUnavailable('Test authoritative lookup is unavailable.');
        }

        $afterLookup = $this->afterLookup;
        $this->afterLookup = null;
        $afterLookup?->__invoke();
        if ($this->forcedLookupSnapshot !== null) {
            return $this->forcedLookupSnapshot;
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

    public function reconfigureService(PanelServiceReconfigurationRequest $request): PanelOperationResult
    {
        $this->reconfigurationTransactionLevels[] = DB::connection()->transactionLevel();
        $this->reconfigurationRequests[] = $request;
        if ($this->reconfigurationThrows) {
            throw new LogicException('Simulated uncertain Service reconfiguration provider boundary.');
        }
        if ($this->reconfigurationOutcome !== PanelOperationOutcome::Success) {
            return new PanelOperationResult(
                $this->reconfigurationOutcome,
                null,
                'reconfigure_'.$this->reconfigurationOutcome->value,
                'Simulated Service reconfiguration provider outcome.',
            );
        }

        $current = $this->services[$request->remoteId] ?? null;
        if ($current === null) {
            return new PanelOperationResult(
                PanelOperationOutcome::DefinitiveFailure,
                null,
                'reconfigure_remote_missing',
                'Remote Service does not exist.',
            );
        }
        $canonicalHash = hash('sha256', implode(':', [
            'reconfigured',
            $current->canonicalHash,
            $request->targetPlanOfferingCode,
            $request->targetReference,
            $request->targetProtocolProfileCode,
        ]));
        $snapshot = new RemoteServiceSnapshot(
            $current->remoteId,
            $current->username,
            $current->status,
            $current->dataLimitBytes,
            $current->usedBytes,
            $current->expiresAt,
            $canonicalHash,
            hash('sha256', 'equivalence:'.$canonicalHash),
        );
        $this->services[$snapshot->remoteId] = $snapshot;

        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $snapshot,
            'reconfigure_success',
            'Service reconfiguration completed.',
        );
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
