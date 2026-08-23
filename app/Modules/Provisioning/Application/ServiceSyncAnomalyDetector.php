<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ServiceSyncAnomalySeverity;
use App\Modules\Provisioning\Domain\ServiceSyncAnomalyType;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;

/**
 * @phpstan-type SyncLocalFacts array{lifecycle_state:string,lifecycle_version:int,remote_identity_generation:int,mutation_generation:int}
 * @phpstan-type SyncRemoteFacts array{disposition:string,status:?string,data_limit_bytes:?int,expires_at:?DateTimeImmutable}
 * @phpstan-type SyncPreviousFacts array{local_lifecycle_version:int|string,local_remote_identity_generation:int|string,local_mutation_generation:int|string,remote_status:string|null,remote_data_limit_bytes:int|string|null,remote_expires_at:string|null}
 * @phpstan-type SyncFinding array{type:ServiceSyncAnomalyType,severity:ServiceSyncAnomalySeverity}
 *
 * @requirement SVC-010 SVC-013 QUA-001 QUA-004
 */
final readonly class ServiceSyncAnomalyDetector
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  SyncLocalFacts  $local
     * @param  SyncRemoteFacts  $remote
     * @param  SyncPreviousFacts|null  $previous
     * @return list<SyncFinding>
     */
    public function detect(array $local, array $remote, ?array $previous): array
    {
        if ($remote['disposition'] === 'unavailable') {
            return [];
        }

        /** @var array<string, ServiceSyncAnomalyType> $types */
        $types = [];
        $add = static function (ServiceSyncAnomalyType $type) use (&$types): void {
            $types[$type->value] = $type;
        };

        if ($remote['disposition'] === 'missing') {
            $add(ServiceSyncAnomalyType::MissingRemote);
        } elseif ($remote['disposition'] === 'identity_mismatch') {
            $add(ServiceSyncAnomalyType::RemoteIdentityMismatch);
        } elseif ($remote['disposition'] === 'present') {
            $status = $remote['status'];
            if ($status === null) {
                throw new DomainException('Present Service sync evidence requires remote status.');
            }

            if (($local['lifecycle_state'] === 'active' && $status !== 'active')
                || ($local['lifecycle_state'] === 'suspended' && $status !== 'suspended')) {
                $add(ServiceSyncAnomalyType::LifecycleMismatch);
            }

            $currentExpiry = $remote['expires_at'];
            if ($currentExpiry !== null && $currentExpiry <= $this->clock->now() && $status === 'active') {
                $add(ServiceSyncAnomalyType::ExpiredLocalActiveRemote);
            }

            if ($previous !== null && $this->sameAuthorityCycle($local, $previous)) {
                $previousExpiry = $this->storedDateTime($previous['remote_expires_at']);
                if ($previousExpiry !== null && $previousExpiry <= $this->clock->now() && $status === 'active') {
                    $add(ServiceSyncAnomalyType::ExpiredLocalActiveRemote);
                }

                $previousLimit = $previous['remote_data_limit_bytes'] === null ? null : (int) $previous['remote_data_limit_bytes'];
                if (! $this->sameDateTime($previousExpiry, $currentExpiry)
                    || $previousLimit !== $remote['data_limit_bytes']) {
                    $add(ServiceSyncAnomalyType::UnexpectedEntitlement);
                }
            }
        } else {
            throw new DomainException('Stored Service sync remote disposition is invalid.');
        }

        return array_map(fn (ServiceSyncAnomalyType $type): array => [
            'type' => $type,
            'severity' => $this->severity($type),
        ], array_values($types));
    }

    /**
     * @param  SyncLocalFacts  $local
     * @param  SyncPreviousFacts  $previous
     */
    private function sameAuthorityCycle(array $local, array $previous): bool
    {
        return (int) $previous['local_lifecycle_version'] === $local['lifecycle_version']
            && (int) $previous['local_remote_identity_generation'] === $local['remote_identity_generation']
            && (int) $previous['local_mutation_generation'] === $local['mutation_generation'];
    }

    private function severity(ServiceSyncAnomalyType $type): ServiceSyncAnomalySeverity
    {
        $value = config('service_sync.severity.'.$type->value);
        if (! is_string($value)) {
            throw new DomainException('Service sync anomaly severity configuration is invalid.');
        }

        return ServiceSyncAnomalySeverity::tryFrom($value)
            ?? throw new DomainException('Service sync anomaly severity configuration is invalid.');
    }

    private function storedDateTime(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value);
    }

    private function sameDateTime(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->getTimestamp() === $right->getTimestamp();
    }
}
