<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use App\Modules\Panels\Application\Contracts\DataAllowanceMode;
use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\RemoteServiceSnapshot;
use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class PasarGuardMutationContractMapper
{
    public const VERSION = '5.2.1';

    private const TARGET_PREFIX = 'pasarguard-group-';

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
        return new PanelMappedMutationRequest('POST', '/api/user', [
            'username' => $this->username($request->username),
            'expire' => $this->requestExpiry($request->expiresAt),
            'data_limit' => $request->dataLimitBytes ?? 0,
            'data_limit_reset_strategy' => 'no_reset',
            'group_ids' => [$this->groupId($request->targetReference)],
            'status' => 'active',
        ]);
    }

    public function updateExpiryRequest(string $remoteId, DateTimeImmutable $expiresAt): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/by-id/'.$this->remoteId($remoteId),
            ['expire' => $this->requestExpiry($expiresAt)],
        );
    }

    public function updateDataAllowanceRequest(
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
        ?RemoteServiceSnapshot $current = null,
    ): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/by-id/'.$this->remoteId($remoteId),
            ['data_limit' => $this->absoluteDataLimit($remoteId, $bytes, $mode, $current)],
        );
    }

    public function resetUsageRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('POST', '/api/user/by-id/'.$this->remoteId($remoteId).'/reset');
    }

    public function suspendRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/by-id/'.$this->remoteId($remoteId).'/disabled',
            ['disabled' => true],
        );
    }

    public function activateRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest(
            'PUT',
            '/api/user/by-id/'.$this->remoteId($remoteId).'/disabled',
            ['disabled' => false],
        );
    }

    public function deleteRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('DELETE', '/api/user/by-id/'.$this->remoteId($remoteId));
    }

    public function rotateSubscriptionLinkRequest(string $remoteId): PanelMappedMutationRequest
    {
        return new PanelMappedMutationRequest('POST', '/api/user/by-id/'.$this->remoteId($remoteId).'/revoke_sub');
    }

    /** @param  array<array-key, mixed>  $providerService */
    public function providerCreateCanonicalHash(array $providerService): string
    {
        return $this->canonicalHash($this->providerCreateCanonicalState($providerService));
    }

    public function requestCreateCanonicalHash(PanelCreateServiceRequest $request): string
    {
        return $this->canonicalHash([
            'username' => $this->username($request->username),
            'status' => 'active',
            'data_limit_bytes' => $request->dataLimitBytes,
            'expires_at_unix' => $request->expiresAt?->getTimestamp(),
            'data_limit_reset_strategy' => 'no_reset',
            'group_ids' => [$this->groupId($request->targetReference)],
        ]);
    }

    /** @param  array<array-key, mixed>  $providerService */
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

    /** @param  array<array-key, mixed>  $providerService */
    public function deliveryArtifacts(array $providerService): SensitiveDeliveryArtifacts
    {
        return new SensitiveDeliveryArtifacts([
            $this->validatedHttpsUrl($this->requiredString($providerService, 'subscription_url')),
        ]);
    }

    public function classifyMutation(string $operation, PanelHttpExchange $exchange): PanelMappedMutationOutcome
    {
        $this->assertOperation($operation);
        $prefix = 'pasarguard_'.$operation;

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
            $expectedStatus = match ($operation) {
                'create_service' => 201,
                'delete' => 204,
                default => 200,
            };
            if ($status !== $expectedStatus || $exchange->malformedJson || ! $this->validSuccessPayload($operation, $exchange->json)) {
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

    private function groupId(string $targetReference): int
    {
        if (! str_starts_with($targetReference, self::TARGET_PREFIX)) {
            throw new InvalidArgumentException('PasarGuard target reference is invalid.');
        }
        $value = substr($targetReference, strlen(self::TARGET_PREFIX));
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new InvalidArgumentException('PasarGuard target group identifier is invalid.');
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($id)) {
            throw new InvalidArgumentException('PasarGuard target group identifier is invalid.');
        }

        return $id;
    }

    private function remoteId(string $value): string
    {
        if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new InvalidArgumentException('PasarGuard remote identifier is invalid.');
        }

        return $value;
    }

    private function username(string $value): string
    {
        if (preg_match('/\A(?=.{3,32}\z)[A-Za-z0-9_.@-]+\z/', $value) !== 1) {
            throw new InvalidArgumentException('PasarGuard username does not match the pinned source contract.');
        }

        return $value;
    }

    private function requestExpiry(?DateTimeImmutable $expiresAt): string|int
    {
        if ($expiresAt === null) {
            return 0;
        }
        if ($expiresAt->getTimestamp() < 1) {
            throw new InvalidArgumentException('Provider expiry must be positive when it is not unlimited.');
        }

        return $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    private function absoluteDataLimit(
        string $remoteId,
        int $bytes,
        DataAllowanceMode $mode,
        ?RemoteServiceSnapshot $current,
    ): int
    {
        $this->remoteId($remoteId);
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

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{username: string, status: string, data_limit_bytes: ?int, expires_at_unix: ?int, data_limit_reset_strategy: string, group_ids: list<int>}
     */
    private function providerCreateCanonicalState(array $payload): array
    {
        $username = $this->username($this->requiredString($payload, 'username'));
        $status = $this->requiredString($payload, 'status');
        if (! in_array($status, ['active', 'disabled', 'expired', 'limited', 'on_hold'], true)) {
            throw new InvalidArgumentException('PasarGuard status does not match the pinned source contract.');
        }
        $resetStrategy = $this->requiredString($payload, 'data_limit_reset_strategy');
        if (! in_array($resetStrategy, ['no_reset', 'day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('PasarGuard reset strategy does not match the pinned source contract.');
        }
        $groupIds = $payload['group_ids'] ?? null;
        if (! is_array($groupIds) || ! array_is_list($groupIds) || $groupIds === []) {
            throw new InvalidArgumentException('PasarGuard groups do not match the pinned source contract.');
        }
        $normalizedGroupIds = [];
        foreach ($groupIds as $groupId) {
            if (! is_int($groupId) || $groupId < 1) {
                throw new InvalidArgumentException('PasarGuard group identifier does not match the pinned source contract.');
            }
            if (! in_array($groupId, $normalizedGroupIds, true)) {
                $normalizedGroupIds[] = $groupId;
            }
        }
        sort($normalizedGroupIds, SORT_NUMERIC);

        return [
            'username' => $username,
            'status' => $status,
            'data_limit_bytes' => $this->nullableLimit($payload['data_limit'] ?? null),
            'expires_at_unix' => $this->nullableExpire($payload['expire'] ?? null),
            'data_limit_reset_strategy' => $resetStrategy,
            'group_ids' => $normalizedGroupIds,
        ];
    }

    private function nullableLimit(mixed $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('PasarGuard data limit does not match the pinned source contract.');
        }

        return $value;
    }

    private function nullableExpire(mixed $value): ?int
    {
        if ($value === null || $value === 0) {
            return null;
        }
        if (is_int($value)) {
            if ($value < 1) {
                throw new InvalidArgumentException('PasarGuard expiry does not match the pinned source contract.');
            }

            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('PasarGuard expiry does not match the pinned source contract.');
        }
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (Throwable) {
            throw new InvalidArgumentException('PasarGuard expiry does not match the pinned source contract.');
        }
    }

    /** @param  array<array-key, mixed>  $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value)
            || $value === ''
            || $value !== trim($value)
            || mb_strlen($value) > 8192
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('PasarGuard response does not match the pinned source contract.');
        }

        return $value;
    }

    /** @param  array<string, mixed>  $state */
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
            throw new InvalidArgumentException('PasarGuard subscription URL is invalid.');
        }

        return $value;
    }

    private function assertOperation(string $operation): void
    {
        if (! in_array($operation, self::OPERATIONS, true)) {
            throw new InvalidArgumentException('PasarGuard mutation operation is invalid.');
        }
    }

    /** @param  array<array-key, mixed>|null  $json */
    private function validSuccessPayload(string $operation, ?array $json): bool
    {
        if ($operation === 'delete') {
            return $json !== null && $json === [];
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
