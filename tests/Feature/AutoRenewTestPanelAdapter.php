<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AutoRenewTestPanelAdapter implements PanelAdapter
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?DateTimeImmutable $lastExpiryAt = null;

    public bool $throwOnUpdateExpiry = false;

    /** @var array<string, RemoteServiceSnapshot> */
    private array $servicesByUsername = [];

    /** @var list<string> */
    private array $operations = [
        'activate',
        'authoritative_username_lookup',
        'create_service',
        'add_data_allowance',
        'atomic_service_entitlements',
        'delete',
        'reset_usage',
        'rotate_subscription_link',
        'suspend',
        'update_expiry',
    ];

    public function resetCalls(): void
    {
        $this->calls = [];
        $this->transactionLevels = [];
        $this->lastExpiryAt = null;
    }

    public function seedKnownEntitlements(string $remoteId, int $dataLimitBytes, DateTimeImmutable $expiresAt): void
    {
        $username = 'seeded-'.substr(hash('sha256', $remoteId), 0, 16);
        $hash = hash('sha256', implode('|', [
            $remoteId,
            $username,
            PanelServiceStatus::Active->value,
            (string) $dataLimitBytes,
            '0',
            $expiresAt->format(DATE_ATOM),
        ]));
        $this->servicesByUsername[$username] = new RemoteServiceSnapshot(
            $remoteId,
            $username,
            PanelServiceStatus::Active,
            $dataLimitBytes,
            0,
            $expiresAt,
            $hash,
            $hash,
        );
    }

    public function setKnownEntitlements(string $remoteId, int $dataLimitBytes, DateTimeImmutable $expiresAt): void
    {
        foreach ($this->servicesByUsername as $username => $service) {
            if (! hash_equals($service->remoteId, $remoteId)) {
                continue;
            }

            $this->servicesByUsername[$username] = $this->snapshot(
                $service,
                $dataLimitBytes,
                $service->usedBytes ?? 0,
                $expiresAt,
            );

            return;
        }

        throw new LogicException('Auto-renew test remote Service is unavailable.');
    }

    public function testConnection(): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_connection_ok', 'Test panel is healthy.');
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities('fake', '1.0.0', $this->operations, ['fake-default']);
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        $this->record('lookup_remote_id');
        foreach ($this->servicesByUsername as $service) {
            if (hash_equals($service->remoteId, $remoteId)) {
                return $service;
            }
        }

        return null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        $this->record('lookup_username');

        return $this->servicesByUsername[$username] ?? null;
    }

    public function createEquivalenceHash(PanelCreateServiceRequest $request): string
    {
        return hash('sha256', json_encode([
            'username' => $request->username,
            'target_reference' => $request->targetReference,
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at' => $request->expiresAt?->format(DATE_ATOM),
            'attributes' => $request->validatedAttributes,
        ], JSON_THROW_ON_ERROR));
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        $this->record('create');
        $hash = $this->createEquivalenceHash($request);
        $service = new RemoteServiceSnapshot(
            'auto-renew-test-'.substr(hash('sha256', $request->idempotencyKey), 0, 24),
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $hash,
            $hash,
        );
        $this->servicesByUsername[$service->username] = $service;

        return new PanelOperationResult(PanelOperationOutcome::Success, $service, 'test_service_created', 'Test Service created.');
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, DateTimeImmutable $expiresAt): PanelOperationResult
    {
        $this->record('update_expiry');
        $this->lastExpiryAt = $expiresAt;
        if ($this->throwOnUpdateExpiry) {
            throw new LogicException('Simulated auto-renew provider uncertainty after boundary entry.');
        }
        foreach ($this->servicesByUsername as $username => $service) {
            if (hash_equals($service->remoteId, $remoteId)) {
                $this->servicesByUsername[$username] = $this->snapshot(
                    $service,
                    $service->dataLimitBytes,
                    $service->usedBytes ?? 0,
                    $expiresAt,
                );
                break;
            }
        }

        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'test_update_expiry_ok', 'Test expiry updated.');
    }

    public function updateDataAllowance(string $idempotencyKey, string $remoteId, int $bytes, DataAllowanceMode $mode): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        throw new LogicException('Not used by auto-renew tests.');
    }

    public function listCompatibleTargets(): array
    {
        return [[
            'id' => 'fake-default',
            'type' => 'inbound',
            'name' => 'Auto-renew test target',
            'capabilities' => $this->operations,
        ]];
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        $this->transactionLevels[] = DB::connection()->transactionLevel();
    }

    private function snapshot(
        RemoteServiceSnapshot $service,
        ?int $dataLimitBytes,
        int $usedBytes,
        DateTimeImmutable $expiresAt,
    ): RemoteServiceSnapshot {
        $hash = hash('sha256', implode('|', [
            $service->remoteId,
            $service->username,
            $service->status->value,
            (string) ($dataLimitBytes ?? -1),
            (string) $usedBytes,
            $expiresAt->format(DATE_ATOM),
        ]));

        return new RemoteServiceSnapshot(
            $service->remoteId,
            $service->username,
            $service->status,
            $dataLimitBytes,
            $usedBytes,
            $expiresAt,
            $hash,
            $service->createEquivalenceHash,
        );
    }
}
