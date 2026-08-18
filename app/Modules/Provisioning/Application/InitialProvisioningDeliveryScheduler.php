<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Domain\ProvisioningState;
use App\Modules\Provisioning\Domain\ServiceDeliveryPurpose;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final readonly class InitialProvisioningDeliveryScheduler
{
    public function __construct(
        private DatabaseManager $database,
        private ServiceDeliveryAttemptQueueService $delivery,
    ) {}

    /** @requirement SVC-002 PRV-002 ARCH-004 DAT-003 SEC-002 QUA-004 */
    public function schedule(string $operationPublicId, string $correlationId): ServiceDeliveryAttemptReceipt
    {
        if (! Str::isUlid($operationPublicId)) {
            throw new DomainException('Initial provisioning operation public ID is invalid for delivery scheduling.');
        }

        /** @var object{service_public_id:string}|null $row */
        $row = $this->database->connection()->table('provisioning_operations as operation')
            ->join('service_subscriptions as service', 'service.id', '=', 'operation.service_subscription_id')
            ->where('operation.public_id', $operationPublicId)
            ->where('operation.operation_type', 'initial_provision')
            ->where('operation.state', ProvisioningState::Succeeded->value)
            ->first(['service.public_id as service_public_id']);
        if ($row === null || ! Str::isUlid($row->service_public_id)) {
            throw new DomainException('Successful initial provisioning Service identity is unavailable for delivery scheduling.');
        }

        return $this->delivery->queue(
            $row->service_public_id,
            ServiceDeliveryPurpose::Initial,
            'initial-delivery:'.$operationPublicId,
            $correlationId,
        );
    }
}
