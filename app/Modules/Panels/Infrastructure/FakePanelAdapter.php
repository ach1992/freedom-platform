<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelAdapter;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Panels\Application\PanelServiceCanonicalizer;
use Closure;
use DateTimeImmutable;
use RuntimeException;

final class FakePanelAdapter implements PanelAdapter
{
    /** @var array<string, RemoteServiceSnapshot> */
    private array $services = [];

    /** @var array<string, string> */
    private array $remoteIdsByUsername = [];

    /** @var array<string, list<string>> */
    private array $deliveryLinks = [];

    /** @var array<string, array{fingerprint: string, result: PanelOperationResult}> */
    private array $operationResults = [];

    private bool $nextCreateIsUncertain = false;

    private bool $authoritativeLookup = true;

    public function __construct(private readonly PanelServiceCanonicalizer $canonicalizer) {}

    public function testConnection(): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, null, 'fake_connection_ok', 'Fake panel is healthy.');
    }

    public function capabilities(): PanelCapabilities
    {
        $operations = [
            'test_connection', 'create_service', 'fetch_status', 'update_expiry',
            'set_data_allowance', 'add_data_allowance', 'reset_usage', 'suspend',
            'activate', 'delete', 'rotate_subscription_link', 'delivery_artifacts',
            'synchronize', 'list_compatible_targets',
        ];
        if ($this->authoritativeLookup) {
            $operations[] = 'authoritative_username_lookup';
        }

        return new PanelCapabilities('fake', '1.0.0', $operations, ['fake-default']);
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        return $this->services[$remoteId] ?? null;
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        $remoteId = $this->remoteIdsByUsername[$username] ?? null;

        return $remoteId === null ? null : ($this->services[$remoteId] ?? null);
    }

    public function createService(PanelCreateServiceRequest $request): PanelOperationResult
    {
        $expectedHash = $this->canonicalizer->hashCreateRequest($request);
        $existing = $this->findByDeterministicUsername($request->username);
        if ($existing !== null) {
            return hash_equals($expectedHash, $existing->canonicalHash)
                ? $this->success($existing, 'fake_service_adopted')
                : $this->failure('fake_remote_conflict', 'Fake remote service conflicts with the request.', $existing);
        }

        $remoteId = 'fake-'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $existingByOperation = $this->findByRemoteId($remoteId);
        if ($existingByOperation !== null) {
            return $this->failure(
                'fake_idempotency_conflict',
                'Fake panel idempotency key conflicts with an existing remote service.',
                $existingByOperation,
            );
        }

        $service = new RemoteServiceSnapshot(
            $remoteId,
            $request->username,
            PanelServiceStatus::Active,
            $request->dataLimitBytes,
            0,
            $request->expiresAt,
            $expectedHash,
        );
        $this->services[$remoteId] = $service;
        $this->remoteIdsByUsername[$request->username] = $remoteId;
        $this->deliveryLinks[$remoteId] = ['https://example.invalid/sub/'.rawurlencode($remoteId)];

        if ($this->nextCreateIsUncertain) {
            $this->nextCreateIsUncertain = false;

            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'fake_timeout_after_create',
                'Fake panel timed out after create.',
            );
        }

        return $this->success($service, 'fake_service_created');
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        return $this->existingResult($remoteId);
    }

    public function updateExpiry(string $idempotencyKey, string $remoteId, DateTimeImmutable $expiresAt): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('update_expiry', [
                'remote_id' => $remoteId,
                'expires_at_unix' => $expiresAt->getTimestamp(),
            ]),
            fn (): PanelOperationResult => $this->replace($remoteId, expiresAt: $expiresAt),
        );
    }

    public function updateDataAllowance(
        string $idempotencyKey,
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
    ): PanelOperationResult {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('update_data_allowance', [
                'remote_id' => $remoteId,
                'bytes' => $bytes,
                'mode' => $mode->value,
            ]),
            function () use ($remoteId, $bytes, $mode): PanelOperationResult {
                $service = $this->services[$remoteId] ?? null;
                if ($service === null) {
                    return $this->notFound();
                }
                $limit = $mode === DataAllowanceMode::Add
                    ? ($service->dataLimitBytes ?? 0) + $bytes
                    : $bytes;

                return $this->replace($remoteId, dataLimitBytes: $limit);
            },
        );
    }

    public function resetUsage(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('reset_usage', ['remote_id' => $remoteId]),
            fn (): PanelOperationResult => $this->replace($remoteId, usedBytes: 0),
        );
    }

    public function suspend(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('suspend', ['remote_id' => $remoteId]),
            fn (): PanelOperationResult => $this->replace($remoteId, status: PanelServiceStatus::Suspended),
        );
    }

    public function activate(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('activate', ['remote_id' => $remoteId]),
            fn (): PanelOperationResult => $this->replace($remoteId, status: PanelServiceStatus::Active),
        );
    }

    public function delete(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('delete', ['remote_id' => $remoteId]),
            function () use ($remoteId): PanelOperationResult {
                $service = $this->services[$remoteId] ?? null;
                if ($service === null) {
                    return $this->notFound();
                }
                unset(
                    $this->services[$remoteId],
                    $this->remoteIdsByUsername[$service->username],
                    $this->deliveryLinks[$remoteId],
                );

                return $this->success($service, 'fake_service_deleted');
            },
        );
    }

    public function rotateSubscriptionLink(string $idempotencyKey, string $remoteId): PanelOperationResult
    {
        return $this->idempotentOperation(
            $idempotencyKey,
            $this->operationFingerprint('rotate_subscription_link', ['remote_id' => $remoteId]),
            function () use ($idempotencyKey, $remoteId): PanelOperationResult {
                $service = $this->services[$remoteId] ?? null;
                if ($service === null) {
                    return $this->notFound();
                }
                $this->deliveryLinks[$remoteId] = [
                    'https://example.invalid/sub/'.rawurlencode($remoteId).'/'.substr(hash('sha256', $idempotencyKey), 0, 16),
                ];

                return $this->success($service, 'fake_subscription_rotated');
            },
        );
    }

    public function getDeliveryArtifacts(string $remoteId): SensitiveDeliveryArtifacts
    {
        if (! isset($this->services[$remoteId])) {
            throw new RuntimeException('Remote service was not found.');
        }

        return new SensitiveDeliveryArtifacts($this->deliveryLinks[$remoteId] ?? []);
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        return $this->existingResult($remoteId);
    }

    public function listCompatibleTargets(): array
    {
        return [[
            'id' => 'fake-default',
            'type' => 'inbound',
            'name' => 'Fake default target',
            'capabilities' => ['create_service'],
        ]];
    }

    public function makeNextCreateUncertain(): void
    {
        $this->nextCreateIsUncertain = true;
    }

    public function makeAuthoritativeLookupUnavailable(): void
    {
        $this->authoritativeLookup = false;
    }

    public function serviceCount(): int
    {
        return count($this->services);
    }

    public function seed(RemoteServiceSnapshot $service): void
    {
        $this->services[$service->remoteId] = $service;
        $this->remoteIdsByUsername[$service->username] = $service->remoteId;
    }

    /**
     * @param Closure(): PanelOperationResult $operation
     */
    private function idempotentOperation(
        string $idempotencyKey,
        string $fingerprint,
        Closure $operation,
    ): PanelOperationResult {
        $existing = $this->operationResults[$idempotencyKey] ?? null;
        if ($existing !== null) {
            if (! hash_equals($existing['fingerprint'], $fingerprint)) {
                return $this->failure(
                    'fake_idempotency_conflict',
                    'Fake panel idempotency key conflicts with a different remote operation.',
                    $existing['result']->service,
                );
            }

            return $existing['result'];
        }

        $result = $operation();
        $this->operationResults[$idempotencyKey] = [
            'fingerprint' => $fingerprint,
            'result' => $result,
        ];

        return $result;
    }

    /** @param array<string, int|string> $payload */
    private function operationFingerprint(string $operation, array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode([
            'operation' => $operation,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
    }

    private function existingResult(string $remoteId): PanelOperationResult
    {
        $service = $this->services[$remoteId] ?? null;

        return $service === null ? $this->notFound() : $this->success($service, 'fake_service_found');
    }

    private function replace(
        string $remoteId,
        ?PanelServiceStatus $status = null,
        ?int $dataLimitBytes = null,
        ?int $usedBytes = null,
        ?DateTimeImmutable $expiresAt = null,
    ): PanelOperationResult {
        $current = $this->services[$remoteId] ?? null;
        if ($current === null) {
            return $this->notFound();
        }
        $service = new RemoteServiceSnapshot(
            $current->remoteId,
            $current->username,
            $status ?? $current->status,
            $dataLimitBytes ?? $current->dataLimitBytes,
            $usedBytes ?? $current->usedBytes,
            $expiresAt ?? $current->expiresAt,
            $current->canonicalHash,
        );
        $this->services[$remoteId] = $service;

        return $this->success($service, 'fake_service_updated');
    }

    private function success(RemoteServiceSnapshot $service, string $code): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::Success, $service, $code, 'Fake panel operation succeeded.');
    }

    private function failure(string $code, string $message, ?RemoteServiceSnapshot $service = null): PanelOperationResult
    {
        return new PanelOperationResult(PanelOperationOutcome::DefinitiveFailure, $service, $code, $message);
    }

    private function notFound(): PanelOperationResult
    {
        return $this->failure('fake_remote_not_found', 'Fake remote service was not found.');
    }
}
