<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type TransferRow object{id:int|string,public_id:string,request_key_hash:string,service_subscription_id:int|string,from_user_id:int|string,to_user_id:int|string,actor_administrator_id:int|string,target_remote_identity_generation:int|string,target_lifecycle_version:int|string,state:string,audit_log_id:int|string|null,correlation_id:string,completed_at:?string}
 * @phpstan-type TransferReplayServiceRow object{id:int|string}
 */
final readonly class ServiceOwnershipTransferService
{
    private const PERMISSION = 'services.transfer_ownership';

    private const EVIDENCE_AUTHORITY = 'service_operational_evidence_v1';

    private const SERVICE_AUTHORITY = 'service_ownership_transfer_v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private ServiceOperationalAuthorityGuard $authority,
        private ServiceOperationalDatabaseCapability $databaseCapability,
        private ServiceOperationalAudit $audit,
    ) {}

    /** @requirement SVC-009 SVC-010 ARCH-003 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function transfer(string $servicePublicId, int $toUserId, ServiceOperationalContext $context): ServiceOwnershipTransferReceipt
    {
        $this->authority->assertFinalized();
        $this->authorizer->authorize($context->actorAdministratorId, self::PERMISSION);
        if (! Str::isUlid($servicePublicId) || $toUserId < 1) {
            throw new DomainException('Service ownership transfer identity is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use ($servicePublicId, $toUserId, $context): ServiceOwnershipTransferReceipt {
            /** @var object{id:int|string,public_id:string,user_id:int|string,service_target_id:int|string|null,remote_service_id:?string,provisioned_at:?string,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string,remote_deleted_at:?string}|null $service */
            $service = $connection->table('service_subscriptions')->where('public_id', $servicePublicId)->lockForUpdate()->first([
                'id', 'public_id', 'user_id', 'service_target_id', 'remote_service_id', 'provisioned_at',
                'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation', 'remote_deleted_at',
            ]);
            if ($service === null) {
                throw new DomainException('Service Subscription does not exist.');
            }
            $currentUserId = $this->positiveInt($service->user_id, 'Service owner user ID');

            /** @var TransferRow|null $existing */
            $existing = $connection->table('service_ownership_transfers')->where('request_key_hash', $context->requestHash())->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertReplay($existing, $service, $toUserId, $context);
                if ($existing->state !== 'completed' || $currentUserId !== (int) $existing->to_user_id) {
                    throw new RuntimeException('Service ownership transfer is incomplete and requires reconciliation.');
                }

                return $this->receipt($existing, $servicePublicId, true);
            }

            $fromUserId = $currentUserId;
            if ($fromUserId === $toUserId) {
                throw new DomainException('Service ownership transfer requires a different target user.');
            }
            if ($service->remote_deleted_at !== null
                || $service->lifecycle_state === 'retired'
                || $service->service_target_id === null
                || ! is_string($service->remote_service_id)
                || $service->remote_service_id === ''
                || $service->provisioned_at === null) {
                throw new DomainException('Only a live fully bound Service can transfer ownership.');
            }
            if ($connection->table('provisioning_operations')
                ->where('service_subscription_id', (int) $service->id)
                ->whereNotIn('state', ['succeeded', 'failed_final', 'compensated'])
                ->exists()) {
                throw new DomainException('Service ownership transfer is blocked by an unresolved mutation.');
            }
            if ($connection->table('service_delivery_attempts as attempt')
                ->leftJoin('service_delivery_effects as effect', 'effect.service_delivery_attempt_id', '=', 'attempt.id')
                ->where('attempt.service_subscription_id', (int) $service->id)
                ->where(function ($query): void {
                    $query->whereNull('effect.id')->orWhereNotIn('effect.state', ['succeeded', 'failed_final']);
                })
                ->exists()) {
                throw new DomainException('Service ownership transfer is blocked by unresolved delivery authority.');
            }
            /** @var object{account_status:string,account_type:string}|null $targetUser */
            $targetUser = $connection->table('users')->where('id', $toUserId)->lockForUpdate()->first(['account_status', 'account_type']);
            if ($targetUser === null || $targetUser->account_status !== 'active' || ! in_array($targetUser->account_type, ['customer', 'agent'], true)) {
                throw new DomainException('Service ownership transfer target user is not active.');
            }

            $transferPublicId = (string) Str::ulid();
            $this->setEvidenceAuthority($connection);
            try {
                $transferId = (int) $connection->table('service_ownership_transfers')->insertGetId([
                    'public_id' => $transferPublicId,
                    'request_key_hash' => $context->requestHash(),
                    'service_subscription_id' => (int) $service->id,
                    'from_user_id' => $fromUserId,
                    'to_user_id' => $toUserId,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'target_remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'),
                    'target_lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version'),
                    'state' => 'pending',
                    'audit_log_id' => null,
                    'correlation_id' => $context->correlationId,
                    'created_at' => $this->timestamp(),
                    'completed_at' => null,
                ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }

            $auditId = $this->audit->record(
                $connection,
                'service.operational.ownership.transferred',
                'service_subscription',
                $servicePublicId,
                $context,
                [
                    'user_id' => $fromUserId,
                    'remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'),
                    'lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version'),
                ],
                [
                    'user_id' => $toUserId,
                    'remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'),
                    'lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version') + 1,
                ],
            );

            $this->setEvidenceAuthority($connection);
            try {
                $moved = $connection->table('service_ownership_transfers')->where('id', $transferId)->where('state', 'pending')->update([
                    'state' => 'applying',
                    'audit_log_id' => $auditId,
                ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($moved !== 1) {
                throw new RuntimeException('Service ownership transfer lost its pending state.');
            }

            $this->setServiceAuthority($connection, $transferId, $context);
            try {
                $updated = $connection->table('service_subscriptions')
                    ->where('id', (int) $service->id)
                    ->where('user_id', $fromUserId)
                    ->where('remote_identity_generation', $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'))
                    ->where('lifecycle_version', $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version'))
                    ->update([
                        'user_id' => $toUserId,
                        'lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Lifecycle version') + 1,
                        'updated_at' => $this->timestamp(),
                    ]);
            } finally {
                $this->clearServiceAuthority($connection);
            }
            if ($updated !== 1) {
                throw new RuntimeException('Service ownership transfer lost its generation/lifecycle authority.');
            }

            $this->setEvidenceAuthority($connection);
            try {
                $finished = $connection->table('service_ownership_transfers')->where('id', $transferId)->where('state', 'applying')->update([
                    'state' => 'completed',
                    'completed_at' => $this->timestamp(),
                ]);
            } finally {
                $this->clearEvidenceAuthority($connection);
            }
            if ($finished !== 1) {
                throw new RuntimeException('Service ownership transfer did not finalize.');
            }

            /** @var TransferRow|null $completed */
            $completed = $connection->table('service_ownership_transfers')->where('id', $transferId)->first();
            if ($completed === null) {
                throw new RuntimeException('Service ownership transfer evidence disappeared.');
            }

            return $this->receipt($completed, $servicePublicId, false);
        }, 3);
    }

    /**
     * @param  TransferRow  $row
     * @param  TransferReplayServiceRow  $service
     */
    private function assertReplay(object $row, object $service, int $toUserId, ServiceOperationalContext $context): void
    {
        if ((int) $row->service_subscription_id !== (int) $service->id
            || (int) $row->to_user_id !== $toUserId
            || (int) $row->actor_administrator_id !== $context->actorAdministratorId
            || ! hash_equals($row->correlation_id, $context->correlationId)) {
            throw new DomainException('Service ownership transfer request fingerprint conflicts with existing evidence.');
        }
    }

    /** @param TransferRow $row */
    private function receipt(object $row, string $servicePublicId, bool $replayed): ServiceOwnershipTransferReceipt
    {
        return new ServiceOwnershipTransferReceipt(
            (int) $row->id,
            $row->public_id,
            $servicePublicId,
            (int) $row->from_user_id,
            (int) $row->to_user_id,
            (int) $row->target_remote_identity_generation,
            (int) $row->target_lifecycle_version + 1,
            $replayed,
        );
    }

    private function setEvidenceAuthority(Connection $connection): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_evidence_authority = ?', [self::EVIDENCE_AUTHORITY]);
        } catch (\Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearEvidenceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement('SET @app_service_operational_evidence_authority = NULL');
        }
    }

    private function setServiceAuthority(Connection $connection, int $evidenceId, ServiceOperationalContext $context): void
    {
        $this->databaseCapability->apply($connection);
        try {
            $connection->statement('SET @app_service_operational_authority = ?', [self::SERVICE_AUTHORITY]);
            $connection->statement('SET @app_service_operational_evidence_id = ?', [$evidenceId]);
            $connection->statement('SET @app_service_operational_request_hash = ?', [$context->requestHash()]);
            $connection->statement('SET @app_service_operational_correlation_id = ?', [$context->correlationId]);
        } catch (\Throwable $exception) {
            $this->databaseCapability->clear($connection);
            throw $exception;
        }
    }

    private function clearServiceAuthority(Connection $connection): void
    {
        try {
            $this->databaseCapability->clear($connection);
        } finally {
            $connection->statement(
                'SET @app_service_operational_authority = NULL, @app_service_operational_evidence_id = NULL, @app_service_operational_request_hash = NULL, @app_service_operational_correlation_id = NULL',
            );
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function positiveInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
