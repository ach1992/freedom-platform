<?php

declare(strict_types=1);

namespace FreedomPlatform\Scripts\Ci;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use App\Modules\Panels\Infrastructure\PanelMappedMutationRequest;
use App\Modules\Panels\Infrastructure\PasarGuardMutationContractMapper;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PasarGuardLiveAcceptance
{
    public const CONFIRMATION = 'MUTATE_DISPOSABLE_PASARGUARD_V5_2_1';

    private const CREATE_LIMIT_BYTES = 1_048_576;
    private const SET_LIMIT_BYTES = 2_097_152;
    private const ADD_LIMIT_BYTES = 1_048_576;

    private readonly Closure $request;

    public function __construct(callable $request)
    {
        $this->request = Closure::fromCallable($request);
    }

    /**
     * @param array{origin: string, api_key: string, run_id: string, confirm: string, group_id?: int|null, now?: DateTimeImmutable} $config
     * @return array<string, mixed>
     */
    public function run(array $config): array
    {
        [$origin, $configuredPath, $apiKey, $runId, $groupId, $now] = $this->validateConfig($config);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Api-Key' => $apiKey,
            'User-Agent' => 'FreedomPlatform-PasarGuard-Live-Acceptance/1.0',
        ];

        [$basePath, $system] = $this->detectBasePath($origin, $configuredPath, $headers);
        $version = $this->requiredString($system, 'version');
        if (ltrim($version, 'vV') !== PasarGuardMutationContractMapper::VERSION) {
            throw new RuntimeException('pasarguard_live_version_mismatch');
        }

        $inbounds = $this->readList($origin, $basePath, '/api/inbounds/details', $headers, 'inbound_discovery');
        foreach ($inbounds as $inbound) {
            if (! is_array($inbound)
                || ! isset($inbound['tag'], $inbound['protocol'])
                || ! is_string($inbound['tag'])
                || ! is_string($inbound['protocol'])
            ) {
                throw new RuntimeException('pasarguard_live_inbound_shape_invalid');
            }
        }

        $groupsPayload = $this->readObject($origin, $basePath, '/api/groups?limit=100', $headers, 'group_discovery');
        $selectedGroupId = $this->selectGroupId($groupsPayload, $groupId);

        $username = 'fp_pg_'.substr(hash('sha256', $runId), 0, 12);
        $usernameHash = hash('sha256', $username);
        $mapper = new PasarGuardMutationContractMapper;
        $createdAt = $now->setTimezone(new DateTimeZone('UTC'));
        $createExpiry = $createdAt->modify('+30 minutes');
        $updatedExpiry = $createdAt->modify('+60 minutes');
        $request = new PanelCreateServiceRequest(
            'pasarguard-live-create-'.$runId,
            'pasarguard-live-idem-'.$runId,
            $username,
            'pasarguard-group-'.$selectedGroupId,
            self::CREATE_LIMIT_BYTES,
            $createExpiry,
            [],
        );

        $remoteId = null;
        $cleanupVerified = false;
        $executedRows = ['LIVE-001', 'LIVE-002', 'LIVE-003', 'LIVE-004'];

        try {
            $lookup = $this->request('GET', $origin.$basePath.'/api/user/by-username/'.rawurlencode($username), $headers, null);
            if ($lookup->status !== 404 || $lookup->transportFailure) {
                throw new RuntimeException('pasarguard_live_initial_absence_unproved');
            }
            $executedRows[] = 'LIVE-005';

            $create = $this->issueMapped($origin, $basePath, $headers, $mapper, 'create_service', $mapper->createRequest($request));
            $remoteId = (string) $this->requiredPositiveInt($create, 'id');
            if (! $mapper->createEquivalent($request, $create)) {
                throw new RuntimeException('pasarguard_live_create_equivalence_failed');
            }
            $executedRows[] = 'LIVE-006';

            $postCreate = $this->readUserByUsername($origin, $basePath, $headers, $username);
            if ((string) $this->requiredPositiveInt($postCreate, 'id') !== $remoteId || ! $mapper->createEquivalent($request, $postCreate)) {
                throw new RuntimeException('pasarguard_live_post_create_read_mismatch');
            }
            $byId = $this->readUserById($origin, $basePath, $headers, $remoteId);
            if ($this->requiredString($byId, 'username') !== $username) {
                throw new RuntimeException('pasarguard_live_numeric_identity_mismatch');
            }
            $executedRows[] = 'LIVE-007';

            $mismatchRequest = new PanelCreateServiceRequest(
                'pasarguard-live-mismatch-'.$runId,
                'pasarguard-live-mismatch-idem-'.$runId,
                $username,
                'pasarguard-group-'.$selectedGroupId,
                self::CREATE_LIMIT_BYTES + 1,
                $createExpiry,
                [],
            );
            if ($mapper->createEquivalent($mismatchRequest, $postCreate)) {
                throw new RuntimeException('pasarguard_live_mismatch_was_not_detected');
            }
            $executedRows[] = 'LIVE-008';

            $expiryPayload = $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'update_expiry',
                $mapper->updateExpiryRequest($remoteId, $updatedExpiry),
            );
            $this->assertExpiry($expiryPayload, $updatedExpiry);
            $this->assertExpiry($this->readUserById($origin, $basePath, $headers, $remoteId), $updatedExpiry);
            $executedRows[] = 'LIVE-011';

            $setPayload = $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'update_data_allowance',
                $mapper->updateDataAllowanceRequest($remoteId, self::SET_LIMIT_BYTES, DataAllowanceMode::Set),
            );
            $this->assertDataLimit($setPayload, self::SET_LIMIT_BYTES);
            $current = $this->readUserById($origin, $basePath, $headers, $remoteId);
            $this->assertDataLimit($current, self::SET_LIMIT_BYTES);
            $executedRows[] = 'LIVE-012';

            $snapshot = $this->snapshotForAdd($current);
            $addPayload = $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'update_data_allowance',
                $mapper->updateDataAllowanceRequest($remoteId, self::ADD_LIMIT_BYTES, DataAllowanceMode::Add, $snapshot),
            );
            $expectedAdded = self::SET_LIMIT_BYTES + self::ADD_LIMIT_BYTES;
            $this->assertDataLimit($addPayload, $expectedAdded);
            $this->assertDataLimit($this->readUserById($origin, $basePath, $headers, $remoteId), $expectedAdded);
            $executedRows[] = 'LIVE-013';

            $resetPayload = $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'reset_usage',
                $mapper->resetUsageRequest($remoteId),
            );
            $this->assertUsedTrafficReset($resetPayload);
            $this->assertUsedTrafficReset($this->readUserById($origin, $basePath, $headers, $remoteId));
            $executedRows[] = 'LIVE-014';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'suspend', $mapper->suspendRequest($remoteId));
            $this->assertStatus($this->readUserById($origin, $basePath, $headers, $remoteId), 'disabled');
            $executedRows[] = 'LIVE-015';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'activate', $mapper->activateRequest($remoteId));
            $active = $this->readUserById($origin, $basePath, $headers, $remoteId);
            $this->assertStatus($active, 'active');
            $executedRows[] = 'LIVE-016';

            $beforeArtifacts = $mapper->deliveryArtifacts($active);
            $beforeLinks = $beforeArtifacts->revealForAuthorizedDelivery();
            $beforeHash = hash('sha256', $beforeLinks[0]);
            if ((string) $beforeArtifacts !== '[SENSITIVE_DELIVERY_ARTIFACTS]') {
                throw new RuntimeException('pasarguard_live_delivery_redaction_failed');
            }

            $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'rotate_subscription_link',
                $mapper->rotateSubscriptionLinkRequest($remoteId),
            );
            $rotated = $this->readUserById($origin, $basePath, $headers, $remoteId);
            $afterArtifacts = $mapper->deliveryArtifacts($rotated);
            $afterLinks = $afterArtifacts->revealForAuthorizedDelivery();
            $afterHash = hash('sha256', $afterLinks[0]);
            if (hash_equals($beforeHash, $afterHash)) {
                throw new RuntimeException('pasarguard_live_subscription_rotation_unproved');
            }
            if ((string) $afterArtifacts !== '[SENSITIVE_DELIVERY_ARTIFACTS]') {
                throw new RuntimeException('pasarguard_live_delivery_redaction_failed');
            }
            $executedRows[] = 'LIVE-017';
            $executedRows[] = 'LIVE-018';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'delete', $mapper->deleteRequest($remoteId));
            $finalLookup = $this->request('GET', $origin.$basePath.'/api/user/by-username/'.rawurlencode($username), $headers, null);
            if ($finalLookup->status !== 404 || $finalLookup->transportFailure) {
                throw new RuntimeException('pasarguard_live_final_absence_unproved');
            }
            $cleanupVerified = true;
            $remoteId = null;
            $executedRows[] = 'LIVE-022';
            $executedRows[] = 'LIVE-023';

            return [
                'provider' => 'pasarguard',
                'version' => PasarGuardMutationContractMapper::VERSION,
                'base_path' => $basePath === '' ? '/' : $basePath,
                'authentication' => 'api_key',
                'group_id' => $selectedGroupId,
                'inbound_count' => count($inbounds),
                'test_user_sha256' => $usernameHash,
                'executed_rows' => $executedRows,
                'deferred_rows' => ['LIVE-009', 'LIVE-010', 'LIVE-019', 'LIVE-020', 'LIVE-021', 'LIVE-024'],
                'cleanup_verified' => true,
                'sensitive_artifacts' => 'redacted',
            ];
        } finally {
            if ($remoteId !== null && ! $cleanupVerified) {
                $this->cleanup($origin, $basePath, $headers, $mapper, $remoteId);
            }
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: int|null, 5: DateTimeImmutable} */
    private function validateConfig(array $config): array
    {
        $originValue = trim((string) ($config['origin'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $runId = trim((string) ($config['run_id'] ?? ''));
        $confirm = (string) ($config['confirm'] ?? '');
        if ($confirm !== self::CONFIRMATION) {
            throw new InvalidArgumentException('pasarguard_live_confirmation_invalid');
        }
        if (preg_match('/\Apg_key_[0-9a-fA-F-]{36}\z/', $apiKey) !== 1) {
            throw new InvalidArgumentException('pasarguard_live_api_key_invalid');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $runId) !== 1) {
            throw new InvalidArgumentException('pasarguard_live_run_id_invalid');
        }

        $parts = parse_url($originValue);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('pasarguard_live_origin_invalid');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('pasarguard_live_origin_host_invalid');
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $origin = 'https://'.$host.$port;
        $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($configuredPath === '/') {
            $configuredPath = '';
        }
        if ($configuredPath !== '' && preg_match('#\A/[A-Za-z0-9._~/-]+\z#', $configuredPath) !== 1) {
            throw new InvalidArgumentException('pasarguard_live_base_path_invalid');
        }

        $groupId = $config['group_id'] ?? null;
        if ($groupId !== null && (! is_int($groupId) || $groupId < 1)) {
            throw new InvalidArgumentException('pasarguard_live_group_id_invalid');
        }

        $now = $config['now'] ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (! $now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('pasarguard_live_clock_invalid');
        }

        return [$origin, $configuredPath, $apiKey, $runId, $groupId, $now];
    }

    /** @return array{0: string, 1: array<array-key, mixed>} */
    private function detectBasePath(string $origin, string $configuredPath, array $headers): array
    {
        foreach (array_values(array_unique([$configuredPath, '', '/hpanel'])) as $basePath) {
            $exchange = $this->request('GET', $origin.$basePath.'/api/system', $headers, null);
            if ($exchange->status !== 200 || $exchange->transportFailure || $exchange->malformedJson || $exchange->json === null) {
                continue;
            }

            return [$basePath, $exchange->json];
        }

        throw new RuntimeException('pasarguard_live_api_base_unavailable');
    }

    /** @return array<array-key, mixed> */
    private function readObject(string $origin, string $basePath, string $path, array $headers, string $code): array
    {
        $exchange = $this->request('GET', $origin.$basePath.$path, $headers, null);
        if ($exchange->status !== 200 || $exchange->transportFailure || $exchange->malformedJson || $exchange->json === null || array_is_list($exchange->json)) {
            throw new RuntimeException('pasarguard_live_'.$code.'_failed');
        }

        return $exchange->json;
    }

    /** @return list<mixed> */
    private function readList(string $origin, string $basePath, string $path, array $headers, string $code): array
    {
        $exchange = $this->request('GET', $origin.$basePath.$path, $headers, null);
        if ($exchange->status !== 200 || $exchange->transportFailure || $exchange->malformedJson || $exchange->json === null || ! array_is_list($exchange->json)) {
            throw new RuntimeException('pasarguard_live_'.$code.'_failed');
        }

        return $exchange->json;
    }

    private function selectGroupId(array $payload, ?int $configuredGroupId): int
    {
        $groups = $payload['groups'] ?? null;
        if (! is_array($groups) || ! array_is_list($groups)) {
            throw new RuntimeException('pasarguard_live_group_shape_invalid');
        }
        $enabled = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $id = $group['id'] ?? null;
            $disabled = $group['is_disabled'] ?? false;
            if (is_int($id) && $id > 0 && $disabled !== true) {
                $enabled[] = $id;
            }
        }
        sort($enabled, SORT_NUMERIC);
        if ($configuredGroupId !== null) {
            if (! in_array($configuredGroupId, $enabled, true)) {
                throw new RuntimeException('pasarguard_live_selected_group_unavailable');
            }

            return $configuredGroupId;
        }
        if ($enabled === []) {
            throw new RuntimeException('pasarguard_live_no_enabled_group');
        }

        return $enabled[0];
    }

    /** @return array<array-key, mixed> */
    private function issueMapped(
        string $origin,
        string $basePath,
        array $headers,
        PasarGuardMutationContractMapper $mapper,
        string $operation,
        PanelMappedMutationRequest $mapped,
    ): array {
        $exchange = $this->request($mapped->method, $origin.$basePath.$mapped->path, $headers, $mapped->payload);
        $outcome = $mapper->classifyMutation($operation, $exchange);
        if ($outcome->outcome !== PanelOperationOutcome::Success) {
            throw new RuntimeException($outcome->providerCode);
        }

        return $exchange->json ?? [];
    }

    /** @return array<array-key, mixed> */
    private function readUserByUsername(string $origin, string $basePath, array $headers, string $username): array
    {
        $exchange = $this->request('GET', $origin.$basePath.'/api/user/by-username/'.rawurlencode($username), $headers, null);
        if ($exchange->status !== 200 || $exchange->transportFailure || $exchange->malformedJson || $exchange->json === null) {
            throw new RuntimeException('pasarguard_live_username_lookup_failed');
        }

        return $exchange->json;
    }

    /** @return array<array-key, mixed> */
    private function readUserById(string $origin, string $basePath, array $headers, string $remoteId): array
    {
        $exchange = $this->request('GET', $origin.$basePath.'/api/user/by-id/'.$remoteId, $headers, null);
        if ($exchange->status !== 200 || $exchange->transportFailure || $exchange->malformedJson || $exchange->json === null) {
            throw new RuntimeException('pasarguard_live_id_lookup_failed');
        }

        return $exchange->json;
    }

    private function snapshotForAdd(array $payload): RemoteServiceSnapshot
    {
        $remoteId = (string) $this->requiredPositiveInt($payload, 'id');
        $username = $this->requiredString($payload, 'username');
        $dataLimit = $payload['data_limit'] ?? null;
        if (! is_int($dataLimit) || $dataLimit < 1) {
            throw new RuntimeException('pasarguard_live_add_baseline_invalid');
        }
        $used = $payload['used_traffic'] ?? null;
        if ($used !== null && (! is_int($used) || $used < 0)) {
            throw new RuntimeException('pasarguard_live_used_traffic_invalid');
        }
        $expiresAt = $this->parseExpiry($payload['expire'] ?? null);
        $status = match ($this->requiredString($payload, 'status')) {
            'active' => PanelServiceStatus::Active,
            'disabled' => PanelServiceStatus::Disabled,
            'expired' => PanelServiceStatus::Expired,
            'limited', 'on_hold' => PanelServiceStatus::Suspended,
            default => PanelServiceStatus::Unknown,
        };

        return new RemoteServiceSnapshot(
            $remoteId,
            $username,
            $status,
            $dataLimit,
            $used,
            $expiresAt,
            hash('sha256', json_encode(['remote_id' => $remoteId, 'data_limit' => $dataLimit], JSON_THROW_ON_ERROR)),
        );
    }

    private function cleanup(
        string $origin,
        string $basePath,
        array $headers,
        PasarGuardMutationContractMapper $mapper,
        string $remoteId,
    ): void {
        $discovery = $this->request('GET', $origin.$basePath.'/api/user/by-id/'.$remoteId, $headers, null);
        if ($discovery->status === 404 && ! $discovery->transportFailure) {
            return;
        }
        if ($discovery->status !== 200 || $discovery->transportFailure || $discovery->malformedJson) {
            throw new RuntimeException('pasarguard_live_cleanup_discovery_unavailable');
        }

        $delete = $this->request('DELETE', $origin.$basePath.$mapper->deleteRequest($remoteId)->path, $headers, null);
        $outcome = $mapper->classifyMutation('delete', $delete);
        $finalDiscovery = $this->request('GET', $origin.$basePath.'/api/user/by-id/'.$remoteId, $headers, null);
        if ($finalDiscovery->status === 404 && ! $finalDiscovery->transportFailure) {
            return;
        }
        if ($outcome->outcome !== PanelOperationOutcome::Success) {
            throw new RuntimeException('pasarguard_live_cleanup_unverified_after_'.$outcome->providerCode);
        }

        throw new RuntimeException('pasarguard_live_cleanup_unverified');
    }

    private function assertExpiry(array $payload, DateTimeImmutable $expected): void
    {
        $actual = $this->parseExpiry($payload['expire'] ?? null);
        if ($actual === null || $actual->getTimestamp() !== $expected->getTimestamp()) {
            throw new RuntimeException('pasarguard_live_expiry_mismatch');
        }
    }

    private function assertDataLimit(array $payload, int $expected): void
    {
        if (($payload['data_limit'] ?? null) !== $expected) {
            throw new RuntimeException('pasarguard_live_data_limit_mismatch');
        }
    }

    private function assertUsedTrafficReset(array $payload): void
    {
        if (($payload['used_traffic'] ?? null) !== 0) {
            throw new RuntimeException('pasarguard_live_usage_reset_unproved');
        }
    }

    private function assertStatus(array $payload, string $expected): void
    {
        if (($payload['status'] ?? null) !== $expected) {
            throw new RuntimeException('pasarguard_live_status_mismatch');
        }
    }

    private function parseExpiry(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return (new DateTimeImmutable('@'.$value))->setTimezone(new DateTimeZone('UTC'));
        }
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('pasarguard_live_expiry_shape_invalid');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new RuntimeException('pasarguard_live_expiry_shape_invalid');
        }
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '' || $value !== trim($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('pasarguard_live_response_string_invalid');
        }

        return $value;
    }

    private function requiredPositiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('pasarguard_live_response_integer_invalid');
        }

        return $value;
    }

    private function request(string $method, string $url, array $headers, ?array $payload): PanelHttpExchange
    {
        $exchange = ($this->request)($method, $url, $headers, $payload);
        if (! $exchange instanceof PanelHttpExchange) {
            throw new RuntimeException('pasarguard_live_transport_contract_invalid');
        }

        return $exchange;
    }
}
