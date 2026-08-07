<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;
use Throwable;

final class MarzbanMutationContractMapper
{
    public const VERSION = '0.8.4';

    private const TARGET_PREFIX = 'marzban-inbound-';

    /** @var list<string> */
    private const PROTOCOLS = ['shadowsocks', 'trojan', 'vless', 'vmess'];

    /** @var list<string> */
    private const OPERATIONS = [
        'create_service',
        'update_expiry',
        'update_data_allowance',
        'reset_usage',
        'suspend',
        'activate',
        'delete',
        'rotate_subscription_link',
    ];

    public function createRequest(PanelCreateServiceRequest $request): PanelMappedMutationRequest
    {
        $target = $this->decodeTargetReference($request->targetReference);

        return new PanelMappedMutationRequest('POST', '/api/user', [
            'username' => $this->username($request->username),
            'status' => 'active',
            'proxies' => [$target['protocol'] => new stdClass],
            'inbounds' => [$target['protocol'] => [$target['tag']]],
            'expire' => $this->requestExpiry($request->expiresAt),
            'data_limit' => $request->dataLimitBytes ?? 0,
            'data_limit_reset_strategy' => 'no_reset',
        ]);
    }

    public function updateExpiryRequest(string $remoteId, DateTimeImmutable $expiresAt): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/'.rawurlencode($this->username($remoteId)),
            ['expire' => $this->requestExpiry($expiresAt)],
        );
    }

    public function updateDataAllowanceRequest(
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
        ?RemoteServiceSnapshot $current = null,
    ): PanelMappedMutationRequest {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/'.rawurlencode($this->username($remoteId)),
            ['data_limit' => $this->absoluteDataLimit($remoteId, $bytes, $mode, $current)],
        );
    }

    public function resetUsageRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('POST', '/api/user/'.rawurlencode($this->username($remoteId)).'/reset');
    }

    public function suspendRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/'.rawurlencode($this->username($remoteId)),
            ['status' => 'disabled'],
        );
    }

    public function activateRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/'.rawurlencode($this->username($remoteId)),
            ['status' => 'active'],
        );
    }

    public function deleteRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('DELETE', '/api/user/'.rawurlencode($this->username($remoteId)));
    }

    public function rotateSubscriptionLinkRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('POST', '/api/user/'.rawurlencode($this->username($remoteId)).'/revoke_sub');
    }

    /** @param array<array-key, mixed> $providerService */
    public function providerCreateCanonicalHash(array $providerService): string
    {
        return $this->canonicalHash($this->providerCreateCanonicalState($providerService));
    }

    public function requestCreateCanonicalHash(PanelCreateServiceRequest $request): string
    {
        $target = $this->decodeTargetReference($request->targetReference);

        return $this->canonicalHash([
            'username' => $this->username($request->username),
            'status' => 'active',
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at_unix' => $request->expiresAt?->getTimestamp(),
            'data_limit_reset_strategy' => 'no_reset',
            'protocols' => [$target['protocol']],
            'inbounds' => [$target['protocol'] => [$target['tag']]],
        ]);
    }

    /** @param array<array-key, mixed> $providerService */
    public function createEquivalent(PanelCreateServiceRequest $request, array $providerService): bool
    {
        try {
            return hash_equals(
                $this->requestCreateCanonicalHash($request),
                $this->providerCreateCanonicalHash($providerService),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<array-key, mixed> $providerService */
    public function deliveryArtifacts(array $providerService): SensitiveDeliveryArtifacts
    {
        $subscriptionUrl = $this->validatedHttpsUrl($this->requiredString($providerService, 'subscription_url'));
        $links = [$subscriptionUrl];
        $providerLinks = $providerService['links'] ?? [];
        if (! is_array($providerLinks) || ! array_is_list($providerLinks)) {
            throw new InvalidArgumentException('Marzban delivery links do not match the pinned source contract.');
        }
        foreach ($providerLinks as $link) {
            if (! is_string($link)) {
                throw new InvalidArgumentException('Marzban delivery link is invalid.');
            }
            $links[] = $this->validatedConfigLink($link);
        }

        return new SensitiveDeliveryArtifacts(array_values(array_unique($links)));
    }

    public function classifyMutation(string $operation, PanelHttpExchange $exchange): PanelMappedMutationOutcome
    {
        $this->assertOperation($operation);
        $prefix = 'marzban_'.$operation;

        if ($exchange->transportFailure || $exchange->status === null) {
            return new PanelMappedMutationOutcome(
                PanelOperationOutcome::UncertainResult,
                $prefix.'_transport_uncertain',
                true,
                false,
            );
        }

        $status = $exchange->status;
        if ($status >= 200 && $status < 300) {
            if ($status !== 200 || $exchange->malformedJson || ! $this->validSuccessPayload($operation, $exchange->json)) {
                return new PanelMappedMutationOutcome(
                    PanelOperationOutcome::UncertainResult,
                    $prefix.'_success_response_unverified',
                    true,
                    false,
                );
            }

            return new PanelMappedMutationOutcome(PanelOperationOutcome::Success, $prefix.'_success', false, false);
        }
        if ($status === 409) {
            return new PanelMappedMutationOutcome(
                PanelOperationOutcome::DefinitiveFailure,
                $prefix.'_conflict_manual_review',
                false,
                true,
            );
        }
        if ($status === 429) {
            return new PanelMappedMutationOutcome(
                PanelOperationOutcome::RetryableFailure,
                $prefix.'_retryable_before_effect',
                false,
                false,
            );
        }
        if ($status >= 500) {
            return new PanelMappedMutationOutcome(
                PanelOperationOutcome::UncertainResult,
                $prefix.'_http_'.$status.'_uncertain',
                true,
                false,
            );
        }

        return new PanelMappedMutationOutcome(
            PanelOperationOutcome::DefinitiveFailure,
            $prefix.'_http_'.$status,
            false,
            false,
        );
    }

    /** @return array{protocol: string, tag: string} */
    private function decodeTargetReference(string $targetReference): array
    {
        if (! str_starts_with($targetReference, self::TARGET_PREFIX)) {
            throw new InvalidArgumentException('Marzban target reference is invalid.');
        }
        $encoded = substr($targetReference, strlen(self::TARGET_PREFIX));
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $encoded) !== 1) {
            throw new InvalidArgumentException('Marzban target reference is invalid.');
        }
        $padding = (4 - strlen($encoded) % 4) % 4;
        $decoded = base64_decode(strtr($encoded.str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Marzban target reference is invalid.');
        }
        try {
            $target = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Marzban target reference is invalid.');
        }
        if (! is_array($target) || array_keys($target) !== ['protocol', 'tag']) {
            throw new InvalidArgumentException('Marzban target reference is invalid.');
        }
        $protocol = $target['protocol'] ?? null;
        $tag = $target['tag'] ?? null;
        if (! is_string($protocol) || ! in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException('Marzban target protocol is invalid.');
        }
        if (! is_string($tag)
            || $tag === ''
            || $tag !== trim($tag)
            || mb_strlen($tag) > 512
            || preg_match('/[\x00-\x1F\x7F]/', $tag) === 1
        ) {
            throw new InvalidArgumentException('Marzban target tag is invalid.');
        }

        return ['protocol' => $protocol, 'tag' => $tag];
    }

    private function requestExpiry(?DateTimeImmutable $expiresAt): int
    {
        if ($expiresAt === null) {
            return 0;
        }
        $timestamp = $expiresAt->getTimestamp();
        if ($timestamp < 1) {
            throw new InvalidArgumentException('Provider expiry must be positive when it is not unlimited.');
        }

        return $timestamp;
    }

    private function absoluteDataLimit(
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
        ?RemoteServiceSnapshot $current,
    ): int {
        $this->username($remoteId);
        if ($bytes < 1) {
            throw new InvalidArgumentException('Provider data mutation must be a positive byte count.');
        }
        if ($mode === DataAllowanceMode::Set) {
            return $bytes;
        }
        if ($current === null
            || ! hash_equals($remoteId, $current->remoteId)
            || $current->dataLimitBytes === null
        ) {
            throw new InvalidArgumentException('Authoritative lookup is required before additive provider data mutation.');
        }
        if ($current->dataLimitBytes > PHP_INT_MAX - $bytes) {
            throw new InvalidArgumentException('Provider data mutation exceeds the supported integer range.');
        }

        return $current->dataLimitBytes + $bytes;
    }

    private function username(string $value): string
    {
        if (preg_match('/\A(?=.{3,32}\z)[A-Za-z0-9_.@-]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('Marzban username does not match the pinned source contract.');
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array{username: string, status: string, data_limit_bytes: ?int, expires_at_unix: ?int, data_limit_reset_strategy: string, protocols: list<string>, inbounds: array<string, list<string>>}
     */
    private function providerCreateCanonicalState(array $payload): array
    {
        $username = $this->username($this->requiredString($payload, 'username'));
        $status = $this->requiredString($payload, 'status');
        if (! in_array($status, ['active', 'disabled', 'expired', 'limited', 'on_hold'], true)) {
            throw new InvalidArgumentException('Marzban status does not match the pinned source contract.');
        }
        $resetStrategy = $this->requiredString($payload, 'data_limit_reset_strategy');
        if (! in_array($resetStrategy, ['no_reset', 'day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('Marzban reset strategy does not match the pinned source contract.');
        }
        $proxies = $payload['proxies'] ?? null;
        $inbounds = $payload['inbounds'] ?? null;
        if (! is_array($proxies) || $proxies === [] || ! is_array($inbounds)) {
            throw new InvalidArgumentException('Marzban protocol mapping does not match the pinned source contract.');
        }
        $protocols = [];
        foreach (array_keys($proxies) as $protocol) {
            if (! is_string($protocol) || ! in_array($protocol, self::PROTOCOLS, true)) {
                throw new InvalidArgumentException('Marzban proxy protocol does not match the pinned source contract.');
            }
            $protocols[] = $protocol;
        }
        sort($protocols, SORT_STRING);

        /** @var array<string, list<string>> $normalizedInbounds */
        $normalizedInbounds = [];
        foreach ($inbounds as $protocol => $tags) {
            if (! is_string($protocol) || ! in_array($protocol, $protocols, true) || ! is_array($tags) || ! array_is_list($tags)) {
                throw new InvalidArgumentException('Marzban inbound mapping does not match the pinned source contract.');
            }
            $normalizedTags = [];
            foreach ($tags as $tag) {
                if (! is_string($tag) || $tag === '' || $tag !== trim($tag)) {
                    throw new InvalidArgumentException('Marzban inbound tag does not match the pinned source contract.');
                }
                $normalizedTags[] = $tag;
            }
            sort($normalizedTags, SORT_STRING);
            $normalizedInbounds[$protocol] = $normalizedTags;
        }
        ksort($normalizedInbounds, SORT_STRING);

        return [
            'username' => $username,
            'status' => $status,
            'data_limit_bytes' => $this->nullableLimit($payload['data_limit'] ?? null),
            'expires_at_unix' => $this->nullableExpire($payload['expire'] ?? null),
            'data_limit_reset_strategy' => $resetStrategy,
            'protocols' => $protocols,
            'inbounds' => $normalizedInbounds,
        ];
    }

    private function nullableLimit(mixed $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Marzban data limit does not match the pinned source contract.');
        }

        return $value;
    }

    private function nullableExpire(mixed $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Marzban expiry does not match the pinned source contract.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Marzban response does not match the pinned source contract.');
        }

        return $value;
    }

    /** @param array<string, mixed> $state */
    private function canonicalHash(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function validatedHttpsUrl(string $value): string
    {
        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('Marzban subscription URL is invalid.');
        }

        return $value;
    }

    private function validatedConfigLink(string $value): string
    {
        if ($value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 8192
            || preg_match('/[\x00-\x20\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Marzban config delivery link is invalid.');
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if (! in_array($scheme, ['ss', 'trojan', 'vless', 'vmess'], true)) {
            throw new InvalidArgumentException('Marzban config delivery link is invalid.');
        }

        return $value;
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('Marzban mutation operation is invalid.');
        }
    }

    /** @param array<array-key, mixed>|null $json */
    private function validSuccessPayload(string $operation, ?array $json): bool
    {
        if ($operation === 'delete') {
            return $json !== null;
        }
        if ($json === null) {
            return false;
        }
        try {
            $this->providerCreateCanonicalHash($json);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
