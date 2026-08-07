<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\PasarGuardGateway;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Application\PanelAdapterSession;
use DateTimeImmutable;
use Throwable;

final class PasarGuardSourceContractGateway extends AbstractPinnedReadOnlyPanelGateway implements PasarGuardGateway
{
    private const VERSION = '5.2.1';

    public function __construct(
        private readonly PanelHttpTransport $transport,
        private readonly PanelAdapterSession $session,
    ) {}

    public function testConnection(): PanelOperationResult
    {
        try {
            $system = $this->systemStats();
            $version = $this->requiredString($system, 'version');
            if (ltrim($version, 'vV') !== self::VERSION) {
                return new PanelOperationResult(
                    PanelOperationOutcome::DefinitiveFailure,
                    null,
                    'pasarguard_version_mismatch',
                    'PasarGuard version does not match the pinned source contract.',
                );
            }

            return new PanelOperationResult(
                PanelOperationOutcome::Success,
                null,
                'pasarguard_source_contract_connection_ok',
                'PasarGuard pinned source contract connection succeeded.',
            );
        } catch (PanelGatewayRequestFailure $failure) {
            return $this->failureResult($failure);
        }
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities(
            'pasarguard',
            self::VERSION,
            [
                'authoritative_username_lookup',
                'fetch_status',
                'list_compatible_targets',
                'synchronize',
                'test_connection',
            ],
            ['shadowsocks', 'trojan', 'vless', 'vmess', 'wireguard'],
        );
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $remoteId) !== 1) {
            throw new AuthoritativePanelLookupUnavailable('pasarguard_remote_id_invalid');
        }

        try {
            $this->assertCompatibleVersion();
            $exchange = $this->authenticatedRequest('GET', '/api/user/by-id/'.$remoteId);
            if ($exchange->status === 404) {
                return null;
            }
            $this->assertSuccessful($exchange, 'lookup');

            return $this->snapshot($exchange->json ?? []);
        } catch (PanelGatewayRequestFailure $failure) {
            throw new AuthoritativePanelLookupUnavailable($failure->providerCode, previous: $failure);
        } catch (Throwable $throwable) {
            throw new AuthoritativePanelLookupUnavailable('pasarguard_lookup_unavailable', previous: $throwable);
        }
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        try {
            $this->assertCompatibleVersion();
            $exchange = $this->authenticatedRequest('GET', '/api/user/by-username/'.rawurlencode($username));
            if ($exchange->status === 404) {
                return null;
            }
            $this->assertSuccessful($exchange, 'lookup');

            return $this->snapshot($exchange->json ?? []);
        } catch (PanelGatewayRequestFailure $failure) {
            throw new AuthoritativePanelLookupUnavailable($failure->providerCode, previous: $failure);
        } catch (Throwable $throwable) {
            throw new AuthoritativePanelLookupUnavailable('pasarguard_lookup_unavailable', previous: $throwable);
        }
    }

    public function fetchStatus(string $remoteId): PanelOperationResult
    {
        try {
            $service = $this->findByRemoteId($remoteId);
            if ($service === null) {
                return new PanelOperationResult(
                    PanelOperationOutcome::DefinitiveFailure,
                    null,
                    'pasarguard_remote_not_found',
                    'PasarGuard remote service was not found.',
                );
            }

            return $this->success($service, 'pasarguard_service_found');
        } catch (AuthoritativePanelLookupUnavailable) {
            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'pasarguard_lookup_unavailable',
                'PasarGuard authoritative lookup is unavailable.',
            );
        }
    }

    public function synchronize(string $remoteId): PanelOperationResult
    {
        return $this->fetchStatus($remoteId);
    }

    public function listCompatibleTargets(): array
    {
        try {
            $this->assertCompatibleVersion();
            $exchange = $this->authenticatedRequest('GET', '/api/groups');
            $this->assertSuccessful($exchange, 'targets');
            $groups = $exchange->json['groups'] ?? null;
            if (! is_array($groups)) {
                throw new PanelGatewayRequestFailure(
                    PanelOperationOutcome::UncertainResult,
                    'pasarguard_targets_malformed_response',
                    'PasarGuard returned a malformed group response.',
                );
            }

            $targets = [];
            foreach ($groups as $group) {
                if (! is_array($group)) {
                    continue;
                }
                $id = $group['id'] ?? null;
                $name = $group['name'] ?? null;
                $disabled = $group['is_disabled'] ?? false;
                if (! is_int($id) || $id < 1 || ! is_string($name) || trim($name) === '' || $disabled === true) {
                    continue;
                }
                $targets[] = [
                    'id' => 'pasarguard-group-'.$id,
                    'type' => 'group',
                    'name' => $name,
                    'capabilities' => ['read_only_source_contract'],
                ];
            }

            usort($targets, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

            return $targets;
        } catch (Throwable) {
            return [];
        }
    }

    protected function providerCode(): string
    {
        return 'pasarguard';
    }

    /** @return array<array-key, mixed> */
    private function systemStats(): array
    {
        $exchange = $this->authenticatedRequest('GET', '/api/system');
        $this->assertSuccessful($exchange, 'system');

        return $exchange->json ?? [];
    }

    private function assertCompatibleVersion(): void
    {
        $version = $this->requiredString($this->systemStats(), 'version');
        if (ltrim($version, 'vV') !== self::VERSION) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::DefinitiveFailure,
                'pasarguard_version_mismatch',
                'PasarGuard version does not match the pinned source contract.',
            );
        }
    }

    private function authenticatedRequest(string $method, string $path): PanelHttpExchange
    {
        return $this->transport->request($method, $path, $this->authorizationHeaders());
    }

    /** @return array<string, string> */
    private function authorizationHeaders(): array
    {
        $credentials = $this->session->credentials->values;
        $apiKey = $credentials['api_key'] ?? $credentials['api_token'] ?? null;
        if (is_string($apiKey)) {
            if (preg_match('/\Apg_key_[0-9a-fA-F-]{36}\z/', $apiKey) !== 1) {
                throw new PanelGatewayRequestFailure(
                    PanelOperationOutcome::DefinitiveFailure,
                    'pasarguard_api_key_invalid',
                    'PasarGuard API key format is invalid.',
                );
            }

            return ['X-Api-Key' => $apiKey];
        }

        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        if (! is_string($username) || ! is_string($password)) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::DefinitiveFailure,
                'pasarguard_credentials_invalid',
                'PasarGuard credentials are unavailable.',
            );
        }

        $exchange = $this->transport->request(
            'POST',
            '/api/admin/token',
            payload: ['username' => $username, 'password' => $password],
            form: true,
        );
        $this->assertSuccessful($exchange, 'authentication');
        $token = $exchange->json['access_token'] ?? null;
        if (! is_string($token)
            || $token === ''
            || mb_strlen($token) > 8192
            || preg_match('/[\x00-\x20\x7F]/', $token) === 1
        ) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_authentication_malformed',
                'PasarGuard authentication returned an invalid token response.',
            );
        }

        return ['Authorization' => 'Bearer '.$token];
    }

    private function assertSuccessful(PanelHttpExchange $exchange, string $operation): void
    {
        if ($exchange->transportFailure || $exchange->status === null) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::RetryableFailure,
                'pasarguard_'.$operation.'_transport_failure',
                'PasarGuard request could not be completed safely.',
            );
        }
        if ($exchange->malformedJson) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_'.$operation.'_malformed_response',
                'PasarGuard returned a malformed response.',
            );
        }
        if ($exchange->successful()) {
            return;
        }

        $status = $exchange->status;
        $outcome = $status === 429 || $status >= 500
            ? PanelOperationOutcome::RetryableFailure
            : PanelOperationOutcome::DefinitiveFailure;

        throw new PanelGatewayRequestFailure(
            $outcome,
            'pasarguard_'.$operation.'_http_'.$status,
            'PasarGuard rejected or could not complete the request.',
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function snapshot(array $payload): RemoteServiceSnapshot
    {
        $remoteId = $payload['id'] ?? null;
        if (! is_int($remoteId) || $remoteId < 1) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_response_invalid',
                'PasarGuard response did not match the pinned source contract.',
            );
        }

        $username = $this->requiredString($payload, 'username');
        $status = $this->serviceStatus($this->requiredString($payload, 'status'));
        $dataLimit = $this->nullableNonNegativeInt($payload['data_limit'] ?? null);
        $usedBytes = $this->nullableNonNegativeInt($payload['used_traffic'] ?? null);
        $expiresAt = $this->expiresAt($payload['expire'] ?? null);

        $canonical = [
            'provider' => 'pasarguard',
            'version' => self::VERSION,
            'username' => $username,
            'status' => $status->value,
            'data_limit_bytes' => $dataLimit === 0 ? null : $dataLimit,
            'expires_at_unix' => $expiresAt?->getTimestamp(),
            'proxy_settings' => $payload['proxy_settings'] ?? [],
            'group_ids' => $payload['group_ids'] ?? [],
            'hwid_limit' => $payload['hwid_limit'] ?? null,
        ];

        return new RemoteServiceSnapshot(
            (string) $remoteId,
            $username,
            $status,
            $dataLimit === 0 ? null : $dataLimit,
            $usedBytes,
            $expiresAt,
            hash('sha256', json_encode($this->canonicalize($canonical), JSON_THROW_ON_ERROR)),
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_response_invalid',
                'PasarGuard response did not match the pinned source contract.',
            );
        }

        return $value;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_response_invalid',
                'PasarGuard response did not match the pinned source contract.',
            );
        }

        return $value;
    }

    private function expiresAt(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_response_invalid',
                'PasarGuard response did not match the pinned source contract.',
            );
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $throwable) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'pasarguard_response_invalid',
                'PasarGuard response did not match the pinned source contract.',
            );
        }
    }

    private function serviceStatus(string $status): PanelServiceStatus
    {
        return match ($status) {
            'active' => PanelServiceStatus::Active,
            'disabled' => PanelServiceStatus::Disabled,
            'expired' => PanelServiceStatus::Expired,
            'limited', 'on_hold' => PanelServiceStatus::Suspended,
            default => PanelServiceStatus::Unknown,
        };
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function success(RemoteServiceSnapshot $service, string $code): PanelOperationResult
    {
        return new PanelOperationResult(
            PanelOperationOutcome::Success,
            $service,
            $code,
            'PasarGuard source-contract read operation succeeded.',
        );
    }

    private function failureResult(PanelGatewayRequestFailure $failure): PanelOperationResult
    {
        return new PanelOperationResult(
            $failure->outcome,
            null,
            $failure->providerCode,
            $failure->getMessage(),
        );
    }
}
