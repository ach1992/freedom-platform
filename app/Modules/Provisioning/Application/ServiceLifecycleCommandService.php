<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ServiceMutationType;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * @phpstan-type CommandServiceRow object{id:int|string,public_id:string,user_id:int|string,order_item_id:int|string,service_target_id:int|string|null,lifecycle_state:string,lifecycle_version:int|string,remote_identity_generation:int|string,mutation_generation:int|string}
 * @phpstan-type CommandOperationRow object{target_remote_identity_generation:int|string,target_lifecycle_version:int|string,request_key_hash:?string}
 */
final readonly class ServiceLifecycleCommandService
{
    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $administratorAuthorizer,
        private ServiceMutationQueueService $mutations,
        private ServiceLifecycleCommandAudit $audit,
        private ServiceCustomerOperationPolicyGuard $customerPolicies,
    ) {}

    public function resetUsage(string $servicePublicId, ServiceLifecycleCommandContext $context): ServiceLifecycleCommandReceipt
    {
        return $this->execute($servicePublicId, ServiceMutationType::ResetUsage, $context);
    }

    public function suspend(string $servicePublicId, ServiceLifecycleCommandContext $context): ServiceLifecycleCommandReceipt
    {
        return $this->execute($servicePublicId, ServiceMutationType::Suspend, $context);
    }

    public function activate(string $servicePublicId, ServiceLifecycleCommandContext $context): ServiceLifecycleCommandReceipt
    {
        return $this->execute($servicePublicId, ServiceMutationType::Activate, $context);
    }

    public function rotateSubscriptionLink(string $servicePublicId, ServiceLifecycleCommandContext $context): ServiceLifecycleCommandReceipt
    {
        return $this->execute($servicePublicId, ServiceMutationType::RotateSubscriptionLink, $context);
    }

    public function retire(string $servicePublicId, ServiceLifecycleCommandContext $context): ServiceLifecycleCommandReceipt
    {
        return $this->execute($servicePublicId, ServiceMutationType::Delete, $context);
    }

    /** @requirement SVC-004 SVC-006 ARCH-002 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 QUA-004 QUA-007 */
    public function execute(
        string $servicePublicId,
        ServiceMutationType $type,
        ServiceLifecycleCommandContext $context,
    ): ServiceLifecycleCommandReceipt {
        if (! Str::isUlid($servicePublicId)) {
            throw new DomainException('Service public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $servicePublicId,
            $type,
            $context,
        ): ServiceLifecycleCommandReceipt {
            /** @var CommandServiceRow|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('public_id', $servicePublicId)
                ->lockForUpdate()
                ->first([
                    'id', 'public_id', 'user_id', 'order_item_id', 'service_target_id',
                    'lifecycle_state', 'lifecycle_version', 'remote_identity_generation', 'mutation_generation',
                ]);
            if ($service === null) {
                throw new DomainException('Service Subscription does not exist.');
            }

            $this->authorize($connection, $service, $type, $context);

            $mutation = $this->mutations->queue(
                $servicePublicId,
                $type,
                $context->requestKey,
                $context->correlationId,
            );

            $existingAudit = $this->audit->existing(
                $connection,
                $servicePublicId,
                $mutation,
                $context,
                true,
            );
            if ($mutation->replayed) {
                if ($existingAudit === null) {
                    throw new RuntimeException('Replayed Service lifecycle mutation is missing its caller audit authority.');
                }

                return $existingAudit;
            }
            if ($existingAudit !== null) {
                throw new RuntimeException('New Service lifecycle mutation collided with existing caller audit authority.');
            }

            /** @var CommandOperationRow|null $operation */
            $operation = $connection->table('provisioning_operations')
                ->where('public_id', $mutation->operationPublicId)
                ->where('service_subscription_id', $this->positiveInt($service->id, 'Service ID'))
                ->lockForUpdate()
                ->first(['target_remote_identity_generation', 'target_lifecycle_version', 'request_key_hash']);
            if ($operation === null) {
                throw new RuntimeException('Service lifecycle mutation operation disappeared before audit binding.');
            }

            return $this->audit->record(
                $connection,
                $servicePublicId,
                $mutation,
                $context,
                [
                    'lifecycle_state' => $service->lifecycle_state,
                    'lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Service lifecycle version'),
                    'remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Remote identity generation'),
                    'mutation_generation' => $this->nonNegativeInt($service->mutation_generation, 'Service mutation generation'),
                ],
                $operation,
            );
        }, 3);
    }

    /** @param CommandServiceRow $service */
    private function authorize(
        Connection $connection,
        object $service,
        ServiceMutationType $type,
        ServiceLifecycleCommandContext $context,
    ): void {
        if ($context->actorAdministratorId !== null) {
            $this->administratorAuthorizer->authorize(
                $context->actorAdministratorId,
                $this->administratorPermission($type),
            );

            return;
        }

        $actorUserId = $context->actorUserId;
        if ($actorUserId === null) {
            throw new AuthorizationException('Service lifecycle authorization failed.');
        }

        $this->customerPolicies->assertCurrentForUpdate(
            $connection,
            $service,
            $actorUserId,
            $this->customerOperationCode($type),
            $type->panelCapabilities(),
        );
    }

    private function customerOperationCode(ServiceMutationType $type): string
    {
        return match ($type) {
            ServiceMutationType::ResetUsage => 'reset_usage',
            ServiceMutationType::Suspend => 'suspend',
            ServiceMutationType::Activate => 'activate',
            ServiceMutationType::RotateSubscriptionLink => 'rotate_subscription_link',
            ServiceMutationType::Delete => 'delete',
            ServiceMutationType::Renew,
            ServiceMutationType::AddData,
            ServiceMutationType::AddDays,
            ServiceMutationType::AddDataDays => throw new DomainException('Paid Service entitlement mutations are not lifecycle customer commands.'),
        };
    }

    private function administratorPermission(ServiceMutationType $type): string
    {
        return match ($type) {
            ServiceMutationType::ResetUsage, ServiceMutationType::Suspend, ServiceMutationType::Activate => 'services.operate',
            ServiceMutationType::RotateSubscriptionLink => 'services.rotate_link',
            ServiceMutationType::Delete => 'services.retire',
            ServiceMutationType::Renew,
            ServiceMutationType::AddData,
            ServiceMutationType::AddDays,
            ServiceMutationType::AddDataDays => throw new DomainException('Paid Service entitlement mutations are not lifecycle administrator commands.'),
        };
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
