<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\MarzbanGateway;
use App\Modules\Panels\Application\Contracts\PanelCapabilities;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Exceptions\AuthoritativePanelLookupUnavailable;
use App\Modules\Panels\Application\PanelAdapterSession;
use DateTimeImmutable;
use Throwable;

final class MarzbanSourceContractGateway extends AbstractPinnedReadOnlyPanelGateway implements MarzbanGateway
{
    private const VERSION = '0.8.4';

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
                    'marzban_version_mismatch',
                    'Marzban version does not match the pinned source contract.',
                );
            }

            return new PanelOperationResult(
                PanelOperationOutcome::Success,
                null,
                'marzban_source_contract_connection_ok',
                'Marzban pinned source contract connection succeeded.',
            );
        } catch (PanelGatewayRequestFailure $failure) {
            return $this->failureResult($failure);
        }
    }

    public function capabilities(): PanelCapabilities
    {
        return new PanelCapabilities(
            'marzban',
            self::VERSION,
            [
                'authoritative_username_lookup',
                'fetch_status',
                'list_compatible_targets',
                'synchronize',
                'test_connection',
            ],
            ['shadowsocks', 'trojan', 'vless', 'vmess'],
        );
    }

    public function findByRemoteId(string $remoteId): ?RemoteServiceSnapshot
    {
        return $this->findByDeterministicUsername($remoteId);
    }

    public function findByDeterministicUsername(string $username): ?RemoteServiceSnapshot
    {
        try {
            $this->assertCompatibleVersion();
            $exchange = $this->authenticatedRequest('GET', '/api/user/'.rawurlencode($username));
            if ($exchange->status === 404) {
                return null;
            }
            $this->assertSuccessful($exchange, 'lookup');

            return $this->snapshot($exchange->json ?? []);
        } catch (PanelGatewayRequestFailure $failure) {
            throw new AuthoritativePanelLookupUnavailable($failure->providerCode, previous: $failure);
        } catch (Throwable $throwable) {
            throw new AuthoritativePanelLookupUnavailable('marzban_lookup_unavailable', previous: $throwable);
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
                    'marzban_remote_not_found',
                    'Marzban remote service was not found.',
                );
            }

            return $this->success($service, 'marzban_service_found');
        } catch (AuthoritativePanelLookupUnavailable) {
            return new PanelOperationResult(
                PanelOperationOutcome::UncertainResult,
                null,
                'marzban_lookup_unavailable',
                'Marzban authoritative lookup is unavailable.',
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
            $exchange = $this->authenticatedRequest('GET', '/api/inbounds');
            $this->assertSuccessful($exchange, 'targets');
            $json = $exchange->json ?? [];

            $targets = [];
            foreach ($json as $protocol => $inbounds) {
                if (! is_string($protocol) || ! is_array($inbounds)) {
                    continue;
                }
                foreach ($inbounds as $inbound) {
                    if (! is_array($inbound)) {
                        continue;
                    }
                    $tag = $inbound['tag'] ?? null;
                    if (! is_string($tag) || trim($tag) === '') {
                        continue;
                    }
                    $reference = $this->encodeTargetReference($protocol, $tag);
                    $targets[] = [
                        'id' => $reference,
                        'type' => 'inbound',
                        'name' => $tag,
                        'capabilities' => ['read_only_source_contract'],
                    ];
                }
            }

            usort($targets, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

            return $targets;
        } catch (Throwable) {
            return [];
        }
    }

    protected function providerCode(): string
    {
        return 'marzban';
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
                'marzban_version_mismatch',
                'Marzban version does not match the pinned source contract.',
            );
        }
    }

    private function authenticatedRequest(string $method, string $path): PanelHttpExchange
    {
        $token = $this->accessToken();

        return $this->transport->request(
            $method,
            $path,
            ['Authorization' => 'Bearer '.$token],
        );
    }

    private function accessToken(): string
    {
        $credentials = $this->session->credentials->values;
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        if (! is_string($username) || ! is_string($password)) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::DefinitiveFailure,
                'marzban_credentials_invalid',
                'Marzban credentials are unavailable.',
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
                'marzban_authentication_malformed',
                'Marzban authentication returned an invalid token response.',
            );
        }

        return $token;
    }

    private function assertSuccessful(PanelHttpExchange $exchange, string $operation): void
    {
        if ($exchange->transportFailure || $exchange->status === null) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::RetryableFailure,
                'marzban_'.$operation.'_transport_failure',
                'Marzban request could not be completed safely.',
            );
        }
        if ($exchange->malformedJson) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'marzban_'.$operation.'_malformed_response',
                'Marzban returned a malformed response.',
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
            'marzban_'.$operation.'_http_'.$status,
            'Marzban rejected or could not complete the request.',
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function snapshot(array $payload): RemoteServiceSnapshot
    {
        $username = $this->requiredString($payload, 'username');
        $status = $this->serviceStatus($this->requiredString($payload, 'status'));
        $dataLimit = $this->nullableNonNegativeInt($payload['data_limit'] ?? null, 'data_limit');
        $usedBytes = $this->nullableNonNegativeInt($payload['used_traffic'] ?? null, 'used_traffic');
        $expire = $this->nullableNonNegativeInt($payload['expire'] ?? null, 'expire');
        $expiresAt = $expire === null || $expire === 0 ? null : new DateTimeImmutable('@'.$expire);

        $canonical = [
            'provider' => 'marzban',
            'version' => self::VERSION,
            'username' => $username,
            'status' => $status->value,
            'data_limit_bytes' => $dataLimit === 0 ? null : $dataLimit,
            'expires_at_unix' => $expiresAt?->getTimestamp(),
            'proxies' => $payload['proxies'] ?? [],
            'inbounds' => $payload['inbounds'] ?? [],
        ];

        return new RemoteServiceSnapshot(
            $username,
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
                'marzban_response_invalid',
                'Marzban response did not match the pinned source contract.',
            );
        }

        return $value;
    }

    private function nullableNonNegativeInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new PanelGatewayRequestFailure(
                PanelOperationOutcome::UncertainResult,
                'marzban_response_invalid',
                'Marzban response did not match the pinned source contract.',
            );
        }

        return $value;
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

    private function encodeTargetReference(string $protocol, string $tag): string
    {
        $encoded = json_encode(['protocol' => $protocol, 'tag' => $tag], JSON_THROW_ON_ERROR);

        return 'marzban-inbound-'.rtrim(strtr(base64_encode($encoded), '+/', '-_'), '=');
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
            'Marzban source-contract read operation succeeded.',
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
