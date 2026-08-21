<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only application-level caller for a human-initiated restricted Service
 * delivery resend. It authorizes and binds immutable caller audit evidence in
 * the same transaction as the Delivery Attempt and its Outbox handoff.
 *
 * @phpstan-type ServiceRow object{id:int|string,public_id:string,user_id:int|string}
 */
final readonly class ServiceDeliveryResendService
{
    private const ADMINISTRATOR_PERMISSION = 'services.operate';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorPermissionAuthorizer $administratorAuthorizer,
        private ServiceDeliveryAttemptQueueService $deliveries,
        private ServiceDeliveryResendAudit $audit,
    ) {}

    /** @requirement SVC-002 SVC-014 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-003 SEC-008 QUA-004 QUA-007 QUA-010 */
    public function resend(
        string $servicePublicId,
        ServiceDeliveryResendContext $context,
    ): ServiceDeliveryResendReceipt {
        if (! Str::isUlid($servicePublicId)) {
            throw new DomainException('Service public ID is invalid.');
        }

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $servicePublicId,
            $context,
        ): ServiceDeliveryResendReceipt {
            /** @var ServiceRow|null $service */
            $service = $connection->table('service_subscriptions')
                ->where('public_id', $servicePublicId)
                ->lockForUpdate()
                ->first(['id', 'public_id', 'user_id', 'lifecycle_state', 'lifecycle_version', 'remote_identity_generation']);
            if ($service === null) {
                throw new DomainException('Service Subscription does not exist.');
            }

            $this->authorize($connection, $service, $context);

            $attempt = $this->deliveries->queue(
                $servicePublicId,
                ServiceDeliveryPurpose::Resend,
                $context->requestKey,
                $context->correlationId,
            );
            $existingAudit = $this->audit->existing(
                $connection,
                $servicePublicId,
                $attempt,
                $context,
                true,
            );
            if ($attempt->replayed) {
                if ($existingAudit === null) {
                    throw new RuntimeException('Replayed Service delivery resend is missing caller audit authority.');
                }

                return $existingAudit;
            }
            if ($existingAudit !== null) {
                throw new RuntimeException('New Service delivery resend collided with existing caller audit authority.');
            }

            return $this->audit->record(
                $connection,
                $servicePublicId,
                $attempt,
                $context,
                [
                    'lifecycle_state' => $service->lifecycle_state,
                    'lifecycle_version' => $this->nonNegativeInt($service->lifecycle_version, 'Service lifecycle version'),
                    'remote_identity_generation' => $this->positiveInt($service->remote_identity_generation, 'Service remote identity generation'),
                ],
            );
        }, 3);
    }

    /** @param ServiceRow $service */
    private function authorize(Connection $connection, object $service, ServiceDeliveryResendContext $context): void
    {
        if ($context->actorAdministratorId !== null) {
            $this->administratorAuthorizer->authorize(
                $context->actorAdministratorId,
                self::ADMINISTRATOR_PERMISSION,
            );

            return;
        }

        $actorUserId = $context->actorUserId;
        if ($actorUserId === null || $this->positiveInt($service->user_id, 'Service owner user ID') !== $actorUserId) {
            throw new AuthorizationException('Service delivery resend authorization failed.');
        }

        /** @var object{account_status:string,account_type:string}|null $user */
        $user = $connection->table('users')->where('id', $actorUserId)->lockForUpdate()->first(['account_status', 'account_type']);
        if ($user === null
            || $user->account_status !== 'active'
            || ! in_array($user->account_type, ['customer', 'agent'], true)) {
            throw new AuthorizationException('Service delivery resend authorization failed.');
        }
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
