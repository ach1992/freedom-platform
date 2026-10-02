<?php

declare(strict_types=1);

namespace FreedomPlatform\Scripts\Ci;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Infrastructure\MarzbanMutationContractMapper;
use App\Modules\Panels\Infrastructure\PanelHttpExchange;
use App\Modules\Panels\Infrastructure\PanelMappedMutationRequest;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class MarzbanLiveAcceptance
{
    public const CONFIRMATION = 'MUTATE_DISPOSABLE_MARZBAN_V0_8_4';

    private const CREATE_LIMIT_BYTES = 1_048_576;

    private const SET_LIMIT_BYTES = 2_097_152;

    private const ADD_LIMIT_BYTES = 1_048_576;

    /** @var list<string> */
    private const PROTOCOLS = ['shadowsocks', 'trojan', 'vless', 'vmess'];

    private readonly Closure $request;

    public function __construct(callable $request)
    {
        $this->request = Closure::fromCallable($request);
    }

    /**
     * @param  array{origin: string, username: string, password: string, run_id: string, confirm: string, now?: DateTimeImmutable}  $config
     * @return array<string, mixed>
     */
    public function run(array $config): array
    {
        [$origin, $basePath, $adminUsername, $password, $runId, $now] = $this->validateConfig($config);
        $token = $this->authenticate($origin, $basePath, $adminUsername, $password);
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'FreedomPlatform-Marzban-Live-Acceptance/1.0',
        ];

        $system = $this->readObject($origin, $basePath, '/api/system', $headers, 'system');
        $version = $this->requiredString($system, 'version');
        if (ltrim($version, 'vV') !== MarzbanMutationContractMapper::VERSION) {
            throw new RuntimeException('marzban_live_version_mismatch');
        }

        $inbounds = $this->readObject($origin, $basePath, '/api/inbounds', $headers, 'inbound_discovery');
        [$targetReference, $targetProtocol, $targetHash, $inboundCount] = $this->selectTarget($inbounds);

        $username = 'fp_mz_'.substr(hash('sha256', $runId), 0, 12);
        $usernameHash = hash('sha256', $username);
        $mapper = new MarzbanMutationContractMapper;
        $createdAt = $now->setTimezone(new DateTimeZone('UTC'));
        $createExpiry = $createdAt->modify('+30 minutes');
        $updatedExpiry = $createdAt->modify('+60 minutes');
        $request = new PanelCreateServiceRequest(
            'marzban-live-create-'.$runId,
            'marzban-live-idem-'.$runId,
            $username,
            $targetReference,
            self::CREATE_LIMIT_BYTES,
            $createExpiry,
            [],
        );

        $remoteId = null;
        $cleanupVerified = false;
        $executedRows = ['LIVE-001', 'LIVE-002', 'LIVE-003', 'LIVE-004'];

        try {
            $lookup = $this->request('GET', $origin.$basePath.'/api/user/'.rawurlencode($username), $headers, null, false);
            if ($lookup->status !== 404 || $lookup->transportFailure) {
                throw new RuntimeException('marzban_live_initial_absence_unproved');
            }
            $executedRows[] = 'LIVE-005';

            // The deterministic username is the Marzban remote identifier. Set it before the
            // create call so an ambiguous create response can be resolved by authoritative lookup
            // and cleanup without blindly issuing a second create.
            $remoteId = $username;
            $create = $this->issueMapped($origin, $basePath, $headers, $mapper, 'create_service', $mapper->createRequest($request));
            if (! $mapper->createEquivalent($request, $create)) {
                throw new RuntimeException('marzban_live_create_equivalence_failed');
            }
            $executedRows[] = 'LIVE-006';

            $postCreate = $this->readUser($origin, $basePath, $headers, $username);
            if ($this->requiredString($postCreate, 'username') !== $username || ! $mapper->createEquivalent($request, $postCreate)) {
                throw new RuntimeException('marzban_live_post_create_read_mismatch');
            }
            $executedRows[] = 'LIVE-007';

            $mismatchRequest = new PanelCreateServiceRequest(
                'marzban-live-mismatch-'.$runId,
                'marzban-live-mismatch-idem-'.$runId,
                $username,
                $targetReference,
                self::CREATE_LIMIT_BYTES + 1,
                $createExpiry,
                [],
            );
            if ($mapper->createEquivalent($mismatchRequest, $postCreate)) {
                throw new RuntimeException('marzban_live_mismatch_was_not_detected');
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
            $this->assertExpiry($this->readUser($origin, $basePath, $headers, $remoteId), $updatedExpiry);
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
            $current = $this->readUser($origin, $basePath, $headers, $remoteId);
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
            $this->assertDataLimit($this->readUser($origin, $basePath, $headers, $remoteId), $expectedAdded);
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
            $this->assertUsedTrafficReset($this->readUser($origin, $basePath, $headers, $remoteId));
            $executedRows[] = 'LIVE-014';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'suspend', $mapper->suspendRequest($remoteId));
            $this->assertStatus($this->readUser($origin, $basePath, $headers, $remoteId), 'disabled');
            $executedRows[] = 'LIVE-015';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'activate', $mapper->activateRequest($remoteId));
            $active = $this->readUser($origin, $basePath, $headers, $remoteId);
            $this->assertStatus($active, 'active');
            $executedRows[] = 'LIVE-016';

            $beforeArtifacts = $mapper->deliveryArtifacts($active);
            $beforeLinks = $beforeArtifacts->revealForAuthorizedDelivery();
            sort($beforeLinks, SORT_STRING);
            $beforeHash = hash('sha256', json_encode($beforeLinks, JSON_THROW_ON_ERROR));
            if ((string) $beforeArtifacts !== '[SENSITIVE_DELIVERY_ARTIFACTS]') {
                throw new RuntimeException('marzban_live_delivery_redaction_failed');
            }

            $this->issueMapped(
                $origin,
                $basePath,
                $headers,
                $mapper,
                'rotate_subscription_link',
                $mapper->rotateSubscriptionLinkRequest($remoteId),
            );
            $rotated = $this->readUser($origin, $basePath, $headers, $remoteId);
            $afterArtifacts = $mapper->deliveryArtifacts($rotated);
            $afterLinks = $afterArtifacts->revealForAuthorizedDelivery();
            sort($afterLinks, SORT_STRING);
            $afterHash = hash('sha256', json_encode($afterLinks, JSON_THROW_ON_ERROR));
            if (hash_equals($beforeHash, $afterHash)) {
                throw new RuntimeException('marzban_live_subscription_rotation_unproved');
            }
            if ((string) $afterArtifacts !== '[SENSITIVE_DELIVERY_ARTIFACTS]') {
                throw new RuntimeException('marzban_live_delivery_redaction_failed');
            }
            $executedRows[] = 'LIVE-017';
            $executedRows[] = 'LIVE-018';

            $this->issueMapped($origin, $basePath, $headers, $mapper, 'delete', $mapper->deleteRequest($remoteId));
            $finalLookup = $this->request('GET', $origin.$basePath.'/api/user/'.rawurlencode($remoteId), $headers, null, false);
            if ($finalLookup->status !== 404 || $finalLookup->transportFailure) {
                throw new RuntimeException('marzban_live_final_absence_unproved');
            }
            $cleanupVerified = true;
            $remoteId = null;
            $executedRows[] = 'LIVE-022';
            $executedRows[] = 'LIVE-023';

            return [
                'provider' => 'marzban',
                'version' => MarzbanMutationContractMapper::VERSION,
                'base_path' => $basePath === '' ? '/' : $basePath,
                'authentication' => 'username_password_bearer',
                'target_protocol' => $targetProtocol,
                'target_sha256' => $targetHash,
                'inbound_count' => $inboundCount,
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

    /** @return array{0:string,1:string,2:string,3:string,4:string,5:DateTimeImmutable} */
    private function validateConfig(array $config): array
    {
        $originValue = trim((string) ($config['origin'] ?? ''));
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $runId = trim((string) ($config['run_id'] ?? ''));
        $confirm = (string) ($config['confirm'] ?? '');

        if ($confirm !== self::CONFIRMATION) {
            throw new InvalidArgumentException('marzban_live_confirmation_invalid');
        }
        if ($username === ''
            || $username !== trim($username)
            || strlen($username) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
        ) {
            throw new InvalidArgumentException('marzban_live_username_invalid');
        }
        if (trim($password) === ''
            || strlen($password) > 4096
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password) === 1
        ) {
            throw new InvalidArgumentException('marzban_live_password_invalid');
        }
        if (preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $runId) !== 1) {
            throw new InvalidArgumentException('marzban_live_run_id_invalid');
        }

        $parts = parse_url($originValue);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('marzban_live_origin_invalid');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException('marzban_live_origin_host_invalid');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('marzban_live_origin_port_invalid');
        }
        $configuredPath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($configuredPath === '/') {
            $configuredPath = '';
        }
        if ($configuredPath !== '' && preg_match('#\A/[A-Za-z0-9._~/-]+\z#', $configuredPath) !== 1) {
            throw new InvalidArgumentException('marzban_live_base_path_invalid');
        }
        $portSuffix = $port === 443 ? '' : ':'.$port;
        $origin = 'https://'.$host.$portSuffix;

        $now = $config['now'] ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (! $now instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('marzban_live_clock_invalid');
        }

        return [$origin, $configuredPath, $username, $password, $runId, $now];
    }

    private function authenticate(string $origin, string $basePath, string $username, string $password): string
    {
        $exchange = $this->request(
            'POST',
            $origin.$basePath.'/api/admin/token',
            ['Accept' => 'application/json'],
            ['username' => $username, 'password' => $password],
            true,
        );
        if ($exchange->transportFailure || $exchange->status === null) {
            throw new RuntimeException('marzban_live_authentication_transport_failure');
        }
        if ($exchange->status !== 200 || $exchange->malformedJson || $exchange->json === null || array_is_list($exchange->json)) {
            throw new RuntimeException('marzban_live_authentication_failed');
        }
        $token = $exchange->json['access_token'] ?? null;
        if (! is_string($token)
            || $token === ''
            || strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7F]/', $token) === 1
        ) {
            throw new RuntimeException('marzban_live_authentication_malformed');
        }

        return $token;
    }

    /** @return array<array-key, mixed> */
    private function readObject(string $origin, string $basePath, string $path, array $headers, string $code): array
    {
        $exchange = $this->request('GET', $origin.$basePath.$path, $headers, null, false);
        if ($exchange->status !== 200
            || $exchange->transportFailure
            || $exchange->malformedJson
            || $exchange->json === null
            || array_is_list($exchange->json)
        ) {
            throw new RuntimeException('marzban_live_'.$code.'_failed');
        }

        return $exchange->json;
    }

    /** @return array{0:string,1:string,2:string,3:int} */
    private function selectTarget(array $inbounds): array
    {
        $candidates = [];
        foreach ($inbounds as $protocol => $entries) {
            if (! is_string($protocol) || ! in_array($protocol, self::PROTOCOLS, true)) {
                continue;
            }
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new RuntimeException('marzban_live_inbound_shape_invalid');
            }
            foreach ($entries as $entry) {
                $tag = is_array($entry) ? ($entry['tag'] ?? null) : null;
                if (! is_string($tag)
                    || $tag === ''
                    || $tag !== trim($tag)
                    || mb_strlen($tag) > 512
                    || preg_match('/[\x00-\x1F\x7F]/', $tag) === 1
                ) {
                    throw new RuntimeException('marzban_live_inbound_shape_invalid');
                }
                $reference = $this->targetReference($protocol, $tag);
                $candidates[] = ['protocol' => $protocol, 'reference' => $reference];
            }
        }
        if ($candidates === []) {
            throw new RuntimeException('marzban_live_no_compatible_inbound');
        }
        usort($candidates, static fn (array $left, array $right): int => strcmp($left['reference'], $right['reference']));
        $selected = $candidates[0];

        return [
            $selected['reference'],
            $selected['protocol'],
            hash('sha256', $selected['reference']),
            count($candidates),
        ];
    }

    private function targetReference(string $protocol, string $tag): string
    {
        $encoded = json_encode(['protocol' => $protocol, 'tag' => $tag], JSON_THROW_ON_ERROR);

        return 'marzban-inbound-'.rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
    }

    /** @return array<array-key, mixed> */
    private function issueMapped(
        string $origin,
        string $basePath,
        array $headers,
        MarzbanMutationContractMapper $mapper,
        string $operation,
        PanelMappedMutationRequest $mapped,
    ): array {
        $exchange = $this->request($mapped->method, $origin.$basePath.$mapped->path, $headers, $mapped->payload, false);
        $outcome = $mapper->classifyMutation($operation, $exchange);
        if ($outcome->outcome !== PanelOperationOutcome::Success) {
            throw new RuntimeException($outcome->providerCode);
        }

        return $exchange->json ?? [];
    }

    /** @return array<array-key, mixed> */
    private function readUser(string $origin, string $basePath, array $headers, string $remoteId): array
    {
        $exchange = $this->request('GET', $origin.$basePath.'/api/user/'.rawurlencode($remoteId), $headers, null, false);
        if ($exchange->status !== 200
            || $exchange->transportFailure
            || $exchange->malformedJson
            || $exchange->json === null
            || array_is_list($exchange->json)
        ) {
            throw new RuntimeException('marzban_live_user_lookup_failed');
        }

        return $exchange->json;
    }

    private function snapshotForAdd(array $payload): RemoteServiceSnapshot
    {
        $remoteId = $this->requiredString($payload, 'username');
        $dataLimit = $payload['data_limit'] ?? null;
        if (! is_int($dataLimit) || $dataLimit < 1) {
            throw new RuntimeException('marzban_live_add_baseline_invalid');
        }
        $used = $payload['used_traffic'] ?? null;
        if ($used !== null && (! is_int($used) || $used < 0)) {
            throw new RuntimeException('marzban_live_used_traffic_invalid');
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
            $remoteId,
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
        MarzbanMutationContractMapper $mapper,
        string $remoteId,
    ): void {
        $discovery = $this->request('GET', $origin.$basePath.'/api/user/'.rawurlencode($remoteId), $headers, null, false);
        if ($discovery->status === 404 && ! $discovery->transportFailure) {
            return;
        }
        if ($discovery->status !== 200 || $discovery->transportFailure || $discovery->malformedJson) {
            throw new RuntimeException('marzban_live_cleanup_discovery_unavailable');
        }

        $mapped = $mapper->deleteRequest($remoteId);
        $delete = $this->request($mapped->method, $origin.$basePath.$mapped->path, $headers, $mapped->payload, false);
        $outcome = $mapper->classifyMutation('delete', $delete);
        $finalDiscovery = $this->request('GET', $origin.$basePath.'/api/user/'.rawurlencode($remoteId), $headers, null, false);
        if ($finalDiscovery->status === 404 && ! $finalDiscovery->transportFailure) {
            return;
        }
        if ($outcome->outcome !== PanelOperationOutcome::Success) {
            throw new RuntimeException('marzban_live_cleanup_unverified_after_'.$outcome->providerCode);
        }

        throw new RuntimeException('marzban_live_cleanup_unverified');
    }

    private function assertExpiry(array $payload, DateTimeImmutable $expected): void
    {
        $actual = $this->parseExpiry($payload['expire'] ?? null);
        if ($actual === null || $actual->getTimestamp() !== $expected->getTimestamp()) {
            throw new RuntimeException('marzban_live_expiry_mismatch');
        }
    }

    private function assertDataLimit(array $payload, int $expected): void
    {
        if (($payload['data_limit'] ?? null) !== $expected) {
            throw new RuntimeException('marzban_live_data_limit_mismatch');
        }
    }

    private function assertUsedTrafficReset(array $payload): void
    {
        if (($payload['used_traffic'] ?? null) !== 0) {
            throw new RuntimeException('marzban_live_usage_reset_unproved');
        }
    }

    private function assertStatus(array $payload, string $expected): void
    {
        if (($payload['status'] ?? null) !== $expected) {
            throw new RuntimeException('marzban_live_status_mismatch');
        }
    }

    private function parseExpiry(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException('marzban_live_expiry_shape_invalid');
        }

        return (new DateTimeImmutable('@'.$value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new RuntimeException('marzban_live_response_string_invalid');
        }

        return $value;
    }

    private function request(string $method, string $url, array $headers, ?array $payload, bool $form): PanelHttpExchange
    {
        $exchange = ($this->request)($method, $url, $headers, $payload, $form);
        if (! $exchange instanceof PanelHttpExchange) {
            throw new RuntimeException('marzban_live_transport_contract_invalid');
        }

        return $exchange;
    }
}
